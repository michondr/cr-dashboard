<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Review\Application\Sync\Synchronizer;
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

use function is_file;
use function strtotime;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class RetentionTest extends TestCase
{
    private const DAY = 86400;

    private AppConfig $config;
    private Connection $connection;
    private Synchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create(sys_get_temp_dir() . '/cr-dashboard-ret-' . uniqid('', true) . '.sqlite');
        TestSchema::migrate($this->config);
        $this->connection = (new ConnectionFactory($this->config))->create();
        $this->synchronizer = new Synchronizer(
            new FakeGitLabClient(),
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

    public function testPrunesOldMergedMrTogetherWithSubResources(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');
        $oldMergedAt = $now - (100 * self::DAY);

        $this->insertMr(901, 'merged', $oldMergedAt - (5 * self::DAY), $oldMergedAt, null);
        $this->connection->executeStatement(
            'INSERT INTO approvals (merge_request_id, user_id, created_at) VALUES (901, 1, ?)',
            [SqliteDateTime::toStorage($oldMergedAt + 3600)],
        );
        $this->connection->executeStatement(
            'INSERT INTO commits (merge_request_id, sha, message, committed_date, current, additions, deletions)
             VALUES (901, ?, ?, ?, 1, 3, 1)',
            ['shaOld', 'old', SqliteDateTime::toStorage($oldMergedAt)],
        );
        $this->connection->executeStatement(
            'INSERT INTO pipelines (id, merge_request_id, status, created_at, updated_at) VALUES (50, 901, ?, ?, ?)',
            ['success', SqliteDateTime::toStorage($oldMergedAt), SqliteDateTime::toStorage($oldMergedAt)],
        );

        $this->insertMr(902, 'merged', $now - (20 * self::DAY), $now - (10 * self::DAY), null);
        $this->connection->executeStatement(
            'INSERT INTO approvals (merge_request_id, user_id, created_at) VALUES (902, 1, ?)',
            [SqliteDateTime::toStorage($now - (9 * self::DAY))],
        );

        $pruned = $this->synchronizer->applyRetention($now);

        self::assertSame(1, $pruned);
        self::assertNull(SqliteRows::value($this->connection, 'SELECT id FROM merge_requests WHERE id = 901'));
        self::assertNull(SqliteRows::value($this->connection, 'SELECT id FROM approvals WHERE merge_request_id = 901'));
        self::assertNull(SqliteRows::value($this->connection, 'SELECT id FROM commits WHERE merge_request_id = 901'));
        self::assertNull(SqliteRows::value($this->connection, 'SELECT id FROM pipelines WHERE merge_request_id = 901'));
        self::assertNotNull(SqliteRows::value($this->connection, 'SELECT id FROM merge_requests WHERE id = 902'));
        $keptApproval = SqliteRows::value($this->connection, 'SELECT id FROM approvals WHERE merge_request_id = 902');
        self::assertNotNull($keptApproval);
    }

    public function testKeepsOpenAndRecentlyMergedMrs(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');

        $this->insertMr(903, 'opened', $now - (70 * self::DAY), null, null);
        $this->insertMr(904, 'closed', $now - (80 * self::DAY), null, $now - (79 * self::DAY));

        $pruned = $this->synchronizer->applyRetention($now);

        self::assertSame(0, $pruned);
        self::assertNotNull(SqliteRows::value($this->connection, 'SELECT id FROM merge_requests WHERE id = 903'));
        self::assertNotNull(SqliteRows::value($this->connection, 'SELECT id FROM merge_requests WHERE id = 904'));
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

    private function insertMr(int $id, string $state, int $createdAt, null|int $mergedAt, null|int $closedAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO merge_requests (
                id, iid, project_id, title, description, author_id, state, draft,
                created_at, merged_at, closed_at, updated_at, web_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $id,
                1,
                'MR ' . $id,
                '',
                1,
                $state,
                0,
                SqliteDateTime::toStorage($createdAt),
                $mergedAt === null ? null : SqliteDateTime::toStorage($mergedAt),
                $closedAt === null ? null : SqliteDateTime::toStorage($closedAt),
                SqliteDateTime::toStorage($createdAt),
                'https://gitlab.example.test/group/proj/-/merge_requests/' . $id,
            ],
        );
    }
}
