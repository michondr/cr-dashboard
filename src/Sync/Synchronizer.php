<?php

declare(strict_types=1);

namespace App\Sync;

use App\Config\AppConfig;
use App\GitLab\GitLabClientInterface;
use App\GitLab\GitLabException;
use App\Storage\Database;
use Symfony\Component\Console\Output\OutputInterface;

use function array_key_exists;
use function count;
use function gmdate;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function mb_strlen;
use function mb_substr;
use function sprintf;
use function strtotime;
use function usort;

/**
 * Fetches GitLab state into SQLite. Sub-resources are wiped and re-inserted per
 * MR on every re-fetch; commits are append-only by sha with a `current` flag so
 * MR size always reflects the latest commit set without re-fetching stats.
 */
final class Synchronizer
{
    /** Optional console output for progress reporting during a CLI sync. */
    private null|OutputInterface $output = null;

    public function __construct(
        private readonly GitLabClientInterface $client,
        private readonly Database $database,
        private readonly AppConfig $config,
    ) {
    }

    public function lastSync(): null|int
    {
        $value = $this->database->queryValue("SELECT value FROM sync_state WHERE key = 'last_sync'");

        return $value === null ? null : (int) $value;
    }

    /**
     * Full backfill: every project, every MR (all states, all pages), and every
     * sub-resource of every MR.
     */
    public function full(int $now, null|OutputInterface $output = null): void
    {
        $this->output = $output;

        if (!$this->acquireLock($now)) {
            throw new SyncLockedException();
        }

        try {
            $this->report('Full backfill: fetching projects...');
            $projectsById = $this->fetchProjects();
            $this->report(sprintf('  %d project(s).', count($projectsById)));

            $this->report('Fetching all merge requests (all states, all pages)...');
            $mrs = $this->client->groupMergeRequests($this->config->gitlabGroup, ['state' => 'all']);
            $total = count($mrs);
            $this->report(sprintf('  %d MR(s) fetched; syncing sub-resources...', $total));

            $done = 0;
            foreach ($mrs as $mr) {
                if (!$this->isAllowedMr($mr, $projectsById)) {
                    continue;
                }
                $this->syncMergeRequest($mr);
                $done++;
                $this->reportMrProgress($done, $total, $mr, $projectsById);
            }

            $this->report(sprintf('  synced %d of %d MR(s).', $done, $total));

            $pruned = $this->applyRetention($now);
            $days = $this->config->retentionDays;
            $this->report(sprintf('Retention pruned %d MR(s) older than %d days.', $pruned, $days));
            $this->setSyncState('last_sync', (string) $now);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Incremental sync: MRs updated since the last sync (or the last hour when
     * nothing was ever synced), plus a re-poll of pipelines/jobs for MRs whose
     * latest pipeline is still running or pending.
     */
    public function incremental(int $now, null|OutputInterface $output = null): void
    {
        $this->output = $output;

        if (!$this->acquireLock($now)) {
            throw new SyncLockedException();
        }

        try {
            $this->report('Incremental sync: fetching projects...');
            $projectsById = $this->fetchProjects();
            $this->report(sprintf('  %d project(s).', count($projectsById)));

            $lastSync = $this->lastSync();
            $updatedAfter = $lastSync === null ? $now - 3600 : $lastSync - 60;
            $this->report(sprintf('Fetching MRs updated since %s...', gmdate(DATE_ATOM, $updatedAfter)));
            $mrs = $this->client->groupMergeRequests($this->config->gitlabGroup, [
                'state' => 'all',
                'updated_after' => gmdate(DATE_ATOM, $updatedAfter),
            ]);
            $total = count($mrs);
            $this->report(sprintf('  %d MR(s) fetched; syncing...', $total));

            $done = 0;
            $syncedIds = [];
            foreach ($mrs as $mr) {
                if (!$this->isAllowedMr($mr, $projectsById)) {
                    continue;
                }
                $this->syncMergeRequest($mr);
                $done++;
                $syncedIds[] = $this->intValue($mr, 'id');
                $this->reportMrProgress($done, $total, $mr, $projectsById);
            }

            $runningIds = $this->runningPipelineMrIds();
            $runningCount = count($runningIds);
            $this->report(sprintf('  synced %d/%d; re-polling %d running pipeline(s)', $done, $total, $runningCount));
            $polled = 0;
            foreach ($runningIds as $mrId) {
                if (in_array($mrId, $syncedIds, true)) {
                    continue;
                }
                $row = $this->mergeRequestRow($mrId);
                if ($row !== null) {
                    $this->syncPipelinesOnly($row);
                    $polled++;
                }
            }

            $this->report(sprintf('  re-polled %d MR(s).', $polled));
            $this->setSyncState('last_sync', (string) $now);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Nightly open-MR refresh: re-fetch sub-resources for every open MR (this
     * catches approvals and discussions that did not bump `updated_at`), then
     * prune MRs that fall outside retention.
     */
    public function refreshOpen(int $now, null|OutputInterface $output = null): void
    {
        $this->output = $output;

        if (!$this->acquireLock($now)) {
            throw new SyncLockedException();
        }

        try {
            $this->report('Open-MR refresh: fetching projects...');
            $projectsById = $this->fetchProjects();
            $this->report(sprintf('  %d project(s).', count($projectsById)));

            $this->report('Fetching open merge requests...');
            $mrs = $this->client->groupMergeRequests($this->config->gitlabGroup, ['state' => 'opened']);
            $total = count($mrs);
            $this->report(sprintf('  %d open MR(s); syncing sub-resources...', $total));

            $done = 0;
            foreach ($mrs as $mr) {
                if (!$this->isAllowedMr($mr, $projectsById)) {
                    continue;
                }
                $this->syncMergeRequest($mr);
                $done++;
                $this->reportMrProgress($done, $total, $mr, $projectsById);
            }

            $this->report(sprintf('  synced %d of %d MR(s).', $done, $total));

            $pruned = $this->applyRetention($now);
            $this->report(sprintf('Retention pruned %d MR(s).', $pruned));
            $this->setSyncState('last_sync', (string) $now);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Delete merged/closed MRs whose merge/close time is older than
     * `RETENTION_DAYS`, together with all their sub-resources.
     */
    public function applyRetention(int $now): int
    {
        $cutoff = $now - ($this->config->retentionDays * 86400);
        $rows = $this->database->query(
            'SELECT id FROM merge_requests
             WHERE state IN ("merged", "closed")
               AND COALESCE(merged_at, closed_at) < ?',
            [$cutoff],
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }
        foreach ($ids as $id) {
            $this->deleteMergeRequest($id);
        }

        return count($ids);
    }

    /**
     * @param array<array-key, mixed> $mr
     */
    private function syncMergeRequest(array $mr): void
    {
        $id = $this->intValue($mr, 'id');
        $projectId = $this->intValue($mr, 'project_id');
        $iid = $this->intValue($mr, 'iid');
        if ($id === 0 || $projectId === 0 || $iid === 0) {
            return;
        }

        $this->storeMergeRequestRow($mr);
        $this->storeApprovals($id, $this->client->approvals($projectId, $iid));
        $this->storeDiscussions($id, $this->authorId($mr), $this->client->discussions($projectId, $iid));
        $this->storePipelinesAndJobs($id, $projectId, $this->client->pipelines($projectId, $iid));
        $this->storeCommits($id, $projectId, $this->client->commits($projectId, $iid));
    }

    /**
     * @param array<array-key, mixed> $mr
     */
    private function syncPipelinesOnly(array $mr): void
    {
        $id = $this->intValue($mr, 'id');
        $projectId = $this->intValue($mr, 'project_id');
        $iid = $this->intValue($mr, 'iid');
        if ($id === 0 || $projectId === 0 || $iid === 0) {
            return;
        }

        $this->storePipelinesAndJobs($id, $projectId, $this->client->pipelines($projectId, $iid));
    }

    /**
     * @param array<array-key, mixed> $mr
     */
    private function storeMergeRequestRow(array $mr): void
    {
        $authorId = $this->authorId($mr);
        if ($authorId !== 0) {
            $author = $mr['author'] ?? null;
            if (is_array($author)) {
                $this->storeUser($author);
            }
        }

        $created = $this->parseTime($mr['created_at'] ?? null);
        if ($created === null) {
            return;
        }
        $updated = $this->parseTime($mr['updated_at'] ?? null) ?? $created;
        $state = $this->stringValue($mr, 'state');
        $state = $state === 'locked' ? 'closed' : $state;

        $this->database->execute(
            'INSERT OR REPLACE INTO merge_requests (
                id, iid, project_id, title, description, author_id, state, draft,
                created_at, merged_at, closed_at, updated_at, web_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $this->intValue($mr, 'id'),
                $this->intValue($mr, 'iid'),
                $this->intValue($mr, 'project_id'),
                $this->stringValue($mr, 'title'),
                $this->nullableStringValue($mr, 'description'),
                $authorId,
                $state,
                $this->boolValue($mr, 'draft') ? 1 : 0,
                $created,
                $this->parseTime($mr['merged_at'] ?? null),
                $this->parseTime($mr['closed_at'] ?? null),
                $updated,
                $this->nullableStringValue($mr, 'web_url'),
            ],
        );
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function storeApprovals(int $mrId, array $payload): void
    {
        $this->database->execute('DELETE FROM approvals WHERE mr_id = ?', [$mrId]);

        $approvedBy = $payload['approved_by'] ?? [];
        if (!is_array($approvedBy)) {
            return;
        }

        foreach ($approvedBy as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $user = $entry['user'] ?? null;
            if (!is_array($user)) {
                continue;
            }
            $userId = $this->intValue($user, 'id');
            $approvedAt = $this->parseTime($entry['approved_at'] ?? null);
            if ($userId === 0 || $approvedAt === null) {
                continue;
            }

            $this->storeUser($user);
            $this->database->execute(
                'INSERT INTO approvals (mr_id, user_id, created_at) VALUES (?, ?, ?)',
                [$mrId, $userId, $approvedAt],
            );
        }
    }

    /**
     * One row per discussion thread: the author of the first non-system,
     * non-author note and that note's time. Reviewers who only reply to an
     * existing thread are not counted (known simplification).
     *
     * @param list<array<string, mixed>> $discussions
     */
    private function storeDiscussions(int $mrId, int $authorId, array $discussions): void
    {
        $this->database->execute('DELETE FROM discussions WHERE mr_id = ?', [$mrId]);

        foreach ($discussions as $discussion) {
            if (!is_array($discussion)) {
                continue;
            }
            $notes = $discussion['notes'] ?? null;
            if (!is_array($notes)) {
                continue;
            }

            foreach ($notes as $note) {
                if (!is_array($note)) {
                    continue;
                }
                if (($note['system'] ?? false) === true) {
                    continue;
                }
                $author = $note['author'] ?? null;
                if (!is_array($author)) {
                    continue;
                }
                $userId = $this->intValue($author, 'id');
                if ($userId === 0 || $userId === $authorId) {
                    continue;
                }
                $createdAt = $this->parseTime($note['created_at'] ?? null);
                if ($createdAt === null) {
                    continue;
                }

                $this->storeUser($author);
                $this->database->execute(
                    'INSERT INTO discussions (mr_id, user_id, created_at) VALUES (?, ?, ?)',
                    [$mrId, $userId, $createdAt],
                );
                break;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $pipelines
     */
    private function storePipelinesAndJobs(int $mrId, int $projectId, array $pipelines): void
    {
        $this->database->execute('DELETE FROM pipelines WHERE mr_id = ?', [$mrId]);
        $this->database->execute('DELETE FROM jobs WHERE mr_id = ?', [$mrId]);

        usort(
            $pipelines,
            fn (array $a, array $b): int => $this->intValue($b, 'id') <=> $this->intValue($a, 'id'),
        );

        foreach ($pipelines as $pipeline) {
            if (!is_array($pipeline)) {
                continue;
            }
            $pipelineId = $this->intValue($pipeline, 'id');
            if ($pipelineId === 0) {
                continue;
            }
            $status = $this->stringValue($pipeline, 'status');

            $this->database->execute(
                'INSERT OR REPLACE INTO pipelines (id, mr_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
                [
                    $pipelineId,
                    $mrId,
                    $status,
                    $this->parseTime($pipeline['created_at'] ?? null),
                    $this->parseTime($pipeline['updated_at'] ?? null),
                ],
            );

            if ($status === 'running' || $status === 'pending') {
                $this->storeJobs($mrId, $projectId, $pipelineId);
            }
        }
    }

    private function storeJobs(int $mrId, int $projectId, int $pipelineId): void
    {
        foreach ($this->client->jobs($projectId, $pipelineId) as $job) {
            if (!is_array($job)) {
                continue;
            }
            $jobId = $this->intValue($job, 'id');
            if ($jobId === 0) {
                continue;
            }

            $this->database->execute(
                'INSERT OR REPLACE INTO jobs (id, pipeline_id, mr_id, status) VALUES (?, ?, ?, ?)',
                [$jobId, $pipelineId, $mrId, $this->stringValue($job, 'status')],
            );
        }
    }

    /**
     * Append-only by (mr_id, sha): new shas insert with stats fetched once,
     * existing shas are re-marked `current`.
     *
     * @param list<array<string, mixed>> $commits
     */
    private function storeCommits(int $mrId, int $projectId, array $commits): void
    {
        $this->database->execute('UPDATE commits SET current = 0 WHERE mr_id = ?', [$mrId]);

        foreach ($commits as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $sha = $this->stringValue($commit, 'id');
            if ($sha === '') {
                continue;
            }
            $message = $this->stringValue($commit, 'title');
            $committedAt = $this->parseTime($commit['committed_date'] ?? null);

            $existing = $this->database->queryValue(
                'SELECT id FROM commits WHERE mr_id = ? AND sha = ?',
                [$mrId, $sha],
            );

            if ($existing === null) {
                $stats = $this->fetchCommitStats($projectId, $sha);
                $this->database->execute(
                    'INSERT INTO commits (mr_id, sha, message, committed_date, current, additions, deletions)
                     VALUES (?, ?, ?, ?, 1, ?, ?)',
                    [$mrId, $sha, $message, $committedAt, $stats['additions'], $stats['deletions']],
                );
            } else {
                $this->database->execute(
                    'UPDATE commits SET current = 1, message = ?, committed_date = ? WHERE mr_id = ? AND sha = ?',
                    [$message, $committedAt, $mrId, $sha],
                );
            }
        }
    }

    /**
     * @return array{additions: int|null, deletions: int|null}
     */
    private function fetchCommitStats(int $projectId, string $sha): array
    {
        try {
            $payload = $this->client->commitStats($projectId, $sha);
        } catch (GitLabException $e) {
            return ['additions' => null, 'deletions' => null];
        }

        $stats = $payload['stats'] ?? null;
        if (!is_array($stats)) {
            return ['additions' => null, 'deletions' => null];
        }

        return [
            'additions' => $this->nullableIntValue($stats, 'additions'),
            'deletions' => $this->nullableIntValue($stats, 'deletions'),
        ];
    }

    /**
     * @return array<int, string> project id => path, filtered to GITLAB_PROJECTS.
     */
    private function fetchProjects(): array
    {
        $allowed = $this->config->gitlabProjects;
        $projectsById = [];

        foreach ($this->client->groupProjects($this->config->gitlabGroup) as $project) {
            if (!is_array($project)) {
                continue;
            }
            $id = $this->intValue($project, 'id');
            $path = $this->stringValue($project, 'path_with_namespace');
            if ($id === 0 || $path === '') {
                continue;
            }
            if ($allowed !== [] && !in_array($path, $allowed, true)) {
                continue;
            }

            $projectsById[$id] = $path;
            $this->database->execute(
                'INSERT OR REPLACE INTO projects (id, path_with_namespace) VALUES (?, ?)',
                [$id, $path],
            );
        }

        return $projectsById;
    }

    /**
     * @param array<array-key, mixed> $mr
     * @param array<int, string> $projectsById
     */
    private function isAllowedMr(array $mr, array $projectsById): bool
    {
        if ($this->config->gitlabProjects === []) {
            return true;
        }

        return array_key_exists($this->intValue($mr, 'project_id'), $projectsById);
    }

    /**
     * @param array<array-key, mixed> $mr
     */
    private function authorId(array $mr): int
    {
        $author = $mr['author'] ?? null;
        if (!is_array($author)) {
            return 0;
        }

        return $this->intValue($author, 'id');
    }

    /**
     * @param array<array-key, mixed> $user
     */
    private function storeUser(array $user): void
    {
        $id = $this->intValue($user, 'id');
        if ($id === 0) {
            return;
        }

        $this->database->execute(
            'INSERT OR REPLACE INTO users (id, name, username, avatar_url) VALUES (?, ?, ?, ?)',
            [
                $id,
                $this->stringValue($user, 'name'),
                $this->stringValue($user, 'username'),
                $this->nullableStringValue($user, 'avatar_url'),
            ],
        );
    }

    /**
     * @return list<int>
     */
    private function runningPipelineMrIds(): array
    {
        $rows = $this->database->query(
            'SELECT p.mr_id FROM pipelines p
             WHERE p.status IN ("running", "pending")
               AND p.id = (SELECT MAX(id) FROM pipelines WHERE mr_id = p.mr_id)',
        );

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['mr_id'];
        }

        return $ids;
    }

    /**
     * @return array<string, int|float|string|null>|null
     */
    private function mergeRequestRow(int $id): null|array
    {
        $rows = $this->database->query('SELECT * FROM merge_requests WHERE id = ?', [$id]);

        return $rows === [] ? null : $rows[0];
    }

    private function deleteMergeRequest(int $id): void
    {
        $this->database->execute('DELETE FROM approvals WHERE mr_id = ?', [$id]);
        $this->database->execute('DELETE FROM discussions WHERE mr_id = ?', [$id]);
        $this->database->execute('DELETE FROM commits WHERE mr_id = ?', [$id]);
        $this->database->execute('DELETE FROM pipelines WHERE mr_id = ?', [$id]);
        $this->database->execute('DELETE FROM jobs WHERE mr_id = ?', [$id]);
        $this->database->execute('DELETE FROM merge_requests WHERE id = ?', [$id]);
    }

    private function acquireLock(int $now): bool
    {
        $this->database->execute(
            "INSERT OR IGNORE INTO sync_state (key, value) VALUES ('sync_lock', ?)",
            [(string) $now],
        );
        if ($this->database->changes() === 1) {
            return true;
        }

        $value = $this->database->queryValue("SELECT value FROM sync_state WHERE key = 'sync_lock'");
        if ($value !== null && $now - (int) $value > AppConfig::LOCK_TIMEOUT_SECONDS) {
            $this->database->execute(
                "UPDATE sync_state SET value = ? WHERE key = 'sync_lock'",
                [(string) $now],
            );

            return true;
        }

        return false;
    }

    private function releaseLock(): void
    {
        $this->database->execute("DELETE FROM sync_state WHERE key = 'sync_lock'");
    }

    private function report(string $message): void
    {
        $this->output?->writeln($message);
    }

    /**
     * Per-MR progress: at -v every MR is logged with its title; otherwise a
     * running count every 25 MRs so a long backfill still shows life.
     *
     * @param array<array-key, mixed> $mr
     * @param array<int, string> $projectsById
     */
    private function reportMrProgress(int $done, int $total, array $mr, array $projectsById): void
    {
        if ($this->output === null) {
            return;
        }

        if ($this->output->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
            $this->output->writeln(sprintf('  %d/%d %s', $done, $total, $this->mrLabel($mr, $projectsById)));
        } elseif ($done % 25 === 0) {
            $this->output->writeln(sprintf('  ...%d/%d', $done, $total));
        }
    }

    /**
     * @param array<array-key, mixed> $mr
     * @param array<int, string> $projectsById
     */
    private function mrLabel(array $mr, array $projectsById): string
    {
        $projectId = $this->intValue($mr, 'project_id');
        $path = $projectsById[$projectId] ?? ('project ' . $projectId);
        $title = $this->stringValue($mr, 'title');
        if (mb_strlen($title) > 60) {
            $title = mb_substr($title, 0, 57) . '...';
        }

        return sprintf('%s !%d %s', $path, $this->intValue($mr, 'iid'), $title);
    }

    private function setSyncState(string $key, string $value): void
    {
        $this->database->execute(
            'INSERT OR REPLACE INTO sync_state (key, value) VALUES (?, ?)',
            [$key, $value],
        );
    }

    private function parseTime(mixed $value): null|int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function intValue(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }

        return '';
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function nullableStringValue(array $data, string $key): null|string
    {
        $value = $data[$key] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function boolValue(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        return is_bool($value) ? $value : false;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function nullableIntValue(array $data, string $key): null|int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return null;
    }
}
