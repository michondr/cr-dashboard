<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;

use function gmdate;

/**
 * Converts between the app's epoch-second timestamps and the UTC 'Y-m-d H:i:s'
 * wall clock that Doctrine DATETIME columns store in SQLite. UTC is explicit on
 * both sides so a non-UTC host timezone cannot skew cached times.
 */
final class SqliteDateTime
{
    public static function toStorage(int $epoch): string
    {
        return gmdate('Y-m-d H:i:s', $epoch);
    }

    public static function fromStorage(null|int|float|string $value): null|int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string) $value, new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->getTimestamp();
    }
}
