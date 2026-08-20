<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\Discussion\DiscussionRepository;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * DBAL-backed {@see DiscussionRepository}. The wipe-and-insert runs in one
 * transaction so a failed write cannot leave stale threads behind.
 */
final class DbalDiscussionRepository implements DiscussionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function replaceForMergeRequest(int $mrId, array $discussions): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('DELETE FROM discussions WHERE merge_request_id = ?', [$mrId]);
            foreach ($discussions as $discussion) {
                $this->connection->executeStatement(
                    'INSERT INTO discussions (merge_request_id, user_id, created_at, resolved) VALUES (?, ?, ?, ?)',
                    [
                        $mrId,
                        $discussion['user_id'],
                        SqliteDateTime::toStorage($discussion['created_at']),
                        $discussion['resolved'] ? 1 : 0,
                    ],
                );
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
