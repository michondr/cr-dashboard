<?php

declare(strict_types=1);

namespace App\Review\Domain\MergeRequest;

/**
 * Persistence boundary for the `merge_requests` cache table (per-table
 * aggregate). Values are the plain row representation used across the app
 * (epoch-second timestamps, 0/1 flags); implementations convert at the edge
 * of the DB and own the upsert semantics.
 */
interface MergeRequestRepository
{
    /**
     * @param array<string, int|float|string|null> $mr
     */
    public function upsert(array $mr): void;

    /**
     * @return array<string, int|float|string|null>|null
     */
    public function findById(int $id): null|array;

    public function isCached(int $id): bool;

    /**
     * @return list<int>
     */
    public function allIds(): array;

    /**
     * Open, non-stale MR refs: the refresh cycle's working set.
     *
     * @return list<array{id: int, project_id: int, iid: int, author_id: int}>
     */
    public function openRefsCreatedAfter(int $after): array;

    /**
     * Merged/closed MR ids whose merge/close time is older than the cutoff.
     *
     * @return list<int>
     */
    public function retentionIdsBefore(int $cutoff): array;

    /**
     * Drops the merge-request row itself. Sub-resources are removed through
     * their own repositories; callers compose the cascade.
     */
    public function remove(int $id): void;
}
