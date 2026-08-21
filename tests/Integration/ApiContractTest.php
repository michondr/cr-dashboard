<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Kernel;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Tests\Support\TestAppConfig;
use App\Tests\Support\TestSchema;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function is_array;
use function is_file;
use function json_decode;
use function time;
use function unlink;

final class ApiContractTest extends TestCase
{
    private const DAY = 86400;

    private AppConfig $config;
    private Connection $connection;

    protected function setUp(): void
    {
        // The kernel boots against this fixed path, so a previous run's file
        // would carry an old schema; drop it before the migration runs.
        foreach (['var/test.sqlite', 'var/test.sqlite-wal', 'var/test.sqlite-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $this->config = TestAppConfig::create('var/test.sqlite');
        TestSchema::migrate($this->config);
        $this->connection = (new ConnectionFactory($this->config))->create();
        foreach (
            [
            'users',
            'projects',
            'merge_requests',
            'approvals',
            'discussions',
            'commits',
            'pipelines',
            'jobs',
            'sync_state',
            ] as $table
        ) {
            $this->connection->executeStatement('DELETE FROM ' . $table);
        }

        $now = time();

        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (1, ?, ?, ?)',
            ['Alice', 'alice', null],
        );
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (2, ?, ?, ?)',
            ['Bob', 'bob', null],
        );
        $this->connection->executeStatement(
            'INSERT INTO projects (id, path_with_namespace, name, avatar_url) VALUES (1, ?, ?, NULL)',
            ['group/proj', 'Proj'],
        );

        $this->insertMr(101, 'opened', $now - (2 * self::DAY), null, null, 1, 'REC-1234 - Add feature');
        $this->connection->executeStatement(
            'UPDATE merge_requests SET labels = ? WHERE id = 101',
            ['["urgent","frontend"]'],
        );
        $this->connection->executeStatement(
            'INSERT INTO approvals (merge_request_id, user_id, created_at) VALUES (101, 2, ?)',
            [SqliteDateTime::toStorage($now - self::DAY)],
        );
        $this->connection->executeStatement(
            'INSERT INTO discussions (merge_request_id, user_id, created_at) VALUES (101, 1, ?)',
            [SqliteDateTime::toStorage($now - self::DAY)],
        );
        $this->connection->executeStatement(
            'INSERT INTO commits (merge_request_id, sha, message, committed_date, current, additions, deletions)
             VALUES (101, ?, ?, ?, 1, ?, ?)',
            ['abc123', 'first commit', SqliteDateTime::toStorage($now - (2 * self::DAY)), 10, 2],
        );

        $this->insertMr(102, 'merged', $now - (20 * self::DAY), $now - (10 * self::DAY), null, 2, 'Just a fix');

        $this->connection->executeStatement(
            "INSERT INTO sync_state (key, value) VALUES ('last_sync', ?)",
            [(string) ($now - 30)],
        );
    }

    public function testApiDataReturnsDocumentedShape(): void
    {
        $payload = $this->fetchApiData(['bucket' => 'day']);

        self::assertArrayHasKey('meta', $payload);
        $meta = $payload['meta'];
        self::assertIsArray($meta);
        self::assertSame(2, $meta['required_approvals']);
        self::assertSame(60, $meta['stale_days']);
        self::assertSame(60, $meta['window_days']);
        self::assertSame(30, $meta['coverage_window_days']);
        self::assertGreaterThanOrEqual(30, $meta['cache_age_seconds']);
        self::assertLessThan(60, $meta['cache_age_seconds']);
        self::assertIsString($meta['last_sync_at']);
        self::assertArrayNotHasKey('next_sync_at', $meta);

        self::assertArrayHasKey('users', $payload);
        $users = $payload['users'];
        self::assertIsArray($users);
        self::assertCount(2, $users);
        foreach ($users as $user) {
            self::assertIsArray($user);
            self::assertArrayHasKey('mr_count', $user);
            self::assertIsInt($user['mr_count']);
        }

        self::assertArrayHasKey('mrs', $payload);
        $mrs = $payload['mrs'];
        self::assertIsArray($mrs);
        // The list shows open MRs only; the merged MR is kept for metrics but
        // hidden from the list.
        self::assertCount(1, $mrs);
        $openMr = $this->findMr($mrs, 101);

        self::assertSame('REC-1234', $openMr['jira_ticket']);
        self::assertSame('opened', $openMr['state']);

        $project = $openMr['project'];
        self::assertIsArray($project);
        self::assertSame('group/proj', $project['path_with_namespace']);
        self::assertSame('Proj', $project['name']);
        self::assertNull($project['avatar_url']);
        self::assertFalse($openMr['draft']);
        self::assertFalse($openMr['stale']);
        self::assertSame(2 * self::DAY, $openMr['age_seconds']);
        self::assertSame(self::DAY, $openMr['time_to_first_approval_seconds']);
        self::assertSame(1, $openMr['commit_count']);

        $diffUrls = $openMr['commit_diff_urls'];
        self::assertIsArray($diffUrls);
        self::assertCount(1, $diffUrls);

        $pipeline = $openMr['pipeline'];
        self::assertIsArray($pipeline);
        self::assertSame('none', $pipeline['indicator']);

        $approvers = $openMr['approvers'];
        self::assertIsArray($approvers);
        self::assertCount(1, $approvers);
        $firstApprover = $approvers[0];
        self::assertIsArray($firstApprover);
        self::assertSame(2, $firstApprover['id']);
        self::assertArrayHasKey('avatar_url', $firstApprover);
        self::assertIsString($firstApprover['approved_at']);

        self::assertFalse($openMr['needs_rebase']);
        self::assertSame(0, $openMr['unresolved_discussions']);
        self::assertFalse($openMr['approved'], 'one approval is below the required two');
        self::assertFalse($openMr['ready']);
        self::assertSame(['urgent', 'frontend'], $openMr['labels']);

        self::assertArrayHasKey('metrics', $payload);
        $metrics = $payload['metrics'];
        self::assertIsArray($metrics);

        self::assertArrayHasKey('time_to_first_approve', $metrics);
        $first = $metrics['time_to_first_approve'];
        self::assertIsArray($first);
        self::assertSame('day', $first['bucket']);
        self::assertSame('seconds', $first['unit']);

        $firstPersons = $first['persons'];
        self::assertIsArray($firstPersons);
        self::assertArrayHasKey('2', $firstPersons);
        $bobSeries = $firstPersons['2'];
        self::assertIsArray($bobSeries);
        self::assertArrayHasKey('mean', $bobSeries);
        self::assertArrayHasKey('median', $bobSeries);

        self::assertArrayHasKey('coverage', $metrics);
        $coverage = $metrics['coverage'];
        self::assertIsArray($coverage);
        $coveragePersons = $coverage['persons'];
        self::assertIsArray($coveragePersons);
        self::assertArrayHasKey('2', $coveragePersons);
        $coverageBob = $coveragePersons['2'];
        self::assertIsArray($coverageBob);
        self::assertArrayHasKey('values', $coverageBob);

        self::assertArrayHasKey('discussions_started', $metrics);
        $started = $metrics['discussions_started'];
        self::assertIsArray($started);
        self::assertSame('day', $started['bucket']);
        self::assertSame('count', $started['unit']);
        $startedPersons = $started['persons'];
        self::assertIsArray($startedPersons);
        self::assertArrayHasKey('1', $startedPersons);
        self::assertIsArray($startedPersons['1']);
        self::assertArrayHasKey('values', $startedPersons['1']);
    }

    public function testApiMrReturnsASingleRowMatchingTheListShape(): void
    {
        $payload = $this->fetchApiData(['bucket' => 'day']);
        self::assertIsArray($payload['mrs']);
        self::assertNotEmpty($payload['mrs']);
        /** @var array<string, mixed> $first */
        $first = $payload['mrs'][0];
        self::assertIsInt($first['id']);

        $kernel = new Kernel('test', true);
        $kernel->boot();
        $request = Request::create('/api/mr/' . $first['id'], 'GET');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertIsArray($decoded['mr']);
        // age_seconds is now-relative and may differ between the two calls.
        unset($decoded['mr']['age_seconds'], $first['age_seconds']);
        self::assertSame($first, $decoded['mr']);

        $missing = Request::create('/api/mr/999999999', 'GET');
        $missingResponse = $kernel->handle($missing);
        $kernel->terminate($missing, $missingResponse);
        self::assertSame(404, $missingResponse->getStatusCode());
        $missingDecoded = json_decode((string) $missingResponse->getContent(), true);
        self::assertIsArray($missingDecoded);
        self::assertNull($missingDecoded['mr']);
    }

    public function testInvalidBucketFallsBackToDay(): void
    {
        $payload = $this->fetchApiData(['bucket' => 'bogus']);

        self::assertArrayHasKey('metrics', $payload);
        $metrics = $payload['metrics'];
        self::assertIsArray($metrics);
        self::assertArrayHasKey('time_to_first_approve', $metrics);
        $first = $metrics['time_to_first_approve'];
        self::assertIsArray($first);
        self::assertSame('day', $first['bucket']);
    }

    public function testHourlyBucketIsAccepted(): void
    {
        $payload = $this->fetchApiData(['bucket' => 'hour']);

        self::assertArrayHasKey('metrics', $payload);
        $metrics = $payload['metrics'];
        self::assertIsArray($metrics);
        self::assertArrayHasKey('time_to_first_approve', $metrics);
        $first = $metrics['time_to_first_approve'];
        self::assertIsArray($first);
        self::assertSame('hour', $first['bucket']);
    }

    public function testUserFilterReturnsAuthoredOrUnreviewedMrs(): void
    {
        $now = time();
        // MR 103: open, authored by user 2, no approvals.
        $this->insertMr(103, 'opened', $now - (3 * self::DAY), null, null, 2, 'REC-2000 - Mine');
        // MR 104: open, authored by user 1, approved by user 2.
        $this->insertMr(104, 'opened', $now - (4 * self::DAY), null, null, 1, 'REC-2001 - Done');
        $this->connection->executeStatement(
            'INSERT INTO approvals (merge_request_id, user_id, created_at) VALUES (104, 2, ?)',
            [SqliteDateTime::toStorage($now - (2 * self::DAY))],
        );

        // User 1 (Alice): her MRs (101, 104) plus MRs she has not approved (103).
        $payload = $this->fetchApiData(['bucket' => 'day', 'user' => '1']);
        self::assertEqualsCanonicalizing([101, 103, 104], $this->mrIds($payload['mrs']));

        // User 2 (Bob): his MR (103); MRs he already approved (101, 104) are dropped.
        $payload = $this->fetchApiData(['bucket' => 'day', 'user' => '2']);
        self::assertEqualsCanonicalizing([103], $this->mrIds($payload['mrs']));
    }

    public function testUsersOrderedByMrCountThenName(): void
    {
        // Bob ranks highest, Alice and Carol tie at 1 (name asc: Alice before Carol),
        // Dave has none and sorts last by name.
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url, mr_count) VALUES (3, ?, ?, NULL, 1)',
            ['Carol', 'carol'],
        );
        $this->connection->executeStatement(
            'INSERT INTO users (id, name, username, avatar_url, mr_count) VALUES (4, ?, ?, NULL, 0)',
            ['Dave', 'dave'],
        );
        $this->connection->executeStatement('UPDATE users SET mr_count = 5 WHERE id = 2');
        $this->connection->executeStatement('UPDATE users SET mr_count = 1 WHERE id = 1');
        $now = time();
        $this->connection->executeStatement(
            "INSERT INTO sync_state (key, value) VALUES ('last_rank_at', ?)",
            [(string) $now],
        );

        $payload = $this->fetchApiData(['bucket' => 'day']);
        $ids = [];
        foreach ($payload['users'] as $user) {
            self::assertIsArray($user);
            self::assertIsInt($user['id']);
            $ids[] = $user['id'];
        }
        self::assertSame([2, 1, 3, 4], $ids);

        $meta = $payload['meta'];
        self::assertIsString($meta['last_rank_at']);
        self::assertIsString($meta['next_rank_at']);
    }

    /**
     * @param array<mixed, mixed> $mrs
     *
     * @return list<mixed>
     */
    private function mrIds(array $mrs): array
    {
        $ids = [];
        foreach ($mrs as $mr) {
            if (is_array($mr)) {
                $ids[] = $mr['id'];
            }
        }

        return $ids;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{
     *   meta: array<string, mixed>,
     *   users: array<mixed, mixed>,
     *   mrs: array<mixed, mixed>,
     *   metrics: array<mixed, mixed>
     * }
     */
    private function fetchApiData(array $query): array
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $request = Request::create('/api/data', 'GET', $query);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        /** @var array{meta: array<string, mixed>, users: array<mixed, mixed>, mrs: array<mixed, mixed>, metrics: array<mixed, mixed>} $decoded */

        return $decoded;
    }

    /**
     * @param array<mixed, mixed> $mrs
     *
     * @return array<mixed, mixed>
     */
    private function findMr(array $mrs, int $id): array
    {
        foreach ($mrs as $mr) {
            self::assertIsArray($mr);
            if ($mr['id'] === $id) {
                return $mr;
            }
        }

        self::fail('MR ' . $id . ' not found in payload.');
    }

    private function insertMr(
        int $id,
        string $state,
        int $createdAt,
        null|int $mergedAt,
        null|int $closedAt,
        int $authorId,
        string $title,
    ): void {
        $this->connection->executeStatement(
            'INSERT INTO merge_requests (
                id, iid, project_id, title, description, author_id, state, draft,
                created_at, merged_at, closed_at, updated_at, web_url
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $id,
                $id,
                1,
                $title,
                'description',
                $authorId,
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
