<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Refresh\RefreshQueue;
use App\Refresh\RefreshWorker;
use App\Storage\Database;
use App\Sync\SlackNotifier;
use App\Sync\Synchronizer;
use App\Tests\Support\FakeGitLabClient;
use App\Tests\Support\FakeHub;
use App\Tests\Support\TestAppConfig;
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
    private Database $database;
    private FakeGitLabClient $client;
    private Synchronizer $synchronizer;
    private RefreshQueue $queue;
    private FakeHub $hub;
    private RefreshWorker $worker;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create(
            sys_get_temp_dir() . '/cr-dashboard-refresh-worker-' . uniqid('', true) . '.sqlite',
        );
        $this->client = new FakeGitLabClient();
        $this->database = new Database($this->config);
        $this->synchronizer = new Synchronizer($this->client, $this->database, $this->config);
        $this->queue = new RefreshQueue($this->database);
        $this->hub = new FakeHub();
        $slackNotifier = new SlackNotifier($this->database, $this->config);
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
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, '2026-08-05T09:00:00+00:00'),
        ];

        $this->queue->requestCycle(1000, null);

        self::assertTrue($this->worker->tick(1000)); // starts cycle: cheap list call
        self::assertTrue($this->queue->isActive());
        self::assertSame(1, $this->client->groupMergeRequestsCalls);

        self::assertTrue($this->worker->tick(1001)); // fetches the one queued MR
        self::assertTrue($this->worker->tick(1002)); // notices the queue is empty, ends the cycle

        self::assertFalse($this->queue->isActive());
        self::assertSame('done', $this->database->queryValue('SELECT state FROM refresh_queue WHERE mr_id = 101'));

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
        $this->client->mergeRequests['opened'] = [
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

    public function testClosedMrsSurfacedByTheListCallAreRemovedFromTheCache(): void
    {
        // Defensive parity with Synchronizer::incremental(): the list call is
        // filtered to state=opened so this is a rare edge (a transition right
        // at the query boundary), but if GitLab does surface one, it must not
        // stay cached or get queued for a sub-resource fetch.
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $closed = $this->mr(101, '2026-08-05T09:00:00+00:00');
        $closed['state'] = 'closed';
        $this->client->mergeRequests['opened'] = [$closed];

        $this->queue->requestCycle(1000, null);
        $this->worker->tick(1000);

        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM merge_requests'));
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM refresh_queue'));
    }

    public function testProgressEventsAreEmittedAfterEachSubResourceFetch(): void
    {
        $this->seedCachedMr(101, 1, updated: '2026-08-01T09:00:00+00:00');
        $this->client->mergeRequests['opened'] = [
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

    private function seedCachedMr(int $id, int $authorId, string $updated): void
    {
        $this->database->execute(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (?, ?, ?, NULL)',
            [$authorId, 'User ' . $authorId, 'user' . $authorId],
        );
        $this->database->execute(
            'INSERT INTO merge_requests
                (id, iid, project_id, author_id, title, state, created_at, updated_at, web_url)
             VALUES (?, ?, 1, ?, ?, \'opened\', ?, ?, \'\')',
            [$id, $id, $authorId, 'MR ' . $id, (int) strtotime($updated), (int) strtotime($updated)],
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
        return (int) $this->database->queryValue($sql);
    }
}
