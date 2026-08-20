<?php

declare(strict_types=1);

namespace App\Review\Domain\Commit;

/**
 * Persistence boundary for the `commits` cache table (per-table aggregate).
 * The `current` flag marks the MR's tip set: each sync marks every stored row
 * non-current, then re-inserts the fetched shas, upserting on (mr_id, sha) so
 * re-pushes refresh the same rows instead of duplicating them.
 */
interface CommitRepository
{
    /**
     * Marks every stored commit of the MR non-current, before the fresh tip
     * set is upserted.
     */
    public function markAllNonCurrent(int $mrId): void;

    /**
     * Insert-or-refresh a tip commit: flips the row (back) to current and
     * refreshes its message and committed date.
     *
     * @param array{message: string|null, committed_date: int|null, additions: int|null, deletions: int|null} $commit
     */
    public function upsert(int $mrId, string $sha, array $commit): void;

    public function isCached(int $mrId, string $sha): bool;
}
