<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Storage\Database;
use App\Sync\Synchronizer;
use App\Sync\SyncLockedException;
use App\Tests\Support\FakeGitLabClient;
use App\Tests\Support\TestAppConfig;
use PHPUnit\Framework\TestCase;

use function is_file;
use function strtotime;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SynchronizerTest extends TestCase
{
    private AppConfig $config;
    private Database $database;
    private FakeGitLabClient $client;
    private Synchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create(
            sys_get_temp_dir() . '/cr-dashboard-sync-' . uniqid('', true) . '.sqlite',
        );
        $this->client = new FakeGitLabClient();
        $this->database = new Database($this->config);
        $this->synchronizer = new Synchronizer($this->client, $this->database, $this->config);
    }

    public function testFullSyncStoresMrAndSubResources(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['all'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->approvalsByIid[101] = [
            'approved_by' => [
                [
                    'user' => ['id' => 2, 'name' => 'Bob', 'username' => 'bob', 'avatar_url' => null],
                    'approved_at' => '2026-08-02T09:00:00+00:00',
                ],
            ],
        ];
        $this->client->discussionsByIid[101] = [
            [
                'notes' => [
                    [
                        'system' => false,
                        'author' => ['id' => 2, 'name' => 'Bob', 'username' => 'bob', 'avatar_url' => null],
                        'created_at' => '2026-08-02T10:00:00+00:00',
                    ],
                ],
            ],
        ];
        $this->client->pipelinesByIid[101] = [
            [
                'id' => 10,
                'status' => 'success',
                'created_at' => '2026-08-01T10:00:00+00:00',
                'updated_at' => '2026-08-01T11:00:00+00:00',
            ],
        ];
        $this->client->commitsByIid[101] = [
            ['id' => 'shaA', 'title' => 'first commit', 'committed_date' => '2026-08-01T10:30:00+00:00'],
        ];
        $this->client->commitStatsBySha['shaA'] = ['stats' => ['additions' => 5, 'deletions' => 2]];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $mrs = $this->database->query('SELECT * FROM merge_requests');
        self::assertCount(1, $mrs);
        self::assertSame('opened', $mrs[0]['state']);
        self::assertSame((int) strtotime('2026-08-01T09:00:00+00:00'), $mrs[0]['created_at']);
        self::assertSame(1, $this->rowCount('approvals'));
        self::assertSame(1, $this->rowCount('discussions'));
        self::assertSame(1, $this->rowCount('pipelines'));
        self::assertSame(1, $this->rowCount('commits'));

        $commit = $this->database->query('SELECT * FROM commits WHERE mr_id = 101')[0];
        self::assertSame('shaA', $commit['sha']);
        self::assertSame(5, $commit['additions']);
        self::assertSame(2, $commit['deletions']);
        self::assertSame(1, $commit['current']);

        self::assertSame(1, $this->database->queryValue('SELECT COUNT(*) FROM users WHERE id = 1'));
        self::assertNotNull($this->synchronizer->lastSync());
    }

    public function testSubResourcesAreWipedAndReinserted(): void
    {
        $this->seedSingleMrWithApprovals([2, 3]);
        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));
        self::assertSame(2, $this->rowCount('approvals'));

        $this->seedSingleMrWithApprovals([3]);
        $this->synchronizer->full((int) strtotime('2026-08-10T12:30:00+00:00'));

        $approvals = $this->database->query('SELECT user_id FROM approvals ORDER BY user_id');
        self::assertCount(1, $approvals);
        self::assertSame(3, $approvals[0]['user_id']);
    }

    public function testCommitsAreAppendOnlyAndStatsFetchedOncePerSha(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['all'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->commitStatsBySha['shaA'] = ['stats' => ['additions' => 10, 'deletions' => 1]];
        $this->client->commitStatsBySha['shaB'] = ['stats' => ['additions' => 20, 'deletions' => 2]];

        $this->client->commitsByIid[101] = [
            ['id' => 'shaA', 'title' => 'a', 'committed_date' => '2026-08-01T10:30:00+00:00'],
        ];
        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));
        self::assertSame(1, $this->rowCount('commits'));
        self::assertSame(1, $this->client->commitStatsCalls);

        // A commit is added.
        $this->client->commitsByIid[101] = [
            ['id' => 'shaA', 'title' => 'a', 'committed_date' => '2026-08-01T10:30:00+00:00'],
            ['id' => 'shaB', 'title' => 'b', 'committed_date' => '2026-08-02T10:30:00+00:00'],
        ];
        $this->synchronizer->full((int) strtotime('2026-08-10T13:00:00+00:00'));

        self::assertSame(2, $this->rowCount('commits'));
        self::assertSame(2, $this->client->commitStatsCalls);
        $shas = $this->database->query('SELECT sha, current FROM commits ORDER BY sha');
        self::assertSame(['shaA' => 1, 'shaB' => 1], $this->currentFlags($shas));

        // shaA is force-pushed away.
        $this->client->commitsByIid[101] = [
            ['id' => 'shaB', 'title' => 'b', 'committed_date' => '2026-08-02T10:30:00+00:00'],
        ];
        $this->synchronizer->full((int) strtotime('2026-08-10T14:00:00+00:00'));

        $shas = $this->database->query('SELECT sha, current FROM commits ORDER BY sha');
        self::assertSame(['shaA' => 0, 'shaB' => 1], $this->currentFlags($shas));
        self::assertSame(2, $this->client->commitStatsCalls, 'stats are never re-fetched for known shas');
    }

    public function testDuplicateCommitShasDoNotCrashAndStatsFetchedOnce(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['all'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->commitStatsBySha['shaA'] = ['stats' => ['additions' => 7, 'deletions' => 3]];
        // GitLab can return the same sha more than once (pagination overlap,
        // merge history); a plain INSERT would hit the UNIQUE (mr_id, sha)
        // constraint on the second occurrence.
        $this->client->commitsByIid[101] = [
            ['id' => 'shaA', 'title' => 'a', 'committed_date' => '2026-08-01T10:30:00+00:00'],
            ['id' => 'shaA', 'title' => 'a', 'committed_date' => '2026-08-01T10:30:00+00:00'],
        ];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $commits = $this->database->query('SELECT sha, additions, deletions, current FROM commits WHERE mr_id = 101');
        self::assertCount(1, $commits, 'duplicate sha is stored once');
        self::assertSame(7, $commits[0]['additions']);
        self::assertSame(3, $commits[0]['deletions']);
        self::assertSame(1, $commits[0]['current']);
        self::assertSame(1, $this->client->commitStatsCalls, 'stats fetched once despite the duplicate');
    }

    public function testFullSyncFetchesStaleOpenMrsRegardlessOfAge(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        // Open MR untouched for far longer than the retention window; open MRs
        // are never pruned, so the windowed backfill must still fetch it.
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, 'opened', '2026-01-01T09:00:00+00:00'),
        ];
        $this->client->mergeRequests['all'] = [];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $mrs = $this->database->query('SELECT id, state FROM merge_requests');
        self::assertCount(1, $mrs);
        self::assertSame(101, $mrs[0]['id']);
        self::assertSame('opened', $mrs[0]['state']);
    }

    public function testFullSyncBoundsRecentQueryToRetentionWindow(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['all'] = [];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $allQuery = null;
        foreach ($this->client->mergeRequestQueries as $query) {
            if (($query['state'] ?? null) === 'all') {
                $allQuery = $query;
                break;
            }
        }
        self::assertNotNull($allQuery, 'full() issues a state=all query bounded by updated_after');
        self::assertArrayHasKey('updated_after', $allQuery);
        $updatedAfter = $allQuery['updated_after'];
        self::assertIsString($updatedAfter);
        // retentionDays defaults to 90: 2026-08-10 minus 90 days = 2026-05-12.
        self::assertStringStartsWith('2026-05-12T', $updatedAfter);
    }

    public function testIncrementalRePollsRunningPipelines(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['all'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->pipelinesByIid[101] = [
            [
                'id' => 10,
                'status' => 'running',
                'created_at' => '2026-08-01T10:00:00+00:00',
                'updated_at' => '2026-08-01T11:00:00+00:00',
            ],
        ];
        $this->client->jobsByPipeline[10] = [
            ['id' => 1, 'status' => 'running'],
        ];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));
        self::assertSame(1, $this->client->jobsCalls);

        // No MR changed, but the pipeline is still running: incremental re-polls it.
        $this->client->mergeRequests['all'] = [];
        $this->client->jobsByPipeline[10] = [
            ['id' => 1, 'status' => 'failed'],
        ];
        $this->synchronizer->incremental((int) strtotime('2026-08-10T12:15:00+00:00'));

        self::assertSame(2, $this->client->jobsCalls);
        $job = $this->database->query('SELECT status FROM jobs WHERE id = 1')[0];
        self::assertSame('failed', $job['status']);
    }

    public function testIncrementalQueriesUpdatedAfterLastSync(): void
    {
        $this->seedSingleMrWithApprovals([]);
        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $this->client->mergeRequests['all'] = [];
        $this->synchronizer->incremental((int) strtotime('2026-08-10T12:15:00+00:00'));

        // full() issues two MR queries (opened, then all+updated_after); the
        // incremental query is therefore the third recorded query.
        $lastQuery = $this->client->mergeRequestQueries[2];
        self::assertArrayHasKey('updated_after', $lastQuery);
        $updatedAfter = $lastQuery['updated_after'];
        self::assertIsString($updatedAfter);
        self::assertStringStartsWith('2026-08-10T', $updatedAfter);
    }

    public function testSyncLockBlocksConcurrentSync(): void
    {
        $this->database->execute(
            "INSERT INTO sync_state (key, value) VALUES ('sync_lock', ?)",
            [(string) strtotime('2026-08-10T12:00:00+00:00')],
        );

        $this->expectException(SyncLockedException::class);

        $this->synchronizer->full((int) strtotime('2026-08-10T12:01:00+00:00'));
    }

    public function testStaleSyncLockCanBeTakenOver(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');
        $this->database->execute(
            "INSERT INTO sync_state (key, value) VALUES ('sync_lock', ?)",
            [(string) ($now - 3600)],
        );

        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['all'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];

        $this->synchronizer->full($now);

        self::assertSame(1, $this->rowCount('merge_requests'));
        self::assertNull($this->database->queryValue("SELECT value FROM sync_state WHERE key = 'sync_lock'"));
    }

    public function testProjectAllowlistRestrictsSyncedMrs(): void
    {
        $this->config = TestAppConfig::create($this->config->databasePath, ['gitlabProjects' => ['group/proj']]);
        $this->synchronizer = new Synchronizer($this->client, $this->database, $this->config);

        $allowedMr = $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00', null, 1);
        $blockedMr = $this->mr(202, 'opened', '2026-08-02T09:00:00+00:00', null, 1);
        $blockedMr['project_id'] = 2;
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
            ['id' => 2, 'path_with_namespace' => 'group/other'],
        ];
        $this->client->mergeRequests['all'] = [$allowedMr, $blockedMr];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $stored = $this->database->query('SELECT iid FROM merge_requests');
        self::assertCount(1, $stored);
        self::assertSame(101, $stored[0]['iid']);
    }

    protected function tearDown(): void
    {
        foreach (
            [
            $this->config->databasePath,
            $this->config->databasePath . '-wal',
            $this->config->databasePath . '-shm',
            ] as $path
        ) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param list<int> $approverIds
     */
    private function seedSingleMrWithApprovals(array $approverIds): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['all'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $approvedBy = [];
        foreach ($approverIds as $id) {
            $approvedBy[] = [
                'user' => ['id' => $id, 'name' => 'User ' . $id, 'username' => 'user' . $id, 'avatar_url' => null],
                'approved_at' => '2026-08-02T09:00:00+00:00',
            ];
        }
        $this->client->approvalsByIid[101] = ['approved_by' => $approvedBy];
    }

    /**
     * @return array<string, mixed>
     */
    private function mr(
        int $id,
        string $state,
        string $createdIso,
        null|string $mergedIso = null,
        int $authorId = 1,
    ): array {
        return [
            'id' => $id,
            'iid' => $id,
            'project_id' => 1,
            'title' => 'MR ' . $id,
            'description' => 'description',
            'author' => [
                'id' => $authorId,
                'name' => 'User ' . $authorId,
                'username' => 'user' . $authorId,
                'avatar_url' => null,
            ],
            'state' => $state,
            'draft' => false,
            'created_at' => $createdIso,
            'merged_at' => $mergedIso,
            'closed_at' => null,
            'updated_at' => $createdIso,
            'web_url' => 'https://gitlab.example.test/group/proj/-/merge_requests/' . $id,
        ];
    }

    private function rowCount(string $table): int
    {
        return (int) $this->database->queryValue('SELECT COUNT(*) FROM ' . $table);
    }

    /**
     * @param list<array<string, int|float|string|null>> $rows
     *
     * @return array<string, int>
     */
    private function currentFlags(array $rows): array
    {
        $flags = [];
        foreach ($rows as $row) {
            $flags[(string) $row['sha']] = (int) $row['current'];
        }

        return $flags;
    }
}
