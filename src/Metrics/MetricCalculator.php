<?php

declare(strict_types=1);

namespace App\Metrics;

use App\Config\AppConfig;

use function array_key_exists;
use function count;
use function ksort;
use function min;
use function usort;

/**
 * Pure, deterministic metric computation over a {@see Dataset}. Every value in
 * the dashboard is derived from current state plus event timestamps; no
 * historical snapshots are stored.
 */
final class MetricCalculator
{
    /**
     * @return array<string, MetricResult>
     */
    public function all(Dataset $data, string $granularity, int $now): array
    {
        return [
            'coverage' => $this->coverage($data, $granularity, $now),
            'time_to_review' => $this->timeToReview($data, $granularity, $now),
            'stale_count' => $this->staleCount($data, $granularity, $now),
            'approvals_given' => $this->approvalsGiven($data, $granularity, $now),
            'time_to_first_approve' => $this->timeToFirstApprove($data, $granularity, $now),
            'time_to_merge' => $this->timeToMerge($data, $granularity, $now),
            'first_response_time' => $this->firstResponseTime($data, $granularity, $now),
            'mr_size' => $this->mrSize($data, $granularity, $now),
            'merged_count' => $this->mergedCount($data, $granularity, $now),
        ];
    }

    /**
     * Metric 1 - time to first approve, owned by the first approver, bucketed
     * by the approval date.
     */
    public function timeToFirstApprove(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $index = $this->axisIndex($axis);
        $raw = [];

        foreach ($data->mrs as $mr) {
            $mrId = (int) $mr['id'];
            $createdAt = (int) $mr['created_at'];
            $approvals = $this->approvalsForMr($data, $mrId);
            if ($approvals === []) {
                continue;
            }

            usort($approvals, static fn (array $a, array $b): int => $a['created_at'] <=> $b['created_at']);
            $first = $approvals[0];
            $bucketIndex = $index[Buckets::key($first['created_at'], $granularity)] ?? null;
            if ($bucketIndex === null) {
                continue;
            }

            $raw[$first['user_id']][$bucketIndex][] = $first['created_at'] - $createdAt;
        }

        return $this->meanMedianResult($axis, 'seconds', $raw, $granularity);
    }

    /**
     * Metric 3 - time to review, per reviewer, from MR creation to the
     * reviewer's first activity (approval or discussion thread).
     */
    public function timeToReview(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $index = $this->axisIndex($axis);
        $raw = [];

        foreach ($data->mrs as $mr) {
            $mrId = (int) $mr['id'];
            $createdAt = (int) $mr['created_at'];
            $activities = [];

            foreach ($this->approvalsForMr($data, $mrId) as $approval) {
                $activities = $this->earliest($activities, $approval['user_id'], $approval['created_at']);
            }
            foreach ($this->discussionsForMr($data, $mrId) as $discussion) {
                $activities = $this->earliest($activities, $discussion['user_id'], $discussion['created_at']);
            }

            foreach ($activities as $userId => $activityAt) {
                $bucketIndex = $index[Buckets::key($activityAt, $granularity)] ?? null;
                if ($bucketIndex === null) {
                    continue;
                }

                $raw[$userId][$bucketIndex][] = $activityAt - $createdAt;
            }
        }

        return $this->meanMedianResult($axis, 'seconds', $raw, $granularity);
    }

    /**
     * Metric 4 - coverage percent, the share of MRs opened in the rolling
     * 30-day window that the person reviewed (at any time).
     */
    public function coverage(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $windowSeconds = AppConfig::COVERAGE_WINDOW_DAYS * 86400;
        $reviewedByMr = $this->reviewedMrIdsByUser($data);
        $valuesByUser = [];

        foreach ($axis as $bucket) {
            $windowStart = $bucket['start'] - $windowSeconds;
            $windowEnd = $bucket['start'];
            $openedIds = [];

            foreach ($data->mrs as $mr) {
                $created = (int) $mr['created_at'];
                if ($created > $windowStart && $created <= $windowEnd) {
                    $openedIds[] = (int) $mr['id'];
                }
            }

            $openedCount = count($openedIds);
            foreach ($this->userIds($data) as $userId) {
                if ($openedCount === 0) {
                    $valuesByUser[$userId][$bucket['key']] = null;
                    continue;
                }

                $reviewedCount = 0;
                foreach ($openedIds as $openedId) {
                    if (array_key_exists($openedId, $reviewedByMr[$userId] ?? [])) {
                        $reviewedCount++;
                    }
                }

                $valuesByUser[$userId][$bucket['key']] = ($reviewedCount / $openedCount) * 100;
            }
        }

        return $this->snapshotResult($axis, 'percent', $valuesByUser, $granularity, true);
    }

    /**
     * Metric 5 - stale MR count per author: MRs open on the plotted day and
     * older than the 60-day stale threshold.
     */
    public function staleCount(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $staleSeconds = AppConfig::STALE_DAYS * 86400;
        $valuesByUser = [];

        foreach ($axis as $bucket) {
            $threshold = $bucket['start'] - $staleSeconds;

            foreach ($data->mrs as $mr) {
                if ((string) $mr['state'] !== 'opened') {
                    continue;
                }

                $created = (int) $mr['created_at'];
                if ($created >= $bucket['start'] || $created >= $threshold) {
                    continue;
                }

                $userId = (int) $mr['author_id'];
                $valuesByUser[$userId][$bucket['key']] = ($valuesByUser[$userId][$bucket['key']] ?? 0) + 1;
            }
        }

        return $this->snapshotResult($axis, 'count', $this->fillZeroGaps($valuesByUser, $axis), $granularity);
    }

    /**
     * Metric 6 - time to merge per author, bucketed by the merge date.
     */
    public function timeToMerge(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $index = $this->axisIndex($axis);
        $raw = [];

        foreach ($data->mrs as $mr) {
            if ((string) $mr['state'] !== 'merged') {
                continue;
            }

            $mergedAt = $mr['merged_at'];
            if ($mergedAt === null) {
                continue;
            }

            $bucketIndex = $index[Buckets::key((int) $mergedAt, $granularity)] ?? null;
            if ($bucketIndex === null) {
                continue;
            }

            $raw[(int) $mr['author_id']][$bucketIndex][] = (int) $mergedAt - (int) $mr['created_at'];
        }

        return $this->meanMedianResult($axis, 'seconds', $raw, $granularity);
    }

    /**
     * Metric 8 - MR size per author: sum of additions + deletions over the MR's
     * current commits, bucketed by the MR creation date.
     */
    public function mrSize(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $index = $this->axisIndex($axis);
        $raw = [];

        foreach ($data->mrs as $mr) {
            $mrId = (int) $mr['id'];
            $commits = $this->currentCommitsForMr($data, $mrId);
            if ($commits === []) {
                continue;
            }

            $size = 0;
            foreach ($commits as $commit) {
                $size += $commit['additions'] + $commit['deletions'];
            }

            $bucketIndex = $index[Buckets::key((int) $mr['created_at'], $granularity)] ?? null;
            if ($bucketIndex === null) {
                continue;
            }

            $raw[(int) $mr['author_id']][$bucketIndex][] = $size;
        }

        return $this->meanMedianResult($axis, 'lines', $raw, $granularity);
    }

    /**
     * Metric 9 - approvals given per person, bucketed by the approval date.
     */
    public function approvalsGiven(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $index = $this->axisIndex($axis);
        $eventsByUser = [];

        foreach ($data->approvals as $approval) {
            $bucketIndex = $index[Buckets::key((int) $approval['created_at'], $granularity)] ?? null;
            if ($bucketIndex === null) {
                continue;
            }

            $eventsByUser[(int) $approval['user_id']][$bucketIndex][] = true;
        }

        return $this->countResult($axis, 'count', $eventsByUser, $granularity);
    }

    /**
     * Metric 10 - first response time per person: MR creation to the person's
     * first discussion thread, bucketed by the discussion date.
     */
    public function firstResponseTime(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $index = $this->axisIndex($axis);
        $raw = [];

        foreach ($data->mrs as $mr) {
            $mrId = (int) $mr['id'];
            $createdAt = (int) $mr['created_at'];
            $activities = [];

            foreach ($this->discussionsForMr($data, $mrId) as $discussion) {
                $activities = $this->earliest($activities, $discussion['user_id'], $discussion['created_at']);
            }

            foreach ($activities as $userId => $activityAt) {
                $bucketIndex = $index[Buckets::key($activityAt, $granularity)] ?? null;
                if ($bucketIndex === null) {
                    continue;
                }

                $raw[$userId][$bucketIndex][] = $activityAt - $createdAt;
            }
        }

        return $this->meanMedianResult($axis, 'seconds', $raw, $granularity);
    }

    /**
     * Metric 11 - merged MR count per author: MRs merged in the rolling 30-day
     * window ending at the plotted day.
     */
    public function mergedCount(Dataset $data, string $granularity, int $now): MetricResult
    {
        $axis = Buckets::axis($now, $granularity, AppConfig::WINDOW_DAYS);
        $windowSeconds = AppConfig::MERGED_WINDOW_DAYS * 86400;
        $valuesByUser = [];

        foreach ($axis as $bucket) {
            $windowStart = $bucket['start'] - $windowSeconds;

            foreach ($data->mrs as $mr) {
                if ((string) $mr['state'] !== 'merged') {
                    continue;
                }

                $mergedAt = $mr['merged_at'];
                if ($mergedAt === null) {
                    continue;
                }

                $merged = (int) $mergedAt;
                if ($merged <= $windowStart || $merged > $bucket['start']) {
                    continue;
                }

                $userId = (int) $mr['author_id'];
                $valuesByUser[$userId][$bucket['key']] = ($valuesByUser[$userId][$bucket['key']] ?? 0) + 1;
            }
        }

        return $this->snapshotResult($axis, 'count', $this->fillZeroGaps($valuesByUser, $axis), $granularity);
    }

    /**
     * @param array<int, int> $activities
     *
     * @return array<int, int>
     */
    private function earliest(array $activities, int $userId, int $timestamp): array
    {
        if (!array_key_exists($userId, $activities)) {
            $activities[$userId] = $timestamp;
        } else {
            $activities[$userId] = min($activities[$userId], $timestamp);
        }

        return $activities;
    }

    /**
     * @param list<array{key: string, start: int}> $axis
     *
     * @return array<string, int>
     */
    private function axisIndex(array $axis): array
    {
        $index = [];
        foreach ($axis as $position => $bucket) {
            $index[$bucket['key']] = $position;
        }

        return $index;
    }

    /**
     * @param list<array{key: string, start: int}> $axis
     * @param array<int, array<int, list<int|float>>> $rawByUser
     */
    private function meanMedianResult(array $axis, string $unit, array $rawByUser, string $granularity): MetricResult
    {
        /** @var array<string, Series> $persons */
        $persons = [];
        foreach ($rawByUser as $userId => $byBucket) {
            $series = Aggregator::meanMedian($axis, $byBucket);
            if (!$this->hasData($series)) {
                continue;
            }

            $persons[(string) $userId] = $series;
        }
        ksort($persons);

        return new MetricResult($granularity, $unit, true, $persons);
    }

    /**
     * @param list<array{key: string, start: int}> $axis
     * @param array<int, array<int, list<mixed>>> $eventsByUser
     */
    private function countResult(array $axis, string $unit, array $eventsByUser, string $granularity): MetricResult
    {
        /** @var array<string, Series> $persons */
        $persons = [];
        foreach ($eventsByUser as $userId => $byBucket) {
            $series = Aggregator::counts($axis, $byBucket);
            if (!$this->hasData($series)) {
                continue;
            }

            $persons[(string) $userId] = $series;
        }
        ksort($persons);

        return new MetricResult($granularity, $unit, false, $persons);
    }

    /**
     * @param list<array{key: string, start: int}> $axis
     * @param array<int, array<string, int|float|null>> $valuesByUser
     */
    private function snapshotResult(
        array $axis,
        string $unit,
        array $valuesByUser,
        string $granularity,
        bool $includeZeros = false,
    ): MetricResult {
        /** @var array<string, Series> $persons */
        $persons = [];
        foreach ($valuesByUser as $userId => $byKey) {
            $series = Aggregator::values($axis, $byKey);
            if (!$this->hasData($series, $includeZeros)) {
                continue;
            }

            $persons[(string) $userId] = $series;
        }
        ksort($persons);

        return new MetricResult($granularity, $unit, false, $persons);
    }

    /**
     * @param array<int, array<string, int|float|null>> $valuesByUser
     * @param list<array{key: string, start: int}> $axis
     *
     * @return array<int, array<string, int|float|null>>
     */
    private function fillZeroGaps(array $valuesByUser, array $axis): array
    {
        foreach ($valuesByUser as $userId => $byKey) {
            foreach ($axis as $bucket) {
                $valuesByUser[$userId][$bucket['key']] = $byKey[$bucket['key']] ?? 0;
            }
        }

        return $valuesByUser;
    }

    private function hasData(Series $series, bool $includeZeros = false): bool
    {
        foreach ($series->values as $value) {
            if ($value === null) {
                continue;
            }

            if ($includeZeros || (float) $value !== 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function userIds(Dataset $data): array
    {
        $ids = [];
        foreach ($data->users as $user) {
            $ids[] = (int) $user['id'];
        }

        return $ids;
    }

    /**
     * @return array<int, array<int, true>>
     */
    private function reviewedMrIdsByUser(Dataset $data): array
    {
        $result = [];
        foreach ($data->approvals as $approval) {
            $result[(int) $approval['user_id']][(int) $approval['mr_id']] = true;
        }
        foreach ($data->discussions as $discussion) {
            $result[(int) $discussion['user_id']][(int) $discussion['mr_id']] = true;
        }

        return $result;
    }

    /**
     * @return list<array{user_id: int, created_at: int}>
     */
    private function approvalsForMr(Dataset $data, int $mrId): array
    {
        $result = [];
        foreach ($data->approvals as $approval) {
            if ((int) $approval['mr_id'] !== $mrId) {
                continue;
            }

            $result[] = [
                'user_id' => (int) $approval['user_id'],
                'created_at' => (int) $approval['created_at'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{user_id: int, created_at: int}>
     */
    private function discussionsForMr(Dataset $data, int $mrId): array
    {
        $result = [];
        foreach ($data->discussions as $discussion) {
            if ((int) $discussion['mr_id'] !== $mrId) {
                continue;
            }

            $result[] = [
                'user_id' => (int) $discussion['user_id'],
                'created_at' => (int) $discussion['created_at'],
            ];
        }

        return $result;
    }

    /**
     * @return list<array{additions: int, deletions: int}>
     */
    private function currentCommitsForMr(Dataset $data, int $mrId): array
    {
        $result = [];
        foreach ($data->commits as $commit) {
            if ((int) $commit['mr_id'] !== $mrId) {
                continue;
            }

            if ((int) $commit['current'] !== 1) {
                continue;
            }

            $result[] = [
                'additions' => (int) $commit['additions'],
                'deletions' => (int) $commit['deletions'],
            ];
        }

        return $result;
    }
}
