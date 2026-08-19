<?php

declare(strict_types=1);

namespace App\Refresh;

use App\Storage\Database;

use function usort;

/**
 * Persists the SQLite-backed refresh cycle/queue state (see docs/feature-sse-refresh.md
 * item 2) in `refresh_queue` plus a handful of `sync_state` keys:
 *
 * - `refresh_requested` / `refresh_requested_user`: a trigger waiting for the
 *   worker to pick up (set by `POST /api/refresh`, cleared once a cycle starts).
 * - `refresh_active` / `refresh_active_user`: the cycle currently running.
 *   A trigger arriving mid-cycle updates `refresh_active_user` in place
 *   (merges its ordering into the pending remainder) rather than queuing
 *   a second cycle.
 * - `refresh_cooldown_until`: sync_state key blocking new cycles for 30s
 *   after a cycle completes.
 */
final class RefreshQueue
{
    public const COOLDOWN_SECONDS = 30;

    public function __construct(private readonly Database $database)
    {
    }

    /**
     * @return array{accepted: bool, reason: null|string, cooldownRemaining: int}
     */
    public function requestCycle(int $now, null|int $userId): array
    {
        if ($this->isActive()) {
            $this->setState('refresh_active_user', $userId === null ? '' : (string) $userId);

            return ['accepted' => true, 'reason' => 'merged', 'cooldownRemaining' => 0];
        }

        $cooldownUntil = (int) ($this->getState('refresh_cooldown_until') ?? '0');
        if ($now < $cooldownUntil) {
            return ['accepted' => false, 'reason' => 'cooldown', 'cooldownRemaining' => $cooldownUntil - $now];
        }

        $this->setState('refresh_requested', '1');
        $this->setState('refresh_requested_user', $userId === null ? '' : (string) $userId);

        return ['accepted' => true, 'reason' => 'queued', 'cooldownRemaining' => 0];
    }

    public function hasPendingRequest(): bool
    {
        return $this->getState('refresh_requested') === '1';
    }

    public function pendingUserId(): null|int
    {
        $value = $this->getState('refresh_requested_user');

        return $value === null || $value === '' ? null : (int) $value;
    }

    public function clearPending(): void
    {
        $this->database->execute("DELETE FROM sync_state WHERE key = 'refresh_requested'");
        $this->database->execute("DELETE FROM sync_state WHERE key = 'refresh_requested_user'");
    }

    public function isActive(): bool
    {
        return $this->getState('refresh_active') === '1';
    }

    public function activeUserId(): null|int
    {
        $value = $this->getState('refresh_active_user');

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * Starts a cycle: wipes the previous cycle's rows and marks it active.
     */
    public function beginCycle(int $now, null|int $userId): void
    {
        $this->database->execute('DELETE FROM refresh_queue');
        $this->setState('refresh_active', '1');
        $this->setState('refresh_active_user', $userId === null ? '' : (string) $userId);
        $this->setState('refresh_cycle_started_at', (string) $now);
    }

    public function enqueue(int $mrId, bool $isNew, int $now): void
    {
        $this->database->execute(
            'INSERT OR IGNORE INTO refresh_queue (mr_id, is_new, state, enqueued_at) VALUES (?, ?, \'queued\', ?)',
            [$mrId, $isNew ? 1 : 0, $now],
        );
    }

    /**
     * Next queued job ordered for `$userId` (spec item 2): new MRs first, then
     * for a known user (a) authored by them, (b) open MRs not yet approved by
     * them, (c) MRs they approved, (d) the rest — ties by `updated_at DESC`.
     * With no user: new MRs first, then `updated_at DESC`.
     *
     * @return array{mr_id: int, is_new: bool}|null
     */
    public function nextQueuedJob(null|int $userId): null|array
    {
        // LEFT JOIN: a newly discovered MR (is_new = 1) has no `merge_requests`
        // row yet — its own fetch is what creates it — so it must still be
        // selectable; it always ranks first regardless of the joined columns.
        $rows = $this->database->query(
            "SELECT rq.mr_id AS mr_id, rq.is_new AS is_new,
                    COALESCE(mr.author_id, -1) AS author_id,
                    COALESCE(mr.updated_at, rq.enqueued_at) AS updated_at,
                    EXISTS(SELECT 1 FROM approvals a WHERE a.mr_id = rq.mr_id AND a.user_id = ?) AS approved_by_user
             FROM refresh_queue rq
             LEFT JOIN merge_requests mr ON mr.id = rq.mr_id
             WHERE rq.state = 'queued'",
            [$userId ?? 0],
        );
        if ($rows === []) {
            return null;
        }

        usort($rows, function (array $a, array $b) use ($userId): int {
            $rankA = $this->rank($a, $userId);
            $rankB = $this->rank($b, $userId);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }

            return (int) $b['updated_at'] <=> (int) $a['updated_at'];
        });

        return ['mr_id' => (int) $rows[0]['mr_id'], 'is_new' => (bool) $rows[0]['is_new']];
    }

    public function markFetching(int $mrId): void
    {
        $this->database->execute("UPDATE refresh_queue SET state = 'fetching' WHERE mr_id = ?", [$mrId]);
    }

    public function recordProgress(int $mrId, int $done, int $expected): void
    {
        $this->database->execute(
            'UPDATE refresh_queue SET requests_done = ?, requests_expected = ? WHERE mr_id = ?',
            [$done, $expected, $mrId],
        );
    }

    public function markDone(int $mrId): void
    {
        $this->database->execute("UPDATE refresh_queue SET state = 'done' WHERE mr_id = ?", [$mrId]);
    }

    public function markFailed(int $mrId): void
    {
        $this->database->execute("UPDATE refresh_queue SET state = 'failed' WHERE mr_id = ?", [$mrId]);
    }

    public function totalCount(): int
    {
        return (int) ($this->database->queryValue('SELECT COUNT(*) FROM refresh_queue') ?? '0');
    }

    public function doneCount(): int
    {
        return (int) (
            $this->database->queryValue(
                "SELECT COUNT(*) FROM refresh_queue WHERE state IN ('done', 'failed')",
            ) ?? '0'
        );
    }

    /**
     * Ends the active cycle and starts the 30s cooldown.
     */
    public function endCycle(int $now): void
    {
        $this->database->execute("DELETE FROM sync_state WHERE key = 'refresh_active'");
        $this->database->execute("DELETE FROM sync_state WHERE key = 'refresh_active_user'");
        $this->setState('refresh_cooldown_until', (string) ($now + self::COOLDOWN_SECONDS));
    }

    /**
     * @return array{active: bool, total: int, done: int}
     */
    public function status(): array
    {
        return [
            'active' => $this->isActive(),
            'total' => $this->totalCount(),
            'done' => $this->doneCount(),
        ];
    }

    /**
     * @param array<string, float|int|string|null> $row
     */
    private function rank(array $row, null|int $userId): int
    {
        if ((int) $row['is_new'] === 1) {
            return 0;
        }
        if ($userId === null) {
            return 1;
        }
        if ((int) $row['author_id'] === $userId) {
            return 1;
        }
        if ((int) $row['approved_by_user'] === 0) {
            return 2;
        }

        return 3;
    }

    private function getState(string $key): null|string
    {
        $value = $this->database->queryValue('SELECT value FROM sync_state WHERE key = ?', [$key]);

        return $value === null ? null : (string) $value;
    }

    private function setState(string $key, string $value): void
    {
        $this->database->execute(
            'INSERT OR REPLACE INTO sync_state (key, value) VALUES (?, ?)',
            [$key, $value],
        );
    }
}
