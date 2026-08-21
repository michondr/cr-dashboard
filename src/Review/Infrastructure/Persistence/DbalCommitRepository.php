<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\Commit\CommitRepository;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed {@see CommitRepository}. Upserting on (mr_id, sha) keeps a
 * re-pushed branch on the same rows; only the current tip set is marked
 * `current`.
 */
final class DbalCommitRepository implements CommitRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function markAllNonCurrent(int $mrId): void
    {
        $this->connection->executeStatement('UPDATE commits SET current = 0 WHERE merge_request_id = ?', [$mrId]);
    }

    public function upsert(int $mrId, string $sha, array $commit): void
    {
        $this->connection->executeStatement(
            'INSERT INTO commits (merge_request_id, sha, message, committed_date, current, additions, deletions)
             VALUES (?, ?, ?, ?, 1, ?, ?)
             ON CONFLICT(merge_request_id, sha) DO UPDATE SET
                current = 1, message = excluded.message,
                committed_date = excluded.committed_date',
            [
                $mrId,
                $sha,
                $commit['message'] ?? null,
                $this->nullableTimestamp($commit, 'committed_date'),
                $commit['additions'] ?? null,
                $commit['deletions'] ?? null,
            ],
        );
    }

    public function isCached(int $mrId, string $sha): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM commits WHERE merge_request_id = ? AND sha = ?',
            [$mrId, $sha],
        ) !== false;
    }

    public function deleteByMergeRequest(int $mrId): void
    {
        $this->connection->executeStatement('DELETE FROM commits WHERE merge_request_id = ?', [$mrId]);
    }

    /**
     * @param array<string, int|float|string|null> $commit
     */
    private function nullableTimestamp(array $commit, string $key): null|string
    {
        $value = $commit[$key] ?? null;

        return $value === null ? null : SqliteDateTime::toStorage((int) $value);
    }
}
