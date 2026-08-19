<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * In-memory snapshot of the SQLite cache, fed into the pure metric functions.
 * Rows are associative arrays as returned by {@see \App\Storage\Database::query()}.
 */
final class Dataset
{
    /**
     * @param list<array<string, int|float|string|null>> $users
     * @param list<array<string, int|float|string|null>> $mrs
     * @param list<array<string, int|float|string|null>> $approvals
     * @param list<array<string, int|float|string|null>> $discussions
     * @param list<array<string, int|float|string|null>> $commits
     * @param list<array<string, int|float|string|null>> $pipelines
     * @param list<array<string, int|float|string|null>> $jobs
     */
    public function __construct(
        public readonly array $users,
        public readonly array $mrs,
        public readonly array $approvals,
        public readonly array $discussions,
        public readonly array $commits,
        public readonly array $pipelines,
        public readonly array $jobs,
    ) {
    }
}
