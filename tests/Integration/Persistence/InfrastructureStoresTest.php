<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Review\Infrastructure\Persistence\RefreshQueueStore;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use App\Shared\Infrastructure\Persistence\SyncStateStore;
use App\Tests\Support\TestAppConfig;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

use function dirname;
use function gmdate;
use function sys_get_temp_dir;
use function uniqid;

use const CASE_LOWER;

/**
 * The `sync_state` / `refresh_queue` tables have no domain entity (they are
 * infrastructure, not aggregates), so the baseline migration will carry their
 * DDL by hand. This test mirrors that: the domain tables come from the entity
 * metadata, the two infra tables from hand-written DDL.
 */
final class InfrastructureStoresTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $config = TestAppConfig::create(
            sys_get_temp_dir() . '/cr-dashboard-stores-' . uniqid('', true) . '.sqlite',
        );
        $this->connection = (new ConnectionFactory($config))->create();

        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Review/Domain'], true);
        $ormConfig->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfig->setProxyDir(sys_get_temp_dir());
        $ormConfig->setProxyNamespace('App\\SystemTests\\Proxies');
        $em = new EntityManager($this->connection, $ormConfig);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        $this->connection->executeStatement(
            'CREATE TABLE sync_state (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )',
        );
        $this->connection->executeStatement(
            'CREATE TABLE refresh_queue (
                mr_id INTEGER PRIMARY KEY,
                is_new INTEGER NOT NULL DEFAULT 0,
                state TEXT NOT NULL DEFAULT \'queued\',
                requests_done INTEGER NOT NULL DEFAULT 0,
                requests_expected INTEGER NOT NULL DEFAULT 4,
                enqueued_at INTEGER NOT NULL
            )',
        );
    }

    public function testSyncStateStoreSetsGetsAndDeletes(): void
    {
        $store = new SyncStateStore($this->connection);

        self::assertNull($store->get('last_sync_at'));
        $store->set('last_sync_at', '12345');
        self::assertSame('12345', $store->get('last_sync_at'));
        $store->delete('last_sync_at');
        self::assertNull($store->get('last_sync_at'));
    }

    public function testSyncStateStoreInsertIfAbsentWinsOnlyTheFirstRace(): void
    {
        $store = new SyncStateStore($this->connection);

        self::assertTrue($store->insertIfAbsent('sync_lock', '1'));
        self::assertFalse($store->insertIfAbsent('sync_lock', '2'));
        self::assertSame('1', $store->get('sync_lock'));
    }

    public function testRequestCycleQueuesATriggerWhenIdle(): void
    {
        $queue = $this->queue();

        $result = $queue->requestCycle(1000, 7);

        self::assertTrue($result['accepted']);
        self::assertSame('queued', $result['reason']);
        self::assertTrue($queue->hasPendingRequest());
        self::assertSame(7, $queue->pendingUserId());
    }

    public function testRequestCycleMergesIntoAnActiveCycleInsteadOfQueuingASecondOne(): void
    {
        $queue = $this->queue();
        $queue->beginCycle(1000, 1);

        $result = $queue->requestCycle(1001, 2);

        self::assertTrue($result['accepted']);
        self::assertSame('merged', $result['reason']);
        self::assertFalse($queue->hasPendingRequest());
        self::assertSame(2, $queue->activeUserId());
    }

    public function testNextQueuedJobOrdersNewMrsFirstThenUserAuthoredThenUnapprovedThenTheRest(): void
    {
        $queue = $this->queue();
        $this->insertUser(1, 'Alice');
        $this->insertUser(2, 'Bob');
        $this->insertMr(101, 1, 100);
        $this->insertMr(102, 2, 200);
        $this->insertMr(103, 2, 300);
        $this->insertMr(104, 2, 400);
        $this->connection->executeStatement(
            'INSERT INTO approvals (merge_request_id, user_id, created_at) VALUES (103, 1, \'1970-01-01 00:00:01\')',
        );

        $queue->beginCycle(1000, 1);
        $queue->enqueue(102, false, 1000);
        $queue->enqueue(103, false, 1000);
        $queue->enqueue(104, true, 1000);
        $queue->enqueue(101, false, 1000);

        // 104 is new -> first, despite lowest updated_at.
        $job = $queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(104, $job['mr_id']);
        self::assertTrue($job['is_new']);
        $queue->markDone(104);

        // 101 is authored by user 1 -> next.
        $job = $queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(101, $job['mr_id']);
        $queue->markDone(101);

        // 102 is open and not approved by user 1 -> next, before approved 103.
        $job = $queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(102, $job['mr_id']);
        $queue->markDone(102);

        // 103, approved by user 1, is last.
        $job = $queue->nextQueuedJob(1);
        self::assertNotNull($job);
        self::assertSame(103, $job['mr_id']);
        $queue->markDone(103);

        self::assertNull($queue->nextQueuedJob(1));
    }

    public function testStatusReportsTotalAndDoneCounts(): void
    {
        $queue = $this->queue();
        $this->insertUser(1, 'Alice');
        $this->insertMr(101, 1, 100);
        $this->insertMr(102, 1, 200);

        $queue->beginCycle(1000, null);
        $queue->enqueue(101, false, 1000);
        $queue->enqueue(102, false, 1000);
        $queue->markDone(101);

        $status = $queue->status();
        self::assertTrue($status['active']);
        self::assertSame(2, $status['total']);
        self::assertSame(1, $status['done']);

        $queue->endCycle(1000);
        self::assertFalse($queue->isActive());
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    private function queue(): RefreshQueueStore
    {
        return new RefreshQueueStore($this->connection);
    }

    private function insertUser(int $id, string $name): void
    {
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (?, ?, ?, NULL)',
            [$id, $name, $name],
        );
    }

    private function insertMr(int $id, int $authorId, int $updatedAt): void
    {
        $stamp = gmdate('Y-m-d H:i:s', $updatedAt);
        $this->connection->executeStatement(
            'INSERT INTO merge_requests
                (id, iid, project_id, author_id, title, state, created_at, updated_at, web_url)
             VALUES (?, ?, 1, ?, ?, \'opened\', ?, ?, \'\')',
            [$id, $id, $authorId, 'MR ' . $id, $stamp, $stamp],
        );
    }
}
