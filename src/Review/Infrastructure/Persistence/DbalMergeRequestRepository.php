<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\MergeRequest\MergeRequestRepository;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed {@see MergeRequestRepository}. Timestamps cross the epoch
 * (app) / 'Y-m-d H:i:s' (Doctrine DATETIME) boundary here; every other value
 * is bound as-is.
 */
final class DbalMergeRequestRepository implements MergeRequestRepository
{
    private const TIMESTAMP_COLUMNS = ['created_at', 'merged_at', 'closed_at', 'updated_at'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function upsert(array $mr): void
    {
        $this->connection->executeStatement(
            'INSERT INTO merge_requests (
                id, iid, project_id, title, description, author_id, state, draft,
                created_at, merged_at, closed_at, updated_at, web_url,
                merge_status, has_conflicts, labels
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
                iid = excluded.iid, project_id = excluded.project_id,
                title = excluded.title, description = excluded.description,
                author_id = excluded.author_id, state = excluded.state,
                draft = excluded.draft, created_at = excluded.created_at,
                merged_at = excluded.merged_at, closed_at = excluded.closed_at,
                updated_at = excluded.updated_at, web_url = excluded.web_url,
                merge_status = excluded.merge_status,
                has_conflicts = excluded.has_conflicts, labels = excluded.labels',
            [
                (int) ($mr['id'] ?? 0),
                (int) ($mr['iid'] ?? 0),
                (int) ($mr['project_id'] ?? 0),
                (string) ($mr['title'] ?? ''),
                $mr['description'] ?? null,
                (int) ($mr['author_id'] ?? 0),
                (string) ($mr['state'] ?? ''),
                (int) ($mr['draft'] ?? 0),
                SqliteDateTime::toStorage((int) ($mr['created_at'] ?? 0)),
                $this->nullableTimestamp($mr, 'merged_at'),
                $this->nullableTimestamp($mr, 'closed_at'),
                SqliteDateTime::toStorage((int) ($mr['updated_at'] ?? 0)),
                $mr['web_url'] ?? null,
                (string) ($mr['merge_status'] ?? ''),
                (int) ($mr['has_conflicts'] ?? 0),
                (string) ($mr['labels'] ?? '[]'),
            ],
        );
    }

    public function findById(int $id): null|array
    {
        $row = SqliteRows::first($this->connection, 'SELECT * FROM merge_requests WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }

        foreach (self::TIMESTAMP_COLUMNS as $column) {
            $value = $row[$column] ?? null;
            $row[$column] = $value === null ? null : SqliteDateTime::fromStorage((string) $value);
        }

        return $row;
    }

    public function isCached(int $id): bool
    {
        return SqliteRows::value($this->connection, 'SELECT 1 FROM merge_requests WHERE id = ?', [$id]) !== null;
    }

    public function allIds(): array
    {
        $ids = [];
        foreach (SqliteRows::list($this->connection, 'SELECT id FROM merge_requests') as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function openRefsCreatedAfter(int $after): array
    {
        $refs = [];
        foreach (
            SqliteRows::list(
                $this->connection,
                'SELECT id, project_id, iid, author_id FROM merge_requests
             WHERE state = \'opened\' AND created_at > ?',
                [SqliteDateTime::toStorage($after)],
            ) as $row
        ) {
            $refs[] = [
                'id' => (int) $row['id'],
                'project_id' => (int) $row['project_id'],
                'iid' => (int) $row['iid'],
                'author_id' => (int) $row['author_id'],
            ];
        }

        return $refs;
    }

    public function retentionIdsBefore(int $cutoff): array
    {
        $ids = [];
        foreach (
            SqliteRows::list(
                $this->connection,
                'SELECT id FROM merge_requests
             WHERE state IN ("merged", "closed") AND COALESCE(merged_at, closed_at) < ?',
                [SqliteDateTime::toStorage($cutoff)],
            ) as $row
        ) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function remove(int $id): void
    {
        $this->connection->executeStatement('DELETE FROM merge_requests WHERE id = ?', [$id]);
    }

    /**
     * @param array<string, int|float|string|null> $mr
     */
    private function nullableTimestamp(array $mr, string $key): null|string
    {
        $value = $mr[$key] ?? null;

        return $value === null ? null : SqliteDateTime::toStorage((int) $value);
    }
}
