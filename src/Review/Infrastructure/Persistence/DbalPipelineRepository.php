<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\Pipeline\PipelineRepository;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed {@see PipelineRepository} for both `pipelines` and `jobs`.
 */
final class DbalPipelineRepository implements PipelineRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function deleteByMergeRequest(int $mrId): void
    {
        $this->connection->executeStatement('DELETE FROM pipelines WHERE merge_request_id = ?', [$mrId]);
    }

    /**
     * @param array<string, int|float|string|null> $pipeline
     */
    public function upsertPipeline(array $pipeline): void
    {
        $this->connection->executeStatement(
            'INSERT INTO pipelines (id, merge_request_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
                merge_request_id = excluded.merge_request_id, status = excluded.status,
                created_at = excluded.created_at, updated_at = excluded.updated_at',
            [
                (int) ($pipeline['id'] ?? 0),
                (int) ($pipeline['merge_request_id'] ?? 0),
                (string) ($pipeline['status'] ?? ''),
                $this->nullableTimestamp($pipeline, 'created_at'),
                $this->nullableTimestamp($pipeline, 'updated_at'),
            ],
        );
    }

    public function deleteJobsByMr(int $mrId): void
    {
        $this->connection->executeStatement('DELETE FROM jobs WHERE merge_request_id = ?', [$mrId]);
    }

    /**
     * @param array<string, int|float|string|null> $job
     */
    public function upsertJob(array $job): void
    {
        $this->connection->executeStatement(
            'INSERT INTO jobs (id, pipeline_id, merge_request_id, status) VALUES (?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
                pipeline_id = excluded.pipeline_id, merge_request_id = excluded.merge_request_id,
                status = excluded.status',
            [
                (int) ($job['id'] ?? 0),
                (int) ($job['pipeline_id'] ?? 0),
                (int) ($job['merge_request_id'] ?? 0),
                (string) ($job['status'] ?? ''),
            ],
        );
    }

    public function runningPipelineMrIds(): array
    {
        $ids = [];
        foreach (
            SqliteRows::list(
                $this->connection,
                'SELECT p.merge_request_id FROM pipelines p
             WHERE p.status IN ("running", "pending")
               AND p.id = (SELECT MAX(id) FROM pipelines WHERE merge_request_id = p.merge_request_id)',
            ) as $row
        ) {
            $ids[] = (int) $row['merge_request_id'];
        }

        return $ids;
    }

    /**
     * @param array<string, int|float|string|null> $pipeline
     */
    private function nullableTimestamp(array $pipeline, string $key): null|string
    {
        $value = $pipeline[$key] ?? null;

        return $value === null ? null : SqliteDateTime::toStorage((int) $value);
    }
}
