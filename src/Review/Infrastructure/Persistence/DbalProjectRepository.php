<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Persistence;

use App\Review\Domain\Project\ProjectRepository;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed {@see ProjectRepository}.
 */
final class DbalProjectRepository implements ProjectRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function upsert(array $project): void
    {
        $this->connection->executeStatement(
            'INSERT INTO projects (id, path_with_namespace, name, avatar_url) VALUES (?, ?, ?, ?)
             ON CONFLICT(id) DO UPDATE SET
                path_with_namespace = excluded.path_with_namespace,
                name = excluded.name, avatar_url = excluded.avatar_url',
            [
                (int) ($project['id'] ?? 0),
                (string) ($project['path_with_namespace'] ?? ''),
                (string) ($project['name'] ?? ''),
                $project['avatar_url'] ?? null,
            ],
        );
    }

    public function allIds(): array
    {
        $ids = [];
        foreach (SqliteRows::list($this->connection, 'SELECT id FROM projects') as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }
}
