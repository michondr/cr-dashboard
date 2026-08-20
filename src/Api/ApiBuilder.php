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

use function array_key_exists;
use function count;
use function gmdate;
use function rtrim;
use function sprintf;
use function usort;

use const DATE_ATOM;

/**
 * Builds the `/api/data` payload from the cache. Every per-MR and per-person
 * value is computed here; the frontend only renders.
 */
final class ApiBuilder
{
    /** User ranking refresh cadence (matches the daily `app:rank-users` cron). */
    private const RANK_INTERVAL_SECONDS = 86400;

    public function __construct(
        private readonly Database $database,
        private readonly MetricCalculator $calculator,
        private readonly AppConfig $config,
        private readonly Synchronizer $synchronizer,
    ) {
    }

    /**
     * @param null|int $user Optional "my view" filter for the MR list.
     *
     * @return array<string, mixed>
     */
    public function build(string $granularity, int $now, null|int $user = null): array
    {
        $dataset = $this->loadDataset();

        $metrics = [];
        foreach ($this->calculator->all($dataset, $granularity, $now) as $name => $result) {
            $metrics[$name] = $result->toApiArray();
        }

        return [
            'meta' => $this->buildMeta($now),
            'users' => $this->buildUsers($dataset),
            'mrs' => $this->buildMrs($dataset, $now, $user),
            'metrics' => $metrics,
        ];
    }

    /**
     * Payload for a single open MR (same shape as one element of `mrs` in the
     * full payload), or null when the MR is not cached or not open. Serves
     * `/api/mr/{id}` so an SSE-driven row patch does not have to fetch and
     * rebuild the whole dataset payload.
     *
     * @return array<string, mixed>|null
     */
    public function buildMr(int $id, int $now): null|array
    {
        $dataset = $this->loadDataset();
        foreach ($dataset->mrs as $mr) {
            if ((int) $mr['id'] === $id && (string) $mr['state'] === 'opened') {
                return $this->buildMrRow($mr, $this->projectInfos(), $dataset, $now);
            }
        }

        return null;
    }

    /**
     * @return array<string, int|string|null>
     */
    private function buildMeta(int $now): array
    {
        $lastSync = $this->synchronizer->lastSync();
        $cacheAge = $lastSync === null ? null : $now - $lastSync;
        $lastRank = $this->synchronizer->lastRank();

        return [
            'required_approvals' => $this->config->requiredApprovals,
            'stale_days' => AppConfig::STALE_DAYS,
            'window_days' => AppConfig::WINDOW_DAYS,
            'coverage_window_days' => AppConfig::COVERAGE_WINDOW_DAYS,
            'jira_url' => $this->config->jiraUrl,
            'generated_at' => $this->iso($now),
            'cache_age_seconds' => $cacheAge,
            'last_sync_at' => $lastSync === null ? null : $this->iso($lastSync),
            'last_rank_at' => $lastRank === null ? null : $this->iso($lastRank),
            'next_rank_at' => $lastRank === null ? null : $this->iso($lastRank + self::RANK_INTERVAL_SECONDS),
        ];
    }

    /**
     * @return list<array{id: int, name: string, username: string, avatar_url: string|null, mr_count: int}>
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
                'mr_count' => (int) ($user['mr_count'] ?? 0),
            ];
        }

        return $users;
    }

    /**
     * The rows the MR list renders: open MRs only. Merged and closed MRs are
     * kept in the cache for the merge/review metrics but are not shown in the
     * list. Stale open MRs are flagged `stale`.
     *
     * @return list<array<string, mixed>>
     */
    private function buildMrs(Dataset $dataset, int $now, null|int $user): array
    {
        $projects = $this->projectInfos();
        $approvedByUser = $user === null ? [] : $this->approverMrIdsByUser($dataset, $user);

        $rows = [];
        foreach ($dataset->mrs as $mr) {
            if ((string) $mr['state'] !== 'opened') {
                continue;
            }
            if ($user !== null) {
                $mrId = (int) $mr['id'];
                $isAuthor = (int) $mr['author_id'] === $user;
                $hasApproved = array_key_exists($mrId, $approvedByUser);
                // Keep MRs I authored, and MRs I have not reviewed yet (not mine
                // and not yet approved by me). Drop MRs I already approved.
                if (!$isAuthor && $hasApproved) {
                    continue;
                }
            }
            $rows[] = $this->buildMrRow($mr, $projects, $dataset, $now);
        }

        return $rows;
    }

    /**
     * @return array<int, true> MR ids the given user has approved.
     */
    private function approverMrIdsByUser(Dataset $dataset, int $user): array
    {
        $ids = [];
        foreach ($dataset->approvals as $approval) {
            if ((int) $approval['user_id'] === $user) {
                $ids[(int) $approval['mr_id']] = true;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, int|float|string|null> $mr
     * @param array<int, array{id: int, path_with_namespace: string, name: string, avatar_url: string|null}> $projects
     *
     * @return array{
     *   id: int,
     *   iid: int,
     *   project: array{id: int, path_with_namespace: string, name: string, avatar_url: string|null},
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
     *   pipeline: array{status: string, indicator: string, tint: string|null},
     *   approvers: list<array{
     *     id: int, name: string, username: string, avatar_url: string|null, approved_at: string|null
     *   }>,
     *   needs_rebase: bool,
     *   unresolved_discussions: int,
     *   approved: bool,
     *   ready: bool
     * }
     */
    private function buildMrRow(array $mr, array $projects, Dataset $dataset, int $now): array
    {
        $id = (int) $mr['id'];
        $state = (string) $mr['state'];
        $createdAt = (int) $mr['created_at'];
        $mergedAt = $mr['merged_at'] === null ? null : (int) $mr['merged_at'];
        $closedAt = $mr['closed_at'] === null ? null : (int) $mr['closed_at'];
        $project = $this->projectFor($projects, (int) $mr['project_id']);
        $projectPath = $project['path_with_namespace'];
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

        $pipeline = $this->pipelineFor($dataset, $id);
        $approvers = $this->approversFor($dataset, $id);
        $unresolvedDiscussions = $this->unresolvedDiscussionCount($dataset, $id);
        $approved = count($approvers) >= $this->config->requiredApprovals;
        $ready = $approved && $pipeline['indicator'] === 'check' && $unresolvedDiscussions === 0;
        $mergeStatus = (string) ($mr['merge_status'] ?? '');
        $needsRebase = (int) ($mr['has_conflicts'] ?? 0) === 1
            || $mergeStatus === 'cannot_be_merged'
            || $mergeStatus === 'cannot_be_merged_recheck';

        return [
            'id' => $id,
            'iid' => $iid,
            'project' => $project,
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
            'pipeline' => $pipeline,
            'approvers' => $approvers,
            'needs_rebase' => $needsRebase,
            'unresolved_discussions' => $unresolvedDiscussions,
            'approved' => $approved,
            'ready' => $ready,
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
     * Discussion threads of an MR that have at least one unresolved resolvable
     * note. Feeds the "unresolved discussion" status badge.
     */
    private function unresolvedDiscussionCount(Dataset $dataset, int $mrId): int
    {
        $count = 0;
        foreach ($dataset->discussions as $discussion) {
            if ((int) $discussion['mr_id'] !== $mrId) {
                continue;
            }
            if ((int) ($discussion['resolved'] ?? 1) === 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Approvers of an MR, earliest approval first, with their avatar. Serves the
     * first-approver avatar, the approvers column, and the "ready" state.
     *
     * @return list<array{id: int, name: string, username: string, avatar_url: string|null, approved_at: string|null}>
     */
    private function approversFor(Dataset $dataset, int $mrId): array
    {
        $approvals = [];
        foreach ($dataset->approvals as $approval) {
            if ((int) $approval['mr_id'] !== $mrId) {
                continue;
            }
            $approvals[] = [
                'user_id' => (int) $approval['user_id'],
                'created_at' => (int) $approval['created_at'],
            ];
        }

        usort($approvals, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);

        $approvers = [];
        foreach ($approvals as $approval) {
            $user = $this->findUser($dataset, $approval['user_id']);
            $approvers[] = [
                'id' => $user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'avatar_url' => $user['avatar_url'],
                'approved_at' => $this->iso($approval['created_at']),
            ];
        }

        return $approvers;
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
     * @return array<int, array{id: int, path_with_namespace: string, name: string, avatar_url: string|null}>
     */
    private function projectInfos(): array
    {
        $infos = [];
        foreach ($this->database->query('SELECT id, path_with_namespace, name, avatar_url FROM projects') as $row) {
            $infos[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'path_with_namespace' => (string) $row['path_with_namespace'],
                'name' => (string) $row['name'],
                'avatar_url' => $row['avatar_url'] === null ? null : (string) $row['avatar_url'],
            ];
        }

        return $infos;
    }

    /**
     * @param array<int, array{id: int, path_with_namespace: string, name: string, avatar_url: string|null}> $projects
     *
     * @return array{id: int, path_with_namespace: string, name: string, avatar_url: string|null}
     */
    private function projectFor(array $projects, int $id): array
    {
        return $projects[$id] ?? [
            'id' => $id,
            'path_with_namespace' => '',
            'name' => '',
            'avatar_url' => null,
        ];
    }

    private function loadDataset(): Dataset
    {
        return new Dataset(
            $this->database->query('SELECT * FROM users ORDER BY mr_count DESC, name ASC'),
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
