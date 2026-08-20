<?php

declare(strict_types=1);

namespace App\Review\Domain\Pipeline;

/**
 * Persistence boundary for the `pipelines` and `jobs` cache tables (a single
 * aggregate: pipelines only mean anything together with their jobs). Both are
 * re-inserted wholesale per MR on every sync.
 */
interface PipelineRepository
{
    public function deleteByMergeRequest(int $mrId): void;

    /**
     * @param array<string, int|float|string|null> $pipeline
     */
    public function upsertPipeline(array $pipeline): void;

    public function deleteJobsByMr(int $mrId): void;

    /**
     * @param array<string, int|float|string|null> $job
     */
    public function upsertJob(array $job): void;

    /**
     * Mr ids whose newest pipeline is still running or pending — their job
     * detail is re-fetched each refresh cycle.
     *
     * @return list<int>
     */
    public function runningPipelineMrIds(): array;
}
