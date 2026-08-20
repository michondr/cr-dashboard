<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\User\UserRepository;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed {@see UserRepository}.
 */
final class DbalUserRepository implements UserRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function upsert(array $user): void
    {
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
                name = excluded.name, username = excluded.username,
                avatar_url = excluded.avatar_url',
            [
                (int) ($user['id'] ?? 0),
                (string) ($user['name'] ?? ''),
                (string) ($user['username'] ?? ''),
                $user['avatar_url'] ?? null,
            ],
        );
    }

    public function allIds(): array
    {
        $ids = [];
        foreach (SqliteRows::list($this->connection, 'SELECT id FROM users') as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function updateRank(int $userId, int $mrCount, int $rankedAt): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET mr_count = ?, ranked_at = ? WHERE id = ?',
            [$mrCount, SqliteDateTime::toStorage($rankedAt), $userId],
        );
    }
}
