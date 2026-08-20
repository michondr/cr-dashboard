<?php

declare(strict_types=1);

namespace App\Review\Domain\Discussion;

/**
 * Persistence boundary for the `discussions` cache table (per-table aggregate).
 * One row per discussion thread; re-inserted wholesale per MR on every sync,
 * so the wipe-then-insert lives here, transactionally.
 */
interface DiscussionRepository
{
    /**
     * @param list<array{user_id: int, created_at: int, resolved: bool}> $discussions
     */
    public function replaceForMergeRequest(int $mrId, array $discussions): void;
}
