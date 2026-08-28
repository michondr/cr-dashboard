<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Review\Application\Refresh\RefreshWorker;
use App\Review\Application\Sync\Synchronizer;
use App\Review\Infrastructure\Persistence\DbalApprovalRepository;
use App\Review\Infrastructure\Persistence\DbalCommitRepository;
use App\Review\Infrastructure\Persistence\DbalDiscussionRepository;
use App\Review\Infrastructure\Persistence\DbalMergeRequestRepository;
use App\Review\Infrastructure\Persistence\DbalPipelineRepository;
use App\Review\Infrastructure\Persistence\DbalProjectRepository;
use App\Review\Infrastructure\Persistence\DbalUserRepository;
use App\Review\Infrastructure\Persistence\RefreshQueueStore;
use App\Review\Infrastructure\Slack\SlackNotifier;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use App\Shared\Infrastructure\Persistence\SyncStateStore;
use App\Tests\Support\FakeGitLabClient;
use App\Tests\Support\FakeHub;
use App\Tests\Support\TestAppConfig;
use App\Tests\Support\TestSchema;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_key_last;
use function array_values;
use function strtotime;
use function sys_get_temp_dir;
use function uniqid;

final class RefreshWorkerTest extends TestCase
{
    private AppConfig $config;
    private Connection $connection;
    private FakeGitLabClient $client;
    private Synchronizer $synchronizer;
    private RefreshQueueStore $queue;
    private FakeHub $hub;
    private RefreshWorker $worker;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create(
            sys_get_temp_dir() . '/cr-dashboard-refresh-worker-' . uniqid('', true) . '.sqlite',
        );
        $this->client = new FakeGitLabClient();
        TestSchema::migrate($this->config);
        $this->connection = (new ConnectionFactory($this->config))->create();
        $this->synchronizer = $this->createSynchronizer();
        $this->queue = new RefreshQueueStore($this->connection);
        $this->hub = new FakeHub();
        $slackNotifier = new SlackNotifier($this->connection, new SyncStateStore($this->connection), $this->config);
        $this->worker = new RefreshWorker(
            $this->queue,
            $this->client,
            $this->synchronizer,
            $slackNotifier,
            $this->hub,
            $this->config,
        );

        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
    }

    public function testIdleTickDoesNothingWithoutAPendingRequest(): void
    {
        self::assertFalse($this->worker->tick(1000));
    }

    public function testACycleListsFetchesAndCompletesEachQueuedMr(): void
    {
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->client->mergeRequests['all'] = [
            $this->mr(101, '2026-08-05T09:00:00+00:00'),
        ];

        $this->queue->requestCycle(1000, null);

        self::assertTrue($this->worker->tick(1000)); // starts cycle: cheap list call
        self::assertTrue($this->queue->isActive());
        self::assertSame(1, $this->client->groupMergeRequestsCalls);

        self::assertTrue($this->worker->tick(1001)); // fetches the one queued MR
        self::assertTrue($this->worker->tick(1002)); // notices the queue is empty, ends the cycle

        self::assertFalse($this->queue->isActive());
        self::assertSame('done', $this->value('SELECT state FROM refresh_queue WHERE mr_id = 101'));

        $doneEvents = array_values(array_filter(
            $this->hub->published,
            static fn (array $event): bool => $event['topic'] === 'refresh' && $event['data']['type'] === 'done',
        ));
        self::assertCount(1, $doneEvents);
        self::assertSame(101, $doneEvents[0]['data']['mr_id']);

        $dataEvents = array_filter(
            $this->hub->published,
            static fn (array $event): bool => $event['topic'] === 'data',
        );
        self::assertCount(1, $dataEvents);
    }

    public function testNewlyDiscoveredMrsAreQueuedFirstAndFlaggedIsNew(): void
    {
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->client->mergeRequests['all'] = [
            $this->mr(101, '2026-08-01T09:00:00+00:00'),
            $this->mr(202, '2026-08-05T09:00:00+00:00'),
        ];

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);

        $job = $this->queue->nextQueuedJob(null);
        self::assertNotNull($job);
        self::assertSame(202, $job['mr_id']);
        self::assertTrue($job['is_new']);
    }

    public function testCachedOpenMrsNotInTheUpdatedListStillJoinTheCycle(): void
    {
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->seedCachedMr(102, 2, updated: '2026-07-01T09:00:00+00:00');
        // Only 101 was updated since the last sync; 102 must still be queued
        // (approvals/discussions can change without bumping updated_at).
        $this->client->mergeRequests['all'] = [
            $this->mr(101, '2026-08-05T09:00:00+00:00'),
        ];
        // 102 joins the cached-ref tier, whose per-MR state check needs a
        // single-MR payload.
        $this->client->mergeRequestByIid[102] = $this->mr(102, '2026-07-01T09:00:00+00:00');

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);

        self::assertSame(
            2,
            (int) $this->value('SELECT COUNT(*) FROM refresh_queue'),
        );

        while ($this->queue->isActive()) {
            $this->worker->tick(1001);
        }

        self::assertSame('done', $this->value('SELECT state FROM refresh_queue WHERE mr_id = 101'));
        self::assertSame('done', $this->value('SELECT state FROM refresh_queue WHERE mr_id = 102'));

        $doneIds = array_column(
            array_values(array_filter(
                $this->hub->published,
                static fn (array $event): bool => $event['topic'] === 'refresh' && $event['data']['type'] === 'done',
            )),
            'data',
        );
        self::assertCount(2, $doneIds);
    }

    public function testStaleCachedMrsAreLeftToTheNightlySync(): void
    {
        $now = (int) strtotime('2026-08-20T12:00:00+00:00');
        $this->seedCachedMr(101, 1, updated: '2026-08-10T09:00:00+00:00');
        // Created >60 days ago: stale, must not be queued by a refresh cycle.
        $this->seedCachedMr(102, 2, updated: '2026-05-01T09:00:00+00:00');
        $this->client->mergeRequests['all'] = [];
        // 101 is a cached-ref job here (the list is empty), so the state
        // check needs a single-MR payload.
        $this->client->mergeRequestByIid[101] = $this->mr(101, '2026-08-10T09:00:00+00:00');

        $this->queue->requestCycle($now, null);
        $this->worker->tick($now);

        $queuedIds = array_column(
            $this->rows('SELECT mr_id FROM refresh_queue'),
            'mr_id',
        );
        self::assertContains(101, $queuedIds);
        self::assertNotContains(102, $queuedIds);
    }

    public function testCycleStartedBroadcastsTheOrderedQueuedMrIds(): void
    {
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->client->mergeRequests['all'] = [
            $this->mr(101, '2026-08-01T09:00:00+00:00'),
            $this->mr(202, '2026-08-05T09:00:00+00:00'),
        ];

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);

        $cycleStarted = array_values(array_filter(
            $this->hub->published,
            static fn (array $event): bool => $event['topic'] === 'refresh'
                && $event['data']['type'] === 'cycle_started',
        ));
        self::assertCount(1, $cycleStarted);
        self::assertSame([202, 101], $cycleStarted[0]['data']['mr_ids']);
    }

    public function testClosedMrsSurfacedByTheListCallAreRemovedFromTheCache(): void
    {
        // Parity with Synchronizer::incremental(): the list call is state=all,
        // so a closed transition is a normal occurrence and must not stay
        // cached or get queued for a sub-resource fetch.
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $closed = $this->mr(101, '2026-08-05T09:00:00+00:00');
        $closed['state'] = 'closed';
        $this->client->mergeRequests['all'] = [$closed];

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM merge_requests'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM refresh_queue'));
    }

    public function testMergedMrsSurfacedByTheListCallLeaveTheBoard(): void
    {
        // An MR merged since the last sync appears in the state=all list with
        // state=merged. Its cached row must be corrected so it stops showing
        // on the board, and it must not be queued for a sub-resource refresh
        // (those are already cached from when it was open). A `changed` event
        // tells the frontend to refetch the row, which now 404s.
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $merged = $this->mr(101, '2026-08-05T09:00:00+00:00');
        $merged['state'] = 'merged';
        $merged['merged_at'] = '2026-08-05T10:00:00+00:00';
        $this->client->mergeRequests['all'] = [$merged];

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);

        self::assertSame(
            'merged',
            $this->value('SELECT state FROM merge_requests WHERE id = 101'),
        );
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM refresh_queue'), 'merged MR is not refreshed');

        $changed = array_values(array_filter(
            $this->hub->published,
            static fn (array $event): bool => $event['topic'] === 'data' && $event['data']['type'] === 'changed',
        ));
        self::assertCount(1, $changed);
        self::assertSame(101, $changed[0]['data']['mr_id']);
    }

    public function testCachedMrMergedBeforeTheListWindowIsCorrectedByTheStateCheck(): void
    {
        // The MR was merged before the last sync, so the updated-since list
        // (updated_after = lastSync - 60) does not return it and its cached
        // row is still "opened". The cached-ref tier's per-MR state check is
        // the only observer of the transition — without it the MR would linger
        // on the board until the nightly reconcile.
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->client->mergeRequests['all'] = [];
        $merged = $this->mr(101, '2026-08-01T09:00:00+00:00');
        $merged['state'] = 'merged';
        $merged['merged_at'] = '2026-08-01T10:00:00+00:00';
        $this->client->mergeRequestByIid[101] = $merged;

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000); // list call: empty, so 101 joins the cached-ref tier
        $this->worker->tick(1001); // state check observes the merge

        self::assertSame('merged', $this->value('SELECT state FROM merge_requests WHERE id = 101'));
        self::assertSame('done', $this->value('SELECT state FROM refresh_queue WHERE mr_id = 101'));
        self::assertSame(1, $this->client->mergeRequestCalls);
        self::assertSame(0, $this->client->subResourceFetches, 'merged MR needs no sub-resource refresh');

        $changed = array_values(array_filter(
            $this->hub->published,
            static fn (array $event): bool => $event['topic'] === 'data' && $event['data']['type'] === 'changed',
        ));
        self::assertCount(1, $changed);
        self::assertSame(101, $changed[0]['data']['mr_id']);
    }

    public function testCachedMrClosedBeforeTheListWindowIsRemovedByTheStateCheck(): void
    {
        // Same gap as the merged variant, for the closed transition: the
        // cached row must not keep showing as "opened" on the board.
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->client->mergeRequests['all'] = [];
        $closed = $this->mr(101, '2026-08-01T09:00:00+00:00');
        $closed['state'] = 'closed';
        $closed['closed_at'] = '2026-08-01T10:00:00+00:00';
        $this->client->mergeRequestByIid[101] = $closed;

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);
        $this->worker->tick(1001);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM merge_requests'));
        self::assertSame(1, $this->client->mergeRequestCalls);
        self::assertSame(0, $this->client->subResourceFetches);
    }

    public function testProgressEventsAreEmittedAfterEachSubResourceFetch(): void
    {
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->client->mergeRequests['all'] = [
            $this->mr(101, '2026-08-05T09:00:00+00:00'),
        ];
        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);
        $this->worker->tick(1001);

        $progress = array_column(
            array_filter(
                $this->hub->published,
                static fn (array $event): bool => $event['topic'] === 'refresh'
                    && $event['data']['type'] === 'progress',
            ),
            'data',
        );

        self::assertNotEmpty($progress);
        self::assertSame(4, $progress[0]['requests_expected']);
        self::assertSame(4, $progress[array_key_last($progress)]['requests_done']);
    }

    private function createSynchronizer(): Synchronizer
    {
        return new Synchronizer(
            $this->client,
            new DbalMergeRequestRepository($this->connection),
            new DbalUserRepository($this->connection),
            new DbalProjectRepository($this->connection),
            new DbalApprovalRepository($this->connection),
            new DbalDiscussionRepository($this->connection),
            new DbalCommitRepository($this->connection),
            new DbalPipelineRepository($this->connection),
            new SyncStateStore($this->connection),
            $this->connection,
            $this->config,
        );
    }

    private function seedCachedMr(int $id, int $authorId, string $updated): void
    {
        $updatedEpoch = (int) strtotime($updated);
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (?, ?, ?, NULL)',
            [$authorId, 'User ' . $authorId, 'user' . $authorId],
        );
        $this->connection->executeStatement(
            'INSERT INTO merge_requests
                (id, iid, project_id, author_id, title, state, created_at, updated_at, web_url)
             VALUES (?, ?, 1, ?, ?, \'opened\', ?, ?, \'\')',
            [
                $id,
                $id,
                $authorId,
                'MR ' . $id,
                SqliteDateTime::toStorage($updatedEpoch),
                SqliteDateTime::toStorage($updatedEpoch),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function mr(int $id, string $updatedIso, int $authorId = 1): array
    {
        return [
            'id' => $id,
            'iid' => $id,
            'project_id' => 1,
            'title' => 'MR ' . $id,
            'description' => '',
            'author' => [
                'id' => $authorId,
                'name' => 'User ' . $authorId,
                'username' => 'user' . $authorId,
                'avatar_url' => null,
            ],
            'state' => 'opened',
            'draft' => false,
            'created_at' => $updatedIso,
            'updated_at' => $updatedIso,
            'merged_at' => null,
            'closed_at' => null,
            'web_url' => '',
            'merge_status' => 'can_be_merged',
            'has_conflicts' => false,
        ];
    }

    private function rowCount(string $sql): int
    {
        return (int) $this->value($sql);
    }

    /**
     * @param list<int|float|string|null> $params
     *
     * @return list<array<string, int|float|string|null>>
     */
    private function rows(string $sql, array $params = []): array
    {
        return SqliteRows::list($this->connection, $sql, $params);
    }

    /**
     * @param list<int|float|string|null> $params
     */
    private function value(string $sql, array $params = []): null|int|float|string
    {
        return SqliteRows::value($this->connection, $sql, $params);
    }
}
