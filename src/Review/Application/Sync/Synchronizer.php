<?php

declare(strict_types=1);

namespace App\Review\Application\Sync;

use App\Config\AppConfig;
use App\Review\Domain\Approval\ApprovalRepository;
use App\Review\Domain\Commit\CommitRepository;
use App\Review\Domain\Discussion\DiscussionRepository;
use App\Review\Domain\MergeRequest\MergeRequestRepository;
use App\Review\Domain\Pipeline\PipelineRepository;
use App\Review\Domain\Project\ProjectRepository;
use App\Review\Domain\User\UserRepository;
use App\Review\Infrastructure\GitLab\GitLabClientInterface;
use App\Review\Infrastructure\GitLab\GitLabException;
use App\Shared\Infrastructure\Persistence\SyncStateStore;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function array_key_exists;
use function array_unique;
use function array_values;
use function count;
use function gmdate;
use function in_array;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
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
        private readonly MergeRequestRepository $mergeRequests,
        private readonly UserRepository $users,
        private readonly ProjectRepository $projects,
        private readonly ApprovalRepository $approvals,
        private readonly DiscussionRepository $discussions,
        private readonly CommitRepository $commits,
        private readonly PipelineRepository $pipelines,
        private readonly SyncStateStore $syncState,
        private readonly Connection $connection,
        private readonly AppConfig $config,
    ) {
    }

    public function lastSync(): null|int
    {
        $value = $this->syncState->get('last_sync');

        return $value === null ? null : (int) $value;
    }

    /**
     * Epoch time the user ranking was last recomputed, or null when never run.
     */
    public function lastRank(): null|int
    {
        $value = $this->syncState->get('last_rank_at');

        return $value === null ? null : (int) $value;
    }

    /**
     * Recompute each user's all-time MR count from GitLab and persist it to
     * `users.mr_count` / `users.ranked_at`. Best-effort: a per-user GitLab failure keeps
     * that user's previous count and the run continues, so one unreachable user cannot
     * blank the ranking. Runs without the sync lock (it is not a sync) and never wipes a
     * count it could not refresh.
     */
    public function rankUsers(int $now, null|OutputInterface $output = null): void
    {
        $this->output = $output;

        $ids = $this->users->allIds();
        $total = count($ids);
        $this->report(sprintf('Ranking users: fetching all-time MR counts for %d user(s)...', $total));

        $succeeded = 0;
        $failed = 0;
        $updates = [];
        foreach ($ids as $id) {
            try {
                $count = $this->client->authorMergeRequestCount($id);
            } catch (GitLabException $e) {
                $failed++;
                $this->report(sprintf('  user %d: keeping previous count (%s)', $id, $e->getMessage()));

                continue;
            }

            $updates[] = [$id, $count];
            $succeeded++;
        }

        $this->connection->beginTransaction();
        try {
            foreach ($updates as [$id, $count]) {
                $this->users->updateRank($id, $count, $now);
            }
            $this->syncState->set('last_rank_at', (string) $now);
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $this->report(sprintf(
            '  ranked %d user(s); %d failed and kept their previous count.',
            $succeeded,
            $failed,
        ));
    }

    /**
     * Full backfill: every project, all currently open MRs (any age) plus every
     * MR merged within the retention window (kept for the merge metrics), and
     * every sub-resource of every MR. Closed MRs are never fetched — no metric
     * uses them, and the list shows only open MRs. After the fetch, any cached
     * MR not in the fetched set (closed since, or merged out of the retention
     * window) is dropped, so the cache mirrors GitLab.
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

            $window = $now - ($this->config->retentionDays * 86400);
            $this->report(sprintf(
                'Fetching merge requests (open + merged within %d days)...',
                $this->config->retentionDays,
            ));
            $mrs = $this->mergeAndDedup(
                $this->client->groupMergeRequests($this->config->gitlabGroup, ['state' => 'opened']),
                $this->client->groupMergeRequests($this->config->gitlabGroup, [
                    'state' => 'merged',
                    'updated_after' => gmdate(DATE_ATOM, $window),
                ]),
            );
            $total = count($mrs);
            $this->report(sprintf('  %d MR(s) fetched; syncing sub-resources...', $total));

            $fetchedIds = [];
            foreach ($mrs as $mr) {
                $id = $this->intValue($mr, 'id');
                if ($id !== 0) {
                    $fetchedIds[$id] = true;
                }
            }

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

            $dropped = $this->reconcileMrs($fetchedIds);
            $this->report(sprintf('Reconciled: dropped %d MR(s) no longer open or recently merged.', $dropped));
            $this->syncState->set('last_sync', (string) $now);
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
                $id = $this->intValue($mr, 'id');
                if ($id === 0) {
                    continue;
                }
                // Closed MRs are not stored (no metric uses them and the list
                // shows only open MRs): drop a closed transition from the cache.
                if ($this->stringValue($mr, 'state') === 'closed') {
                    $this->deleteMergeRequest($id);
                    continue;
                }
                $this->syncMergeRequest($mr);
                $done++;
                $syncedIds[] = $id;
                $this->reportMrProgress($done, $total, $mr, $projectsById);
            }

            $runningIds = $this->pipelines->runningPipelineMrIds();
            $runningCount = count($runningIds);
            $this->report(sprintf('  synced %d/%d; re-polling %d running pipeline(s)', $done, $total, $runningCount));
            $polled = 0;
            foreach ($runningIds as $mrId) {
                if (in_array($mrId, $syncedIds, true)) {
                    continue;
                }
                $row = $this->mergeRequests->findById($mrId);
                if ($row !== null) {
                    $this->syncPipelinesOnly($row);
                    $polled++;
                }
            }

            $this->report(sprintf('  re-polled %d MR(s).', $polled));
            $this->syncState->set('last_sync', (string) $now);
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
            $this->syncState->set('last_sync', (string) $now);
        } finally {
            $this->releaseLock();
        }
    }

    /**
     * Project ids already cached in `projects` (i.e. allowed by GITLAB_PROJECTS
     * as of the last project fetch). Used by the refresh worker to filter its
     * cheap list call without an extra GitLab request.
     *
     * @return array<int, true>
     */
    public function cachedProjectIds(): array
    {
        $ids = [];
        foreach ($this->projects->allIds() as $id) {
            $ids[$id] = true;
        }

        return $ids;
    }

    /**
     * Identifiers of every cached open non-stale MR, for refresh cycles: MRs
     * not in the GitLab "updated since" list still get their sub-resources
     * re-fetched. Stale MRs (older than STALE_DAYS) are excluded — they rarely
     * change and are covered by the nightly full sync; one that does change on
     * GitLab still enters the cycle via the updated-since list.
     *
     * @return list<array{id: int, project_id: int, iid: int, author_id: int}>
     */
    public function openMergeRequestRefs(int $now): array
    {
        return $this->mergeRequests->openRefsCreatedAfter($now - (AppConfig::STALE_DAYS * 86400));
    }

    public function isMergeRequestCached(int $id): bool
    {
        return $this->mergeRequests->isCached($id);
    }

    /**
     * Drops a merge request and all its sub-resources from the cache (used by
     * the refresh worker when its list call observes a closed transition).
     */
    public function removeMergeRequest(int $id): void
    {
        $this->deleteMergeRequest($id);
    }

    /**
     * Delete merged/closed MRs whose merge/close time is older than
     * `RETENTION_DAYS`, together with all their sub-resources.
     */
    public function applyRetention(int $now): int
    {
        $cutoff = $now - ($this->config->retentionDays * 86400);
        $ids = $this->mergeRequests->retentionIdsBefore($cutoff);
        foreach ($ids as $id) {
            $this->deleteMergeRequest($id);
        }

        return count($ids);
    }

    /**
     * Same sub-resource fetch as {@see syncMergeRequest()}, but reports
     * progress after each step for the refresh worker
     * (`App\Review\Application\Refresh\RefreshWorker`) to publish as Mercure
     * events. `requests_expected` starts at 4 (one call per sub-resource) and
     * is corrected once pipelines/commits are known: +1 per running/pending
     * pipeline (a jobs call) and +1 per not-yet-cached commit sha (a
     * commit-stats call).
     *
     * @param array<array-key, mixed> $mr
     * @param callable(int $requestsDone, int $requestsExpected): void $onProgress
     */
    public function syncMergeRequestForRefresh(array $mr, callable $onProgress): void
    {
        $id = $this->intValue($mr, 'id');
        $projectId = $this->intValue($mr, 'project_id');
        $iid = $this->intValue($mr, 'iid');
        if ($id === 0 || $projectId === 0 || $iid === 0) {
            return;
        }

        $this->storeMergeRequestRow($mr);
        $this->refreshSubResources($id, $projectId, $iid, $this->authorId($mr), $onProgress);
    }

    /**
     * Re-fetch every sub-resource of an already-stored MR without touching its
     * main row. Used for cycle jobs whose MR was not in the GitLab "updated
     * since" list: the cached row is current, but approvals/discussions can
     * change without bumping `updated_at`.
     */
    public function refreshSubResources(
        int $id,
        int $projectId,
        int $iid,
        int $authorId,
        callable $onProgress,
    ): void {
        $done = 0;
        $expected = 4;

        $this->storeApprovals($id, $this->client->approvals($projectId, $iid));
        $onProgress(++$done, $expected);

        $this->storeDiscussions($id, $authorId, $this->client->discussions($projectId, $iid));
        $onProgress(++$done, $expected);

        $pipelines = $this->client->pipelines($projectId, $iid);
        foreach ($pipelines as $pipeline) {
            if (!is_array($pipeline)) {
                continue;
            }
            $status = $this->stringValue($pipeline, 'status');
            if ($status === 'running' || $status === 'pending') {
                $expected++;
            }
        }
        $onProgress($done, $expected);
        $this->storePipelinesAndJobs($id, $projectId, $pipelines);
        $onProgress(++$done, $expected);

        $commits = $this->client->commits($projectId, $iid);
        $expected += $this->countUncachedShas($id, $commits);
        $onProgress($done, $expected);
        $this->storeCommits($id, $projectId, $commits);
        $onProgress(++$done, $expected);
    }

    /**
     * @param array<array-key, mixed> $mr
     */
    private function syncMergeRequest(array $mr): void
    {
        $this->syncMergeRequestForRefresh($mr, static function (int $done, int $expected): void {
        });
    }

    /**
     * Number of shas in `$commits` not already cached for this MR — each one
     * costs one `commitStats` call in {@see storeCommits()}.
     *
     * @param list<array<string, mixed>> $commits
     */
    private function countUncachedShas(int $mrId, array $commits): int
    {
        $seen = [];
        $count = 0;
        foreach ($commits as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $sha = $this->stringValue($commit, 'id');
            if ($sha === '' || array_key_exists($sha, $seen)) {
                continue;
            }
            $seen[$sha] = true;

            if (!$this->commits->isCached($mrId, $sha)) {
                $count++;
            }
        }

        return $count;
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
                $this->users->upsert($this->normalizeUser($author));
            }
        }

        $created = $this->parseTime($mr['created_at'] ?? null);
        if ($created === null) {
            return;
        }
        $updated = $this->parseTime($mr['updated_at'] ?? null) ?? $created;
        $state = $this->stringValue($mr, 'state');
        $state = $state === 'locked' ? 'closed' : $state;

        $this->mergeRequests->upsert([
            'id' => $this->intValue($mr, 'id'),
            'iid' => $this->intValue($mr, 'iid'),
            'project_id' => $this->intValue($mr, 'project_id'),
            'title' => $this->stringValue($mr, 'title'),
            'description' => $this->nullableStringValue($mr, 'description'),
            'author_id' => $authorId,
            'state' => $state,
            'draft' => $this->boolValue($mr, 'draft') ? 1 : 0,
            'created_at' => $created,
            'merged_at' => $this->parseTime($mr['merged_at'] ?? null),
            'closed_at' => $this->parseTime($mr['closed_at'] ?? null),
            'updated_at' => $updated,
            'web_url' => $this->nullableStringValue($mr, 'web_url'),
            'merge_status' => $this->stringValue($mr, 'merge_status'),
            'has_conflicts' => $this->boolValue($mr, 'has_conflicts') ? 1 : 0,
            'labels' => $this->labelsJson($mr),
        ]);
    }

    /**
     * MR labels as a JSON array of names. GitLab reports labels as a plain
     * string list on the merge-request endpoints; the object form
     * `{"name": ...}` is tolerated anyway.
     *
     * @param array<array-key, mixed> $mr
     */
    private function labelsJson(array $mr): string
    {
        $labels = $mr['labels'] ?? [];
        if (!is_array($labels)) {
            return '[]';
        }

        $names = [];
        foreach ($labels as $label) {
            if (is_string($label) && $label !== '') {
                $names[] = $label;
            } elseif (is_array($label) && is_string($label['name'] ?? null)) {
                $names[] = $label['name'];
            }
        }

        $json = json_encode(array_values(array_unique($names)));

        return $json === false ? '[]' : $json;
    }

    /**
     * @param array<array-key, mixed> $payload
     */
    private function storeApprovals(int $mrId, array $payload): void
    {
        $approvedBy = $payload['approved_by'] ?? [];
        if (!is_array($approvedBy)) {
            $this->approvals->replaceForMergeRequest($mrId, []);

            return;
        }

        $approvals = [];
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

            $this->users->upsert($this->normalizeUser($user));
            $approvals[] = ['user_id' => $userId, 'created_at' => $approvedAt];
        }

        $this->approvals->replaceForMergeRequest($mrId, $approvals);
    }

    /**
     * One row per discussion thread: the author of the first non-system,
     * non-author note and that note's time. Reviewers who only reply to an
     * existing thread are not counted (known simplification). `resolved` is 0
     * when any resolvable note in the thread is still unresolved.
     *
     * @param list<array<string, mixed>> $discussions
     */
    private function storeDiscussions(int $mrId, int $authorId, array $discussions): void
    {
        $rows = [];
        foreach ($discussions as $discussion) {
            if (!is_array($discussion)) {
                continue;
            }
            $notes = $discussion['notes'] ?? null;
            if (!is_array($notes)) {
                continue;
            }

            $resolved = true;
            foreach ($notes as $note) {
                if (!is_array($note)) {
                    continue;
                }
                if (($note['resolvable'] ?? false) === true && ($note['resolved'] ?? false) === false) {
                    $resolved = false;
                    break;
                }
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

                $this->users->upsert($this->normalizeUser($author));
                $rows[] = ['user_id' => $userId, 'created_at' => $createdAt, 'resolved' => $resolved];
                break;
            }
        }

        $this->discussions->replaceForMergeRequest($mrId, $rows);
    }

    /**
     * @param list<array<string, mixed>> $pipelines
     */
    private function storePipelinesAndJobs(int $mrId, int $projectId, array $pipelines): void
    {
        $this->pipelines->deleteByMergeRequest($mrId);
        $this->pipelines->deleteJobsByMr($mrId);

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

            $this->pipelines->upsertPipeline([
                'id' => $pipelineId,
                'merge_request_id' => $mrId,
                'status' => $status,
                'created_at' => $this->parseTime($pipeline['created_at'] ?? null),
                'updated_at' => $this->parseTime($pipeline['updated_at'] ?? null),
            ]);

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

            $this->pipelines->upsertJob([
                'id' => $jobId,
                'pipeline_id' => $pipelineId,
                'merge_request_id' => $mrId,
                'status' => $this->stringValue($job, 'status'),
            ]);
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
        $this->commits->markAllNonCurrent($mrId);

        $seen = [];
        foreach ($commits as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $sha = $this->stringValue($commit, 'id');
            if ($sha === '' || array_key_exists($sha, $seen)) {
                continue;
            }
            $seen[$sha] = true;

            $message = $this->stringValue($commit, 'title');
            $committedAt = $this->parseTime($commit['committed_date'] ?? null);

            if ($this->commits->isCached($mrId, $sha)) {
                $additions = null;
                $deletions = null;
            } else {
                $stats = $this->fetchCommitStats($projectId, $sha);
                $additions = $stats['additions'];
                $deletions = $stats['deletions'];
            }

            $this->commits->upsert($mrId, $sha, [
                'message' => $message,
                'committed_date' => $committedAt,
                'additions' => $additions,
                'deletions' => $deletions,
            ]);
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
            $this->projects->upsert([
                'id' => $id,
                'path_with_namespace' => $path,
                'name' => $this->stringValue($project, 'name'),
                'avatar_url' => $this->nullableStringValue($project, 'avatar_url'),
            ]);
        }

        return $projectsById;
    }

    /**
     * Union two MR lists, dropping a second occurrence of any MR id. The open
     * list and the recent list overlap on open MRs updated within the window;
     * syncing an MR twice is idempotent but wastes the sub-resource fetches,
     * so dedup keeps each MR's sub-resource calls to one set per backfill.
     *
     * @param list<array<string, mixed>> $a
     * @param list<array<string, mixed>> $b
     *
     * @return list<array<string, mixed>>
     */
    private function mergeAndDedup(array $a, array $b): array
    {
        $seen = [];
        $result = [];
        foreach ([$a, $b] as $mrs) {
            foreach ($mrs as $mr) {
                if (!is_array($mr)) {
                    continue;
                }
                $id = $this->intValue($mr, 'id');
                if ($id === 0 || array_key_exists($id, $seen)) {
                    continue;
                }
                $seen[$id] = true;
                $result[] = $mr;
            }
        }

        return $result;
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
     * @param array<array-key, mixed> $user
     *
     * @return array<string, int|float|string|null>
     */
    private function normalizeUser(array $user): array
    {
        return [
            'id' => $this->intValue($user, 'id'),
            'name' => $this->stringValue($user, 'name'),
            'username' => $this->stringValue($user, 'username'),
            'avatar_url' => $this->nullableStringValue($user, 'avatar_url'),
        ];
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

    private function deleteMergeRequest(int $id): void
    {
        $this->approvals->replaceForMergeRequest($id, []);
        $this->discussions->replaceForMergeRequest($id, []);
        $this->commits->deleteByMergeRequest($id);
        $this->pipelines->deleteByMergeRequest($id);
        $this->pipelines->deleteJobsByMr($id);
        $this->mergeRequests->remove($id);
    }

    /**
     * Drop every cached MR whose id is not in the fetched set. After a full
     * backfill the fetched set is authoritative for which MRs are open or
     * recently merged, so anything missing has been closed or merged out of the
     * retention window and should not linger in the cache.
     *
     * @param array<int, true> $fetchedIds
     */
    private function reconcileMrs(array $fetchedIds): int
    {
        $deleted = 0;
        foreach ($this->mergeRequests->allIds() as $id) {
            if (!array_key_exists($id, $fetchedIds)) {
                $this->deleteMergeRequest($id);
                $deleted++;
            }
        }

        return $deleted;
    }

    private function acquireLock(int $now): bool
    {
        if ($this->syncState->insertIfAbsent('sync_lock', (string) $now)) {
            return true;
        }

        $value = $this->syncState->get('sync_lock');
        if ($value !== null && $now - (int) $value > AppConfig::LOCK_TIMEOUT_SECONDS) {
            $this->syncState->set('sync_lock', (string) $now);

            return true;
        }

        return false;
    }

    private function releaseLock(): void
    {
        $this->syncState->delete('sync_lock');
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
