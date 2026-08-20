<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Key/value persistence on the `sync_state` table: last-sync markers, the sync
 * lock, and the refresh cycle flags. Both storage formats live in the table —
 * the values themselves carry no type — so no timestamp conversion happens
 * here.
 */
final class SyncStateStore
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function get(string $key): null|string
    {
        $value = SqliteRows::value($this->connection, 'SELECT value FROM sync_state WHERE key = ?', [$key]);

        return $value === null ? null : (string) $value;
    }

    public function set(string $key, string $value): void
    {
        $this->connection->executeStatement(
            'INSERT OR REPLACE INTO sync_state (key, value) VALUES (?, ?)',
            [$key, $value],
        );
    }

    /**
     * Stores the value only when the key is absent (the sync lock). True when
     * this call won the race.
     */
    public function insertIfAbsent(string $key, string $value): bool
    {
        return $this->connection->executeStatement(
            'INSERT OR IGNORE INTO sync_state (key, value) VALUES (?, ?)',
            [$key, $value],
        ) === 1;
    }

    public function delete(string $key): void
    {
        $this->connection->executeStatement('DELETE FROM sync_state WHERE key = ?', [$key]);
    }
}
