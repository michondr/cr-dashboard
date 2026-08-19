<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Refresh\RefreshQueue;
use App\Storage\Database;
use App\Tests\Support\TestAppConfig;
use PHPUnit\Framework\TestCase;

use function sys_get_temp_dir;
use function uniqid;

final class RefreshQueueTest extends TestCase
{
    private AppConfig $config;
    private Database $database;
    private RefreshQueue $queue;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create(
            sys_get_temp_dir() . '/cr-dashboard-refresh-' . uniqid('', true) . '.sqlite',
        );
        $this->database = new Database($this->config);
        $this->queue = new RefreshQueue($this->database);
    }

    public function testRequestCycleQueuesATriggerWhenIdle(): void
    {
        $result = $this->queue->requestCycle(1000, 7);

        self::assertTrue($result['accepted']);
        self::assertSame('queued', $result['reason']);
        self::assertTrue($this->queue->hasPendingRequest());
        self::assertSame(7, $this->queue->pendingUserId());
    }

    public function testRequestCycleMergesIntoAnActiveCycleInsteadOfQueuingASecondOne(): void
    {
        $this->queue->beginCycle(1000, 1);

        $result = $this->queue->requestCycle(1001, 2);

        self::assertTrue($result['accepted']);
        self::assertSame('merged', $result['reason']);
        self::assertFalse($this->queue->hasPendingRequest());
        self::assertSame(2, $this->queue->activeUserId());
    }

    public function testRequestCycleIsRejectedDuringTheCooldownAfterACompletedCycle(): void
    {
        $this->queue->beginCycle(1000, null);
        $this->queue->endCycle(1000);

        $result = $this->queue->requestCycle(1010, null);

        self::assertFalse($result['accepted']);
        self::assertSame('cooldown', $result['reason']);
        self::assertSame(RefreshQueue::COOLDOWN_SECONDS - 10, $result['cooldownRemaining']);

        $after = $this->queue->requestCycle(1000 + RefreshQueue::COOLDOWN_SECONDS, null);
        self::assertTrue($after['accepted']);
    }

    public function testNextQueuedJobOrdersNewMrsFirstThenUserAuthoredThenUnapprovedThenTheRest(): void
    {
        $this->insertUser(1, 'Alice');
        $this->insertUser(2, 'Bob');
        $this->insertMr(101, 1, 100);
        $this->insertMr(102, 2, 200);
        $this->insertMr(103, 2, 300);
        $this->insertMr(104, 2, 400);
        $this->database->execute(
            'INSERT INTO approvals (mr_id, user_id, created_at) VALUES (103, 1, 1)',
        );

        $this->queue->beginCycle(1000, 1);
        $this->queue->enqueue(102, false, 1000);
        $this->queue->enqueue(103, false, 1000);
        $this->queue->enqueue(104, true, 1000);
        $this->queue->enqueue(101, false, 1000);

        // 104 is new -> first, despite lowest updated_at.
        $job = $this->queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(104, $job['mr_id']);
        self::assertTrue($job['is_new']);
        $this->queue->markDone(104);

        // 101 is authored by user 1 -> next.
        $job = $this->queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(101, $job['mr_id']);
        $this->queue->markDone(101);

        // 102 is open and not approved by user 1 -> next, before approved 103.
        $job = $this->queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(102, $job['mr_id']);
        $this->queue->markDone(102);

        // 103, approved by user 1, is last.
        $job = $this->queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(103, $job['mr_id']);
        $this->queue->markDone(103);

        self::assertNull($this->queue->nextQueuedJob(1));
    }

    public function testOrderedQueuedMrIdsMatchesTheNextQueuedJobPopOrderWithoutConsumingTheQueue(): void
    {
        $this->insertUser(1, 'Alice');
        $this->insertUser(2, 'Bob');
        $this->insertMr(101, 1, 100);
        $this->insertMr(102, 2, 200);
        $this->insertMr(103, 2, 300);
        $this->insertMr(104, 2, 400);
        $this->database->execute(
            'INSERT INTO approvals (mr_id, user_id, created_at) VALUES (103, 1, 1)',
        );

        $this->queue->beginCycle(1000, 1);
        $this->queue->enqueue(102, false, 1000);
        $this->queue->enqueue(103, false, 1000);
        $this->queue->enqueue(104, true, 1000);
        $this->queue->enqueue(101, false, 1000);

        self::assertSame([104, 101, 102, 103], $this->queue->orderedQueuedMrIds(1));

        // A snapshot, not a pop: the queue is untouched and still poppable in the same order.
        $job = $this->queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(104, $job['mr_id']);
    }

    public function testStatusReportsTotalAndDoneCounts(): void
    {
        $this->insertUser(1, 'Alice');
        $this->insertMr(101, 1, 100);
        $this->insertMr(102, 1, 200);

        $this->queue->beginCycle(1000, null);
        $this->queue->enqueue(101, false, 1000);
        $this->queue->enqueue(102, false, 1000);
        $this->queue->markDone(101);

        $status = $this->queue->status();
        self::assertTrue($status['active']);
        self::assertSame(2, $status['total']);
        self::assertSame(1, $status['done']);
    }

    private function insertUser(int $id, string $name): void
    {
        $this->database->execute(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (?, ?, ?, NULL)',
            [$id, $name, $name],
        );
    }

    private function insertMr(int $id, int $authorId, int $updatedAt): void
    {
        $this->database->execute(
            'INSERT INTO merge_requests
                (id, iid, project_id, author_id, title, state, created_at, updated_at, web_url)
             VALUES (?, ?, 1, ?, ?, \'opened\', ?, ?, \'\')',
            [$id, $id, $authorId, 'MR ' . $id, $updatedAt, $updatedAt],
        );
    }
}
