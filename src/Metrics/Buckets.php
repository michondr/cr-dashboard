<?php

declare(strict_types=1);

namespace App\Metrics;

use DateTimeImmutable;
use DateTimeZone;

use function gmdate;

/**
 * UTC bucket keys. Day buckets are calendar days, week buckets start on Monday,
 * hour buckets are whole UTC hours.
 */
final class Buckets
{
    public const WEEK = 'week';
    public const DAY = 'day';
    public const HOUR = 'hour';

    public static function key(int $epoch, string $granularity): string
    {
        $start = self::bucketStart($epoch, $granularity);

        return $granularity === self::HOUR
            ? gmdate('Y-m-d H:00', $start)
            : gmdate('Y-m-d', $start);
    }

    public static function bucketStart(int $epoch, string $granularity): int
    {
        return match ($granularity) {
            self::WEEK => self::weekStart($epoch),
            self::HOUR => $epoch - ($epoch % 3600),
            default => $epoch - ($epoch % 86400),
        };
    }

    /**
     * @return list<array{key: string, start: int}> Bucket keys and their start
     *                                               epochs covering [now - windowDays, now].
     */
    public static function axis(int $now, string $granularity, int $windowDays): array
    {
        $start = $now - ($windowDays * 86400);
        $cursor = self::bucketStart($start, $granularity);
        $end = self::bucketStart($now, $granularity);
        $buckets = [];

        while ($cursor <= $end) {
            $buckets[] = ['key' => self::key($cursor, $granularity), 'start' => $cursor];
            $cursor = self::next($cursor, $granularity);
        }

        return $buckets;
    }

    private static function weekStart(int $epoch): int
    {
        $date = (new DateTimeImmutable('@' . $epoch))->setTimezone(new DateTimeZone('UTC'));
        $monday = $date->modify('monday this week');

        return (int) $monday->format('U');
    }

    private static function next(int $epoch, string $granularity): int
    {
        return match ($granularity) {
            self::WEEK => $epoch + (7 * 86400),
            self::HOUR => $epoch + 3600,
            default => $epoch + 86400,
        };
    }
}
