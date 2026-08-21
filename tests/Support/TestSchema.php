<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\AppConfig;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use DoctrineMigrations\Version20260820000001;
use DoctrineMigrations\Version20260820000002;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_is_list;

/**
 * Applies the full migration chain (baseline, then the Doctrine-native
 * conversion) to a test database. The integration tests drive the DBAL
 * repositories directly, so a fresh SQLite file gets its tables from the same
 * migrations the prod entrypoint runs.
 */
final class TestSchema
{
    public static function migrate(AppConfig $config): void
    {
        $connection = (new ConnectionFactory($config))->create();

        $baseline = new Version20260820000001($connection, new NullLogger());
        $baseline->up(new Schema());
        self::executeQueries($connection, $baseline);

        $conversion = new Version20260820000002($connection, new NullLogger());
        $conversion->up(new Schema());
        self::executeQueries($connection, $conversion);

        $connection->close();
    }

    private static function executeQueries(Connection $connection, AbstractMigration $migration): void
    {
        foreach ($migration->getSql() as $query) {
            $params = $query->getParameters();
            if (!array_is_list($params)) {
                throw new RuntimeException('Migration query parameters must be a positional list.');
            }

            $connection->executeStatement($query->getStatement(), $params);
        }
    }
}
