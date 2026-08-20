<?php

declare(strict_types=1);

namespace App\Storage;

use App\Config\AppConfig;
use RuntimeException;
use SQLite3;
use SQLite3Result;
use SQLite3Stmt;

use function array_key_first;
use function dirname;
use function is_bool;
use function is_dir;
use function is_float;
use function is_int;
use function is_string;
use function mkdir;
use function str_starts_with;

/**
 * Thin SQLite3 wrapper with WAL mode and a busy timeout so the sync writer and
 * the web readers do not error under concurrent access.
 */
final class Database
{
    private SQLite3 $connection;
    private readonly string $path;

    public function __construct(AppConfig $config)
    {
        $this->path = $this->resolvePath($config->databasePath, $config->projectRoot);

        $directory = dirname($this->path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $this->connection = new SQLite3($this->path);
        $this->connection->enableExceptions(true);
        $this->connection->busyTimeout(5000);
        $this->connection->exec('PRAGMA journal_mode = WAL;');
        $this->connection->exec('PRAGMA foreign_keys = ON;');
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param array<int|string, int|float|string|bool|null> $params
     */
    public function execute(string $sql, array $params = []): void
    {
        $statement = $this->prepare($sql, $params);
        $statement->execute();
        $statement->close();
    }

    /**
     * @param array<int|string, int|float|string|bool|null> $params
     *
     * @return list<array<string, int|float|string|null>>
     */
    public function query(string $sql, array $params = []): array
    {
        $statement = $this->prepare($sql, $params);
        $result = $statement->execute();
        if ($result === false) {
            throw new RuntimeException('SQLite query failed: ' . $this->connection->lastErrorMsg());
        }

        $rows = $this->collectRows($result);
        $result->finalize();
        $statement->close();

        return $rows;
    }

    /**
     * @param array<int|string, int|float|string|bool|null> $params
     */
    public function queryValue(string $sql, array $params = []): null|int|float|string
    {
        $rows = $this->query($sql, $params);
        if ($rows === []) {
            return null;
        }

        $first = $rows[0];
        $key = array_key_first($first);

        return $key === null ? null : ($first[$key] ?? null);
    }

    public function changes(): int
    {
        return $this->connection->changes();
    }

    public function begin(): void
    {
        $this->connection->exec('BEGIN;');
    }

    public function commit(): void
    {
        $this->connection->exec('COMMIT;');
    }

    public function rollback(): void
    {
        $this->connection->exec('ROLLBACK;');
    }

    private function resolvePath(string $path, string $projectRoot): string
    {
        return str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;
    }

    /**
     * @param array<int|string, int|float|string|bool|null> $params
     */
    private function prepare(string $sql, array $params): SQLite3Stmt
    {
        $statement = $this->connection->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('SQLite prepare failed: ' . $this->connection->lastErrorMsg());
        }

        foreach ($params as $key => $value) {
            $type = match (true) {
                $value === null => SQLITE3_NULL,
                is_int($value), is_float($value), is_bool($value) => SQLITE3_INTEGER,
                is_string($value) => SQLITE3_TEXT,
                default => SQLITE3_TEXT,
            };
            $paramKey = is_int($key) ? $key + 1 : $key;
            $statement->bindValue($paramKey, $value, $type);
        }

        return $statement;
    }

    /**
     * @return list<array<string, int|float|string|null>>
     */
    private function collectRows(SQLite3Result $result): array
    {
        $rows = [];
        while (($row = $result->fetchArray(SQLITE3_ASSOC)) !== false) {
            /** @var array<string, int|float|string|null> $row */
            $rows[] = $row;
        }

        return $rows;
    }
}
