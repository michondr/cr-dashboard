<?php

declare(strict_types=1);

namespace App\Metrics;

use function in_array;
use function usort;

/**
 * Metric 12 - pipeline indicator for the MR row, computed from the latest
 * pipeline (highest id) and its jobs.
 */
final class PipelineIndicator
{
    private const RUNNING_STATUSES = [
        'running',
        'pending',
        'created',
        'waiting_for_resource',
        'preparing',
        'scheduled',
    ];

    /**
     * @param list<array{id: int, status: string}> $pipelines
     * @param list<array{pipeline_id: int, status: string}> $jobs
     *
     * @return array{status: string, indicator: string, tint: string|null}
     */
    public static function compute(array $pipelines, array $jobs): array
    {
        if ($pipelines === []) {
            return ['status' => '', 'indicator' => 'none', 'tint' => null];
        }

        usort($pipelines, static fn (array $a, array $b): int => $b['id'] <=> $a['id']);
        $latest = $pipelines[0];
        $status = $latest['status'];
        $indicator = match (true) {
            $status === 'success' => 'check',
            $status === 'failed' => 'fail',
            $status === 'canceled', $status === 'skipped', $status === 'manual' => 'neutral',
            in_array($status, self::RUNNING_STATUSES, true) => 'spinner',
            default => 'none',
        };

        $tint = $indicator === 'spinner' ? self::spinnerTint($jobs, $latest['id']) : null;

        return ['status' => $status, 'indicator' => $indicator, 'tint' => $tint];
    }

    /**
     * @param list<array{pipeline_id: int, status: string}> $jobs
     */
    private static function spinnerTint(array $jobs, int $pipelineId): null|string
    {
        foreach ($jobs as $job) {
            if ($job['pipeline_id'] !== $pipelineId) {
                continue;
            }
            if ($job['status'] === 'failed') {
                return 'fail';
            }
        }

        foreach ($jobs as $job) {
            if ($job['pipeline_id'] !== $pipelineId) {
                continue;
            }
            if ($job['status'] === 'warning') {
                return 'warn';
            }
        }

        return null;
    }
}
