<?php

declare(strict_types=1);

namespace App\Review\Domain\Approval;

/**
 * Persistence boundary for the `approvals` cache table (per-table aggregate).
 * Approvals are re-inserted wholesale per MR on every sync; the wipe-then-
 * insert order lives here so a failed write cannot leave stale rows behind.
 */
interface ApprovalRepository
{
    /**
     * @param list<array{user_id: int, created_at: int}> $approvals
     */
    public function replaceForMergeRequest(int $mrId, array $approvals): void;
}
