<?php

declare(strict_types=1);

namespace App\ReadModel;

/**
 * In-memory snapshot of the cache, fed into the pure metric functions and the
 * API builder. Rows are the canonical read-model shape: Doctrine-native column
 * names (`merge_request_id`, ...) with timestamps decoded back to epoch
 * seconds, as returned by {@see DatasetRepository::load()}.
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
