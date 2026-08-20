<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use App\Config\AppConfig;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

use function dirname;
use function is_dir;
use function mkdir;
use function str_starts_with;

/**
 * Opens the Doctrine DBAL connection to the SQLite cache with the same
 * concurrency settings the legacy storage wrapper used: WAL journaling, a
 * busy timeout so the sync writer and the web readers do not error under
 * concurrent access, and foreign keys enabled. Only opens the file — schema
 * is created by migrations, never here.
 */
final class ConnectionFactory
{
    public function __construct(private readonly AppConfig $config)
    {
    }

    public function create(): Connection
    {
        $path = $this->resolvePath($this->config->databasePath, $this->config->projectRoot);

        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $connection = DriverManager::getConnection(['driver' => 'sqlite3', 'path' => $path]);
        $connection->executeStatement('PRAGMA journal_mode = WAL;');
        $connection->executeStatement('PRAGMA busy_timeout = 5000;');
        $connection->executeStatement('PRAGMA foreign_keys = ON;');

        return $connection;
    }

    private function resolvePath(string $path, string $projectRoot): string
    {
        return str_starts_with($path, '/') ? $path : $projectRoot . '/' . $path;
    }
}
