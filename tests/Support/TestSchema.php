<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\AppConfig;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20260820000001;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_is_list;

/**
 * Applies the baseline migration to a test database. The legacy integration
 * tests still drive `Storage\Database`, which no longer builds its schema
 * (that moved to the migration run by the prod entrypoint), so a fresh SQLite
 * file gets its tables from the same migration prod uses.
 */
final class TestSchema
{
    public static function migrate(AppConfig $config): void
    {
        $connection = (new ConnectionFactory($config))->create();
        $migration = new Version20260820000001($connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $params = $query->getParameters();
            if (!array_is_list($params)) {
                throw new RuntimeException('Migration query parameters must be a positional list.');
            }

            $connection->executeStatement($query->getStatement(), $params);
        }

        $connection->close();
    }
}
