<?php

declare(strict_types=1);

namespace App\ReadModel;

use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use Doctrine\DBAL\Connection;

/**
 * DBAL-backed {@see DatasetRepository}. Timestamp columns stored as Doctrine
 * DATETIME text are decoded back to epoch seconds here, once, so the metric
 * functions and the API builder can keep working in the app's epoch domain.
 */
final class DbalDatasetRepository implements DatasetRepository
{
    /** Timestamp columns per table that need DATETIME→epoch decoding. */
    private const TIMESTAMP_COLUMNS = [
        'users' => ['ranked_at'],
        'merge_requests' => ['created_at', 'merged_at', 'closed_at', 'updated_at'],
        'approvals' => ['created_at'],
        'discussions' => ['created_at'],
        'commits' => ['committed_date'],
        'pipelines' => ['created_at', 'updated_at'],
        'jobs' => [],
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function load(): Dataset
    {
        return new Dataset(
            $this->loadTable('users', 'SELECT * FROM users ORDER BY mr_count DESC, name ASC'),
            $this->loadTable('merge_requests', 'SELECT * FROM merge_requests'),
            $this->loadTable('approvals', 'SELECT * FROM approvals'),
            $this->loadTable('discussions', 'SELECT * FROM discussions'),
            $this->loadTable('commits', 'SELECT * FROM commits'),
            $this->loadTable('pipelines', 'SELECT * FROM pipelines'),
            $this->loadTable('jobs', 'SELECT * FROM jobs'),
        );
    }

    public function projectInfos(): array
    {
        $rows = SqliteRows::list($this->connection, 'SELECT id, path_with_namespace, name, avatar_url FROM projects');
        $infos = [];
        foreach ($rows as $row) {
            $infos[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'path_with_namespace' => (string) $row['path_with_namespace'],
                'name' => (string) $row['name'],
                'avatar_url' => $row['avatar_url'] === null ? null : (string) $row['avatar_url'],
            ];
        }

        return $infos;
    }

    /**
     * @return list<array<string, int|float|string|null>>
     */
    private function loadTable(string $table, string $sql): array
    {
        $rows = SqliteRows::list($this->connection, $sql);
        $columns = self::TIMESTAMP_COLUMNS[$table];
        if ($columns === []) {
            return $rows;
        }

        $out = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $row[$column] = SqliteDateTime::fromStorage($row[$column]);
            }
            $out[] = $row;
        }

        return $out;
    }
}
