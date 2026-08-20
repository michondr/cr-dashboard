<?php

declare(strict_types=1);

namespace App\Review\Domain\User;

/**
 * Persistence boundary for the `users` cache table (per-table aggregate).
 * Sync upserts author data; the daily ranking job overwrites the all-time MR
 * count in place, so user rows are shared between the two writers.
 */
interface UserRepository
{
    /**
     * @param array<string, int|float|string|null> $user
     */
    public function upsert(array $user): void;

    /**
     * @return list<int>
     */
    public function allIds(): array;

    /**
     * Persists a freshly recomputed all-time MR count (app:rank-users).
     */
    public function updateRank(int $userId, int $mrCount, int $rankedAt): void;
}
