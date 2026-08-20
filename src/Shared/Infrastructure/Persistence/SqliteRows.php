<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

use function is_float;
use function is_int;
use function is_string;

/**
 * Typed reads off a DBAL connection. DBAL returns rows as `array<string,
 * mixed>`, but the repositories cast to scalars; this boundary normalizes every
 * row to the same `array<string, int|float|string|null>` contract the legacy
 * `Database::query()` exposed, so `(int)`/`(string)` casts stay on known scalar
 * types instead of `mixed`.
 */
final class SqliteRows
{
    /**
     * @param list<int|float|string|null> $params
     *
     * @return list<array<string, int|float|string|null>>
     */
    public static function list(Connection $connection, string $sql, array $params = []): array
    {
        $rows = [];
        foreach ($connection->fetchAllAssociative($sql, $params) as $row) {
            $rows[] = self::normalize($row);
        }

        return $rows;
    }

    /**
     * @param list<int|float|string|null> $params
     *
     * @return array<string, int|float|string|null>|null
     */
    public static function first(Connection $connection, string $sql, array $params = []): null|array
    {
        $row = $connection->fetchAssociative($sql, $params);
        if ($row === false) {
            return null;
        }

        return self::normalize($row);
    }

    /**
     * A single scalar column value; SQLite returns numbers as int/float and
     * text as string, so any other type is dropped as if it were null.
     *
     * @param list<int|float|string|null> $params
     */
    public static function value(Connection $connection, string $sql, array $params = []): null|int|float|string
    {
        $value = $connection->fetchOne($sql, $params);
        if ($value === false) {
            return null;
        }
        if ($value === null || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, int|float|string|null>
     */
    private static function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if (is_int($value) || is_float($value) || is_string($value) || $value === null) {
                continue;
            }

            $row[$key] = null;
        }

        return $row;
    }
}
