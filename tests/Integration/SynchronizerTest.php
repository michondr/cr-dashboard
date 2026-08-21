<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Review\Application\Sync\Synchronizer;
use App\Review\Application\Sync\SyncLockedException;
use App\Review\Infrastructure\GitLab\GitLabException;
use App\Review\Infrastructure\Persistence\DbalApprovalRepository;
use App\Review\Infrastructure\Persistence\DbalCommitRepository;
use App\Review\Infrastructure\Persistence\DbalDiscussionRepository;
use App\Review\Infrastructure\Persistence\DbalMergeRequestRepository;
use App\Review\Infrastructure\Persistence\DbalPipelineRepository;
use App\Review\Infrastructure\Persistence\DbalProjectRepository;
use App\Review\Infrastructure\Persistence\DbalUserRepository;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use App\Shared\Infrastructure\Persistence\SyncStateStore;
use App\Tests\Support\FakeGitLabClient;
use App\Tests\Support\TestAppConfig;
use App\Tests\Support\TestSchema;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;

use function array_merge;
use function is_file;
use function strtotime;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SynchronizerTest extends TestCase
{
    private AppConfig $config;
    private Connection $connection;
    private FakeGitLabClient $client;
    private Synchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create(
            sys_get_temp_dir() . '/cr-dashboard-sync-' . uniqid('', true) . '.sqlite',
        );
        $this->client = new FakeGitLabClient();
        TestSchema::migrate($this->config);
        $this->connection = (new ConnectionFactory($this->config))->create();
        $this->synchronizer = $this->createSynchronizer($this->config);
    }

    public function testFullSyncStoresMrAndSubResources(): void
    {
        $this->client->projects = [
            [
                'id' => 1,
                'path_with_namespace' => 'group/proj',
                'name' => 'Proj',
                'avatar_url' => 'https://gitlab.example.test/uploads/proj/avatar.png',
            ],
        ];
        $this->client->mergeRequests['opened'] = [
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

        $mrs = $this->rows('SELECT * FROM merge_requests');
        self::assertCount(1, $mrs);
        self::assertSame('opened', $mrs[0]['state']);
        $createdAt = SqliteDateTime::fromStorage($mrs[0]['created_at']);
        self::assertSame((int) strtotime('2026-08-01T09:00:00+00:00'), $createdAt);
        self::assertSame(1, $this->rowCount('approvals'));
        self::assertSame(1, $this->rowCount('discussions'));
        self::assertSame(1, $this->rowCount('pipelines'));
        self::assertSame(1, $this->rowCount('commits'));

        $commit = $this->rows('SELECT * FROM commits WHERE merge_request_id = 101')[0];
        self::assertSame('shaA', $commit['sha']);
        self::assertSame(5, $commit['additions']);
        self::assertSame(2, $commit['deletions']);
        self::assertSame(1, $commit['current']);

        $project = $this->rows('SELECT path_with_namespace, name, avatar_url FROM projects WHERE id = 1')[0];
        self::assertSame('group/proj', $project['path_with_namespace']);
        self::assertSame('Proj', $project['name']);
        self::assertSame('https://gitlab.example.test/uploads/proj/avatar.png', $project['avatar_url']);

        self::assertSame(1, $this->value('SELECT COUNT(*) FROM users WHERE id = 1'));
        self::assertNotNull($this->synchronizer->lastSync());
    }

    public function testMrLabelsAreStoredAsJson(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj', 'name' => 'Proj', 'avatar_url' => null],
        ];
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00', null, 1, ['labels' => ['frontend', 'urgent']]),
        ];
        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $stored = $this->rows('SELECT labels FROM merge_requests WHERE id = 101');
        self::assertSame('["frontend","urgent"]', $stored[0]['labels']);
    }

    public function testSubResourcesAreWipedAndReinserted(): void
    {
        $this->seedSingleMrWithApprovals([2, 3]);
        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));
        self::assertSame(2, $this->rowCount('approvals'));

        $this->seedSingleMrWithApprovals([3]);
        $this->synchronizer->full((int) strtotime('2026-08-10T12:30:00+00:00'));

        $approvals = $this->rows('SELECT user_id FROM approvals ORDER BY user_id');
        self::assertCount(1, $approvals);
        self::assertSame(3, $approvals[0]['user_id']);
    }

    public function testCommitsAreAppendOnlyAndStatsFetchedOncePerSha(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
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
        $shas = $this->rows('SELECT sha, current FROM commits ORDER BY sha');
        self::assertSame(['shaA' => 1, 'shaB' => 1], $this->currentFlags($shas));

        // shaA is force-pushed away.
        $this->client->commitsByIid[101] = [
            ['id' => 'shaB', 'title' => 'b', 'committed_date' => '2026-08-02T10:30:00+00:00'],
        ];
        $this->synchronizer->full((int) strtotime('2026-08-10T14:00:00+00:00'));

        $shas = $this->rows('SELECT sha, current FROM commits ORDER BY sha');
        self::assertSame(['shaA' => 0, 'shaB' => 1], $this->currentFlags($shas));
        self::assertSame(2, $this->client->commitStatsCalls, 'stats are never re-fetched for known shas');
    }

    public function testDuplicateCommitShasDoNotCrashAndStatsFetchedOnce(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->commitStatsBySha['shaA'] = ['stats' => ['additions' => 7, 'deletions' => 3]];
        // GitLab can return the same sha more than once (pagination overlap,
        // merge history); a plain INSERT would hit the UNIQUE (merge_request_id, sha)
        // constraint on the second occurrence.
        $this->client->commitsByIid[101] = [
            ['id' => 'shaA', 'title' => 'a', 'committed_date' => '2026-08-01T10:30:00+00:00'],
            ['id' => 'shaA', 'title' => 'a', 'committed_date' => '2026-08-01T10:30:00+00:00'],
        ];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $commits = $this->rows('SELECT sha, additions, deletions, current FROM commits WHERE merge_request_id = 101');
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

        $mrs = $this->rows('SELECT id, state FROM merge_requests');
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

        $mergedQuery = null;
        foreach ($this->client->mergeRequestQueries as $query) {
            if (($query['state'] ?? null) === 'merged') {
                $mergedQuery = $query;
                break;
            }
        }
        self::assertNotNull($mergedQuery, 'full() issues a state=merged query bounded by updated_after');
        self::assertArrayHasKey('updated_after', $mergedQuery);
        $updatedAfter = $mergedQuery['updated_after'];
        self::assertIsString($updatedAfter);
        // retentionDays defaults to 90: 2026-08-10 minus 90 days = 2026-05-12.
        self::assertStringStartsWith('2026-05-12T', $updatedAfter);
    }

    public function testIncrementalRePollsRunningPipelines(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
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
        $job = $this->rows('SELECT status FROM jobs WHERE id = 1')[0];
        self::assertSame('failed', $job['status']);
    }

    public function testIncrementalQueriesUpdatedAfterLastSync(): void
    {
        $this->seedSingleMrWithApprovals([]);
        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $this->client->mergeRequests['all'] = [];
        $this->synchronizer->incremental((int) strtotime('2026-08-10T12:15:00+00:00'));

        // full() issues two MR queries (opened, then merged+updated_after); the
        // incremental query is therefore the third recorded query.
        $lastQuery = $this->client->mergeRequestQueries[2];
        self::assertArrayHasKey('updated_after', $lastQuery);
        $updatedAfter = $lastQuery['updated_after'];
        self::assertIsString($updatedAfter);
        self::assertStringStartsWith('2026-08-10T', $updatedAfter);
    }

    public function testIncrementalDropsMrThatTransitionsToClosed(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->approvalsByIid[101] = ['approved_by' => [
            [
                'user' => ['id' => 2, 'name' => 'Bob', 'username' => 'bob', 'avatar_url' => null],
                'approved_at' => '2026-08-02T09:00:00+00:00',
            ],
        ]];
        $this->client->commitsByIid[101] = [
            ['id' => 'shaA', 'title' => 'a', 'committed_date' => '2026-08-01T10:30:00+00:00'],
        ];
        $this->client->commitStatsBySha['shaA'] = ['stats' => ['additions' => 1, 'deletions' => 1]];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));
        self::assertSame(1, $this->rowCount('merge_requests'));
        self::assertSame(1, $this->rowCount('approvals'));
        self::assertSame(1, $this->rowCount('commits'));

        // The MR is closed before the next sync: incremental fetches it via
        // state=all and must drop it and its sub-resources from the cache.
        $this->client->mergeRequests['all'] = [
            $this->mr(101, 'closed', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->mergeRequests['opened'] = [];
        $this->synchronizer->incremental((int) strtotime('2026-08-10T12:15:00+00:00'));

        self::assertSame(0, $this->rowCount('merge_requests'));
        self::assertSame(0, $this->rowCount('approvals'));
        self::assertSame(0, $this->rowCount('commits'));
    }

    public function testSyncLockBlocksConcurrentSync(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO sync_state (key, value) VALUES ('sync_lock', ?)",
            [(string) strtotime('2026-08-10T12:00:00+00:00')],
        );

        $this->expectException(SyncLockedException::class);

        $this->synchronizer->full((int) strtotime('2026-08-10T12:01:00+00:00'));
    }

    public function testStaleSyncLockCanBeTakenOver(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');
        $this->connection->executeStatement(
            "INSERT INTO sync_state (key, value) VALUES ('sync_lock', ?)",
            [(string) ($now - 3600)],
        );

        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];

        $this->synchronizer->full($now);

        self::assertSame(1, $this->rowCount('merge_requests'));
        self::assertNull(SqliteRows::value($this->connection, "SELECT value FROM sync_state WHERE key = 'sync_lock'"));
    }

    public function testProjectAllowlistRestrictsSyncedMrs(): void
    {
        $config = TestAppConfig::create($this->config->databasePath, ['gitlabProjects' => ['group/proj']]);
        $this->synchronizer = $this->createSynchronizer($config);

        $allowedMr = $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00', null, 1);
        $blockedMr = $this->mr(202, 'opened', '2026-08-02T09:00:00+00:00', null, 1);
        $blockedMr['project_id'] = 2;
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
            ['id' => 2, 'path_with_namespace' => 'group/other'],
        ];
        $this->client->mergeRequests['opened'] = [$allowedMr, $blockedMr];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $stored = $this->rows('SELECT iid FROM merge_requests');
        self::assertCount(1, $stored);
        self::assertSame(101, $stored[0]['iid']);
    }

    public function testMrMergeStatusAndConflictsAreStored(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00', null, 1, [
                'merge_status' => 'cannot_be_merged',
                'has_conflicts' => true,
            ]),
        ];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $mr = $this->rows('SELECT merge_status, has_conflicts FROM merge_requests WHERE id = 101')[0];
        self::assertSame('cannot_be_merged', $mr['merge_status']);
        self::assertSame(1, $mr['has_conflicts']);
    }

    public function testDiscussionResolutionStateIsStored(): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
            $this->mr(101, 'opened', '2026-08-01T09:00:00+00:00'),
        ];
        $this->client->discussionsByIid[101] = [
            [
                'notes' => [
                    [
                        'system' => false,
                        'author' => ['id' => 2, 'name' => 'Bob', 'username' => 'bob', 'avatar_url' => null],
                        'created_at' => '2026-08-02T10:00:00+00:00',
                        'resolvable' => true,
                        'resolved' => false,
                    ],
                ],
            ],
            [
                'notes' => [
                    [
                        'system' => false,
                        'author' => ['id' => 3, 'name' => 'Ann', 'username' => 'ann', 'avatar_url' => null],
                        'created_at' => '2026-08-02T11:00:00+00:00',
                        'resolvable' => true,
                        'resolved' => true,
                    ],
                ],
            ],
        ];

        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        $resolved = $this->rows('SELECT user_id, resolved FROM discussions ORDER BY user_id');
        self::assertSame(2, $resolved[0]['user_id']);
        self::assertSame(0, $resolved[0]['resolved']);
        self::assertSame(3, $resolved[1]['user_id']);
        self::assertSame(1, $resolved[1]['resolved']);
    }

    public function testStoreUserPreservesMrCountOnResync(): void
    {
        // A pre-ranked user (mr_count = 5) is re-touched by a sync that re-stores it.
        // The UPSERT must refresh name/username/avatar but leave the rank intact.
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url, mr_count) VALUES (1, ?, ?, NULL, 5)',
            ['User 1', 'user1'],
        );

        $this->seedSingleMrWithApprovals([2]);
        $this->synchronizer->full((int) strtotime('2026-08-10T12:00:00+00:00'));

        self::assertSame(5, $this->value('SELECT mr_count FROM users WHERE id = 1'));
    }

    public function testRankUsersUpdatesCountsAndIsBestEffort(): void
    {
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url, mr_count) VALUES (1, ?, ?, NULL, 0)',
            ['Alice', 'alice'],
        );
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url, mr_count) VALUES (2, ?, ?, NULL, 0)',
            ['Bob', 'bob'],
        );
        // User 3 already has a count; GitLab will fail for it, so the count must stay.
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url, mr_count) VALUES (3, ?, ?, NULL, 9)',
            ['Carol', 'carol'],
        );

        $this->client->mrCountByAuthor = [1 => 7, 2 => 3];
        $this->client->mrCountErrorsByAuthor[3] = new GitLabException('boom');

        $now = (int) strtotime('2026-08-10T12:00:00+00:00');
        $this->synchronizer->rankUsers($now);

        self::assertSame(7, $this->value('SELECT mr_count FROM users WHERE id = 1'));
        self::assertSame(3, $this->value('SELECT mr_count FROM users WHERE id = 2'));
        self::assertSame(9, $this->value('SELECT mr_count FROM users WHERE id = 3'));
        self::assertSame($now, SqliteDateTime::fromStorage($this->value('SELECT ranked_at FROM users WHERE id = 1')));
        self::assertSame($now, $this->synchronizer->lastRank());
        self::assertSame(3, $this->client->authorMergeRequestCountCalls);
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

    private function createSynchronizer(AppConfig $config): Synchronizer
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
            $config,
        );
    }

    /**
     * @param list<int> $approverIds
     */
    private function seedSingleMrWithApprovals(array $approverIds): void
    {
        $this->client->projects = [
            ['id' => 1, 'path_with_namespace' => 'group/proj'],
        ];
        $this->client->mergeRequests['opened'] = [
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
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function mr(
        int $id,
        string $state,
        string $createdIso,
        null|string $mergedIso = null,
        int $authorId = 1,
        array $overrides = [],
    ): array {
        return array_merge([
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
        ], $overrides);
    }

    private function rowCount(string $table): int
    {
        return (int) SqliteRows::value($this->connection, 'SELECT COUNT(*) FROM ' . $table);
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
