<?php

declare(strict_types=1);

namespace App\Metrics;

use function array_map;
use function array_sum;
use function count;
use function intdiv;
use function sort;

final class Aggregator
{
    /**
     * Build a mean/median series from per-bucket raw values.
     *
     * @param list<array{key: string, start: int}> $axis
     * @param array<int, list<int|float>> $rawByBucket
     */
    public static function meanMedian(array $axis, array $rawByBucket): Series
    {
        $keys = array_map(static fn (array $bucket): string => $bucket['key'], $axis);
        $mean = [];
        $median = [];

        foreach ($axis as $index => $_) {
            $values = $rawByBucket[$index] ?? [];
            $mean[] = $values === [] ? null : self::mean($values);
            $median[] = $values === [] ? null : self::median($values);
        }

        return new Series($keys, $mean, $median);
    }

    /**
     * Build a mean/median series where each bucket aggregates every sample in
     * the trailing window ending at that bucket's end. Turns sparse per-event
     * duration data into a continuous trend line (matching how coverage and
     * merged-count already plot rolling windows) instead of isolated spikes on
     * the days events happened.
     *
     * @param list<array{key: string, start: int}> $axis
     * @param list<array{0: int, 1: int|float}> $samples [timestamp, value] pairs
     */
    public static function rollingMeanMedian(array $axis, array $samples, int $windowSeconds): Series
    {
        $keys = array_map(static fn (array $bucket): string => $bucket['key'], $axis);
        $ends = self::bucketEnds($axis);
        $mean = [];
        $median = [];

        foreach ($axis as $index => $_) {
            $end = $ends[$index];
            $values = [];
            foreach ($samples as [$timestamp, $value]) {
                if ($timestamp > $end - $windowSeconds && $timestamp <= $end) {
                    $values[] = $value;
                }
            }
            $mean[] = $values === [] ? null : self::mean($values);
            $median[] = $values === [] ? null : self::median($values);
        }

        return new Series($keys, $mean, $median);
    }

    /**
     * Build a count series where each bucket counts every event in the
     * trailing window ending at that bucket's end.
     *
     * @param list<array{key: string, start: int}> $axis
     * @param list<int> $timestamps
     */
    public static function rollingCounts(array $axis, array $timestamps, int $windowSeconds): Series
    {
        $keys = array_map(static fn (array $bucket): string => $bucket['key'], $axis);
        $ends = self::bucketEnds($axis);
        $values = [];

        foreach ($axis as $index => $_) {
            $end = $ends[$index];
            $count = 0;
            foreach ($timestamps as $timestamp) {
                if ($timestamp > $end - $windowSeconds && $timestamp <= $end) {
                    $count++;
                }
            }
            $values[] = $count;
        }

        return new Series($keys, $values);
    }

    /**
     * Exclusive end of each bucket: the next bucket's start, extrapolated from
     * the bucket step for the last one. Windows end at the bucket END so an
     * event that happened today already counts into today's point.
     *
     * @param list<array{key: string, start: int}> $axis
     *
     * @return list<int>
     */
    private static function bucketEnds(array $axis): array
    {
        $ends = [];
        $total = count($axis);
        foreach ($axis as $index => $bucket) {
            if ($index + 1 < $total) {
                $ends[] = $axis[$index + 1]['start'];
            } else {
                $step = $total > 1 ? $bucket['start'] - $axis[$index - 1]['start'] : 86400;
                $ends[] = $bucket['start'] + $step;
            }
        }

        return $ends;
    }

    /**
     * Build a count series from per-bucket event lists.
     *
     * @param list<array{key: string, start: int}> $axis
     * @param array<int, list<mixed>> $eventsByBucket
     */
    public static function counts(array $axis, array $eventsByBucket): Series
    {
        $keys = array_map(static fn (array $bucket): string => $bucket['key'], $axis);
        $values = [];

        foreach ($axis as $index => $_) {
            $values[] = count($eventsByBucket[$index] ?? []);
        }

        return new Series($keys, $values);
    }

    /**
     * Build a series from a per-bucket-key snapshot map. Keys absent from the
     * map become null (an empty value), which is what coverage uses for days
     * with no MRs opened.
     *
     * @param list<array{key: string, start: int}> $axis
     * @param array<string, int|float|null> $snapshot
     */
    public static function values(array $axis, array $snapshot): Series
    {
        $keys = array_map(static fn (array $bucket): string => $bucket['key'], $axis);
        $values = [];

        foreach ($axis as $bucket) {
            $values[] = $snapshot[$bucket['key']] ?? null;
        }

        return new Series($keys, $values);
    }

    /**
     * @param list<int|float> $values
     */
    public static function mean(array $values): int|float
    {
        return array_sum($values) / count($values);
    }

    /**
     * @param list<int|float> $values
     */
    public static function median(array $values): int|float
    {
        $sorted = $values;
        sort($sorted);
        $count = count($sorted);
        $middle = intdiv($count, 2);

        if ($count % 2 === 1) {
            return $sorted[$middle];
        }

        return ($sorted[$middle - 1] + $sorted[$middle]) / 2;
    }
}
