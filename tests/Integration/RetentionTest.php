<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Storage\Database;
use App\Sync\Synchronizer;
use App\Tests\Support\FakeGitLabClient;
use App\Tests\Support\TestAppConfig;
use App\Tests\Support\TestSchema;
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
    private Database $database;
    private Synchronizer $synchronizer;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create(sys_get_temp_dir() . '/cr-dashboard-ret-' . uniqid('', true) . '.sqlite');
        $this->database = new Database($this->config);
        TestSchema::migrate($this->config);
        $this->synchronizer = new Synchronizer(new FakeGitLabClient(), $this->database, $this->config);
    }

    public function testPrunesOldMergedMrTogetherWithSubResources(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');
        $oldMergedAt = $now - (100 * self::DAY);

        $this->insertMr(901, 'merged', $oldMergedAt - (5 * self::DAY), $oldMergedAt, null);
        $this->database->execute(
            'INSERT INTO approvals (mr_id, user_id, created_at) VALUES (901, 1, ?)',
            [$oldMergedAt + 3600],
        );
        $this->database->execute(
            'INSERT INTO commits (mr_id, sha, message, committed_date, current, additions, deletions)
			 VALUES (901, ?, ?, ?, 1, 3, 1)',
            ['shaOld', 'old', $oldMergedAt],
        );
        $this->database->execute(
            'INSERT INTO pipelines (id, mr_id, status, created_at, updated_at) VALUES (50, 901, ?, ?, ?)',
            ['success', $oldMergedAt, $oldMergedAt],
        );

        $this->insertMr(902, 'merged', $now - (20 * self::DAY), $now - (10 * self::DAY), null);
        $this->database->execute(
            'INSERT INTO approvals (mr_id, user_id, created_at) VALUES (902, 1, ?)',
            [$now - (9 * self::DAY)],
        );

        $pruned = $this->synchronizer->applyRetention($now);

        self::assertSame(1, $pruned);
        self::assertNull($this->database->queryValue('SELECT id FROM merge_requests WHERE id = 901'));
        self::assertNull($this->database->queryValue('SELECT id FROM approvals WHERE mr_id = 901'));
        self::assertNull($this->database->queryValue('SELECT id FROM commits WHERE mr_id = 901'));
        self::assertNull($this->database->queryValue('SELECT id FROM pipelines WHERE mr_id = 901'));
        self::assertNotNull($this->database->queryValue('SELECT id FROM merge_requests WHERE id = 902'));
        self::assertNotNull($this->database->queryValue('SELECT id FROM approvals WHERE mr_id = 902'));
    }

    public function testKeepsOpenAndRecentlyMergedMrs(): void
    {
        $now = (int) strtotime('2026-08-10T12:00:00+00:00');

        $this->insertMr(903, 'opened', $now - (70 * self::DAY), null, null);
        $this->insertMr(904, 'closed', $now - (80 * self::DAY), null, $now - (79 * self::DAY));

        $pruned = $this->synchronizer->applyRetention($now);

        self::assertSame(0, $pruned);
        self::assertNotNull($this->database->queryValue('SELECT id FROM merge_requests WHERE id = 903'));
        self::assertNotNull($this->database->queryValue('SELECT id FROM merge_requests WHERE id = 904'));
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
        $this->database->execute(
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
                $createdAt,
                $mergedAt,
                $closedAt,
                $createdAt,
                'https://gitlab.example.test/group/proj/-/merge_requests/' . $id,
            ],
        );
    }
}
