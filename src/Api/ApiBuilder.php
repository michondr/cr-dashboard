<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\AppConfig;
use App\Metrics\Dataset;
use App\Metrics\JiraTicket;
use App\Metrics\MetricCalculator;
use App\Metrics\PipelineIndicator;
use App\Storage\Database;
use App\Sync\Synchronizer;

use function array_slice;
use function count;
use function gmdate;
use function rtrim;
use function sprintf;
use function strcmp;
use function usort;

use const DATE_ATOM;

/**
 * Builds the `/api/data` payload from the cache. Every per-MR and per-person
 * value is computed here; the frontend only renders.
 */
final class ApiBuilder
{
    public function __construct(
        private readonly Database $database,
        private readonly MetricCalculator $calculator,
        private readonly AppConfig $config,
        private readonly Synchronizer $synchronizer,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(string $granularity, int $now): array
    {
        $dataset = $this->loadDataset();

        $metrics = [];
        foreach ($this->calculator->all($dataset, $granularity, $now) as $name => $result) {
            $metrics[$name] = $result->toApiArray();
        }

        return [
            'meta' => $this->buildMeta($now),
            'users' => $this->buildUsers($dataset),
            'mrs' => $this->buildMrs($dataset, $now),
            'metrics' => $metrics,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    private function buildMeta(int $now): array
    {
        $lastSync = $this->synchronizer->lastSync();
        $cacheAge = $lastSync === null ? null : $now - $lastSync;

        return [
            'required_approvals' => $this->config->requiredApprovals,
            'stale_days' => AppConfig::STALE_DAYS,
            'window_days' => AppConfig::WINDOW_DAYS,
            'coverage_window_days' => AppConfig::COVERAGE_WINDOW_DAYS,
            'jira_url' => $this->config->jiraUrl,
            'generated_at' => $this->iso($now),
            'cache_age_seconds' => $cacheAge,
            'last_sync_at' => $lastSync === null ? null : $this->iso($lastSync),
            'next_sync_at' => $lastSync === null ? null : $this->iso($lastSync + AppConfig::SYNC_INTERVAL_SECONDS),
        ];
    }

    /**
     * @return list<array{id: int, name: string, username: string, avatar_url: string|null}>
     */
    private function buildUsers(Dataset $dataset): array
    {
        $users = [];
        foreach ($dataset->users as $user) {
            $users[] = [
                'id' => (int) $user['id'],
                'name' => (string) $user['name'],
                'username' => (string) $user['username'],
                'avatar_url' => $user['avatar_url'] === null ? null : (string) $user['avatar_url'],
            ];
        }

        return $users;
    }

    /**
     * The rows the MR list renders: the last 5 merged MRs, the open and closed
     * MRs from the last 60 days, and stale open MRs (flagged `stale`).
     *
     * @return list<array<string, mixed>>
     */
    private function buildMrs(Dataset $dataset, int $now): array
    {
        $projectPaths = $this->projectPaths();
        $rows = [];
        foreach ($dataset->mrs as $mr) {
            $rows[] = $this->buildMrRow($mr, $projectPaths, $dataset, $now);
        }

        $merged = [];
        $body = [];
        foreach ($rows as $row) {
            if ($row['state'] === 'merged') {
                $merged[] = $row;
            } else {
                $body[] = $row;
            }
        }

        usort(
            $merged,
            static fn (array $a, array $b): int => strcmp((string) $b['merged_at'], (string) $a['merged_at']),
        );
        $merged = array_slice($merged, 0, 5);

        $windowStart = $this->iso($now - (AppConfig::WINDOW_DAYS * 86400));
        $filtered = [];
        foreach ($body as $row) {
            if ($row['state'] === 'closed' && $row['created_at'] < $windowStart) {
                continue;
            }
            $filtered[] = $row;
        }

        return [...$merged, ...$filtered];
    }

    /**
     * @param array<string, int|float|string|null> $mr
     * @param array<int, string> $projectPaths
     *
     * @return array{
     *   id: int,
     *   iid: int,
     *   project_path: string,
     *   title: string,
     *   jira_ticket: string|null,
     *   description: string,
     *   author: array{id: int, name: string, username: string, avatar_url: string|null},
     *   state: string,
     *   draft: bool,
     *   stale: bool,
     *   created_at: string,
     *   merged_at: string|null,
     *   closed_at: string|null,
     *   web_url: string,
     *   age_seconds: int,
     *   time_to_first_approval_seconds: int|null,
     *   commit_count: int,
     *   commit_diff_urls: list<string>,
     *   pipeline: array{status: string, indicator: string, tint: string|null}
     * }
     */
    private function buildMrRow(array $mr, array $projectPaths, Dataset $dataset, int $now): array
    {
        $id = (int) $mr['id'];
        $state = (string) $mr['state'];
        $createdAt = (int) $mr['created_at'];
        $mergedAt = $mr['merged_at'] === null ? null : (int) $mr['merged_at'];
        $closedAt = $mr['closed_at'] === null ? null : (int) $mr['closed_at'];
        $projectPath = $projectPaths[(int) $mr['project_id']] ?? '';
        $iid = (int) $mr['iid'];

        $ageSeconds = match ($state) {
            'merged' => ($mergedAt ?? $now) - $createdAt,
            'closed' => ($closedAt ?? $now) - $createdAt,
            default => $now - $createdAt,
        };

        $commitShas = $this->currentCommitShas($dataset, $id);
        $commitDiffUrls = [];
        foreach ($commitShas as $sha) {
            $commitDiffUrls[] = sprintf(
                '%s/%s/-/merge_requests/%d/diffs?commit_id=%s',
                rtrim($this->config->gitlabUrl, '/'),
                $projectPath,
                $iid,
                $sha,
            );
        }

        return [
            'id' => $id,
            'iid' => $iid,
            'project_path' => $projectPath,
            'title' => (string) $mr['title'],
            'jira_ticket' => JiraTicket::extract((string) $mr['title']),
            'description' => $mr['description'] === null ? '' : (string) $mr['description'],
            'author' => $this->findUser($dataset, (int) $mr['author_id']),
            'state' => $state,
            'draft' => (int) $mr['draft'] === 1,
            'stale' => $state === 'opened' && ($now - $createdAt) > (AppConfig::STALE_DAYS * 86400),
            'created_at' => $this->iso($createdAt),
            'merged_at' => $mergedAt === null ? null : $this->iso($mergedAt),
            'closed_at' => $closedAt === null ? null : $this->iso($closedAt),
            'web_url' => $mr['web_url'] === null ? '' : (string) $mr['web_url'],
            'age_seconds' => $ageSeconds,
            'time_to_first_approval_seconds' => $this->firstApprovalSeconds($dataset, $id, $createdAt),
            'commit_count' => count($commitShas),
            'commit_diff_urls' => $commitDiffUrls,
            'pipeline' => $this->pipelineFor($dataset, $id),
        ];
    }

    /**
     * @return array{id: int, name: string, username: string, avatar_url: string|null}
     */
    private function findUser(Dataset $dataset, int $id): array
    {
        foreach ($dataset->users as $user) {
            if ((int) $user['id'] === $id) {
                return [
                    'id' => $id,
                    'name' => (string) $user['name'],
                    'username' => (string) $user['username'],
                    'avatar_url' => $user['avatar_url'] === null ? null : (string) $user['avatar_url'],
                ];
            }
        }

        return ['id' => $id, 'name' => 'Unknown', 'username' => '', 'avatar_url' => null];
    }

    private function firstApprovalSeconds(Dataset $dataset, int $mrId, int $createdAt): null|int
    {
        $first = null;
        foreach ($dataset->approvals as $approval) {
            if ((int) $approval['mr_id'] !== $mrId) {
                continue;
            }
            $at = (int) $approval['created_at'];
            if ($first === null || $at < $first) {
                $first = $at;
            }
        }

        return $first === null ? null : $first - $createdAt;
    }

    /**
     * @return array{status: string, indicator: string, tint: string|null}
     */
    private function pipelineFor(Dataset $dataset, int $mrId): array
    {
        $pipelines = [];
        foreach ($dataset->pipelines as $pipeline) {
            if ((int) $pipeline['mr_id'] !== $mrId) {
                continue;
            }
            $pipelines[] = [
                'id' => (int) $pipeline['id'],
                'status' => (string) $pipeline['status'],
            ];
        }

        $jobs = [];
        foreach ($dataset->jobs as $job) {
            if ((int) $job['mr_id'] !== $mrId) {
                continue;
            }
            $jobs[] = [
                'pipeline_id' => (int) $job['pipeline_id'],
                'status' => (string) $job['status'],
            ];
        }

        return PipelineIndicator::compute($pipelines, $jobs);
    }

    /**
     * @return list<string>
     */
    private function currentCommitShas(Dataset $dataset, int $mrId): array
    {
        $shas = [];
        foreach ($dataset->commits as $commit) {
            if ((int) $commit['mr_id'] !== $mrId) {
                continue;
            }
            if ((int) $commit['current'] !== 1) {
                continue;
            }
            $shas[] = (string) $commit['sha'];
        }

        return $shas;
    }

    /**
     * @return array<int, string>
     */
    private function projectPaths(): array
    {
        $paths = [];
        foreach ($this->database->query('SELECT id, path_with_namespace FROM projects') as $row) {
            $paths[(int) $row['id']] = (string) $row['path_with_namespace'];
        }

        return $paths;
    }

    private function loadDataset(): Dataset
    {
        return new Dataset(
            $this->database->query('SELECT * FROM users'),
            $this->database->query('SELECT * FROM merge_requests'),
            $this->database->query('SELECT * FROM approvals'),
            $this->database->query('SELECT * FROM discussions'),
            $this->database->query('SELECT * FROM commits'),
            $this->database->query('SELECT * FROM pipelines'),
            $this->database->query('SELECT * FROM jobs'),
        );
    }

    private function iso(int $epoch): string
    {
        return gmdate(DATE_ATOM, $epoch);
    }
}
