<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\Approval\ApprovalRepository;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use Doctrine\DBAL\Connection;
use Throwable;

/**
 * DBAL-backed {@see ApprovalRepository}. The wipe-then-insert per MR runs in
 * one transaction so a failed sync cannot leave stale approvals behind.
 */
final class DbalApprovalRepository implements ApprovalRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function replaceForMergeRequest(int $mrId, array $approvals): void
    {
        $this->connection->beginTransaction();
        try {
            $this->connection->executeStatement('DELETE FROM approvals WHERE merge_request_id = ?', [$mrId]);
            foreach ($approvals as $approval) {
                $this->connection->executeStatement(
                    'INSERT INTO approvals (merge_request_id, user_id, created_at) VALUES (?, ?, ?)',
                    [$mrId, $approval['user_id'], SqliteDateTime::toStorage($approval['created_at'])],
                );
            }
            $this->connection->commit();
        } catch (Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
