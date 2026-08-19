<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Metrics\Buckets;
use App\Metrics\Dataset;
use App\Metrics\MetricCalculator;
use App\Metrics\MetricResult;
use App\Metrics\Series;
use PHPUnit\Framework\TestCase;

use function array_search;
use function strtotime;

final class MetricCalculatorTest extends TestCase
{
    private const DAY = 86400;

    private MetricCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new MetricCalculator();
    }

    public function testAllMetricsFromOneDataset(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');

        $dataset = new Dataset(
            [
                ['id' => 1, 'name' => 'Alice', 'username' => 'alice', 'avatar_url' => null],
                ['id' => 2, 'name' => 'Bob', 'username' => 'bob', 'avatar_url' => null],
                ['id' => 3, 'name' => 'Cara', 'username' => 'cara', 'avatar_url' => null],
            ],
            [
                $this->mr(101, 1, 'opened', $now - (10 * self::DAY), null, null),
                $this->mr(102, 2, 'merged', $now - (20 * self::DAY), $now - (15 * self::DAY), null),
                $this->mr(103, 1, 'opened', $now - (30 * self::DAY), null, null),
                $this->mr(104, 1, 'opened', $now - (61 * self::DAY), null, null),
            ],
            [
                ['mr_id' => 101, 'user_id' => 2, 'created_at' => $now - (8 * self::DAY)],
                ['mr_id' => 101, 'user_id' => 3, 'created_at' => $now - (6 * self::DAY)],
                ['mr_id' => 102, 'user_id' => 1, 'created_at' => $now - (18 * self::DAY)],
                ['mr_id' => 102, 'user_id' => 2, 'created_at' => $now - (17 * self::DAY)],
            ],
            [
                ['mr_id' => 101, 'user_id' => 2, 'created_at' => $now - (9 * self::DAY)],
            ],
            [
                ['mr_id' => 103, 'sha' => 'sha1', 'current' => 1, 'additions' => 30, 'deletions' => 10],
                ['mr_id' => 103, 'sha' => 'sha2', 'current' => 1, 'additions' => 5, 'deletions' => 5],
            ],
            [],
            [],
        );

        $metrics = $this->calculator->all($dataset, Buckets::DAY, $now);

        $first = $metrics['time_to_first_approve'];
        self::assertSame('seconds', $first->unit);
        // MR 101: first approver Bob (day 8), MR 102: first approver Alice (day 18).
        self::assertSame(2 * self::DAY, $this->valueAt($first, 2, $this->key($now - (8 * self::DAY))));
        self::assertSame(2 * self::DAY, $this->valueAt($first, 1, $this->key($now - (18 * self::DAY))));

        $review = $metrics['time_to_review'];
        // Bob's earliest activity on MR 101 is the discussion (day 9) -> 1 day.
        self::assertSame(1 * self::DAY, $this->valueAt($review, 2, $this->key($now - (9 * self::DAY))));
        self::assertSame(4 * self::DAY, $this->valueAt($review, 3, $this->key($now - (6 * self::DAY))));
        self::assertSame(2 * self::DAY, $this->valueAt($review, 1, $this->key($now - (18 * self::DAY))));

        $coverage = $metrics['coverage'];
        // Opened in the rolling 30-day window ending today: MRs 101, 102, 103.
        $today = $this->key($now);
        self::assertEqualsWithDelta(66.67, $this->valueAt($coverage, 2, $today), 0.01);
        self::assertEqualsWithDelta(33.33, $this->valueAt($coverage, 1, $today), 0.01);

        $stale = $metrics['stale_count'];
        // Only MR 104 (created 61 days ago) is stale today, authored by Alice.
        self::assertSame(1, $this->valueAt($stale, 1, $today));
        self::assertArrayNotHasKey('2', $stale->persons);

        $merge = $metrics['time_to_merge'];
        self::assertSame(5 * self::DAY, $this->valueAt($merge, 2, $this->key($now - (15 * self::DAY))));

        $size = $metrics['mr_size'];
        self::assertEquals(50, $this->valueAt($size, 1, $this->key($now - (30 * self::DAY))));

        $given = $metrics['approvals_given'];
        self::assertSame(1, $this->valueAt($given, 2, $this->key($now - (17 * self::DAY))));
        self::assertSame(1, $this->valueAt($given, 1, $this->key($now - (18 * self::DAY))));
        self::assertSame(1, $this->valueAt($given, 2, $this->key($now - (8 * self::DAY))));

        $response = $metrics['first_response_time'];
        self::assertSame(1 * self::DAY, $this->valueAt($response, 2, $this->key($now - (9 * self::DAY))));

        $mergedCount = $metrics['merged_count'];
        self::assertSame(1, $this->valueAt($mergedCount, 2, $today));
    }

    public function testDurationMetricsCarryMeanAndMedianSeries(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');
        $dataset = new Dataset(
            [],
            [$this->mr(101, 1, 'opened', $now - (10 * self::DAY), null, null)],
            [
                ['mr_id' => 101, 'user_id' => 2, 'created_at' => $now - (8 * self::DAY)],
                ['mr_id' => 101, 'user_id' => 3, 'created_at' => $now - (6 * self::DAY)],
            ],
            [],
            [],
            [],
            [],
        );

        $result = $this->calculator->timeToFirstApprove($dataset, Buckets::DAY, $now);
        $api = $result->toApiArray();
        $persons = $api['persons'];
        self::assertArrayHasKey('2', $persons);
        $bobSeries = $persons['2'];
        self::assertIsArray($bobSeries);
        self::assertArrayHasKey('mean', $bobSeries);
        self::assertArrayHasKey('median', $bobSeries);
    }

    /**
     * @return array<string, int|float|string|null>
     */
    private function mr(
        int $id,
        int $authorId,
        string $state,
        int $createdAt,
        null|int $mergedAt,
        null|int $closedAt,
    ): array {
        return [
            'id' => $id,
            'iid' => $id,
            'project_id' => 1,
            'title' => 'MR ' . $id,
            'author_id' => $authorId,
            'state' => $state,
            'draft' => 0,
            'created_at' => $createdAt,
            'merged_at' => $mergedAt,
            'closed_at' => $closedAt,
        ];
    }

    private function key(int $epoch): string
    {
        return Buckets::key($epoch, Buckets::DAY);
    }

    private function valueAt(MetricResult $result, int $userId, string $bucketKey): null|int|float
    {
        /** @var Series|null $series */
        $series = $result->persons[(string) $userId] ?? null;
        if (!$series instanceof Series) {
            return null;
        }

        $index = array_search($bucketKey, $series->buckets, true);

        return $index === false ? null : $series->values[$index];
    }
}
