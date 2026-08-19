<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\AppConfig;
use App\Kernel;
use App\Storage\Database;
use App\Tests\Support\TestAppConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function json_decode;
use function time;

final class ApiContractTest extends TestCase
{
    private const DAY = 86400;

    private AppConfig $config;
    private Database $database;

    protected function setUp(): void
    {
        $this->config = TestAppConfig::create('var/test.sqlite');
        $this->database = new Database($this->config);
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
            $this->database->execute('DELETE FROM ' . $table);
        }

        $now = time();

        $this->database->execute(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (1, ?, ?, ?)',
            ['Alice', 'alice', null],
        );
        $this->database->execute(
            'INSERT INTO users (id, name, username, avatar_url) VALUES (2, ?, ?, ?)',
            ['Bob', 'bob', null],
        );

        $this->insertMr(101, 'opened', $now - (2 * self::DAY), null, null, 1, 'REC-1234 - Add feature');
        $this->database->execute(
            'INSERT INTO approvals (mr_id, user_id, created_at) VALUES (101, 2, ?)',
            [$now - self::DAY],
        );
        $this->database->execute(
            'INSERT INTO commits (mr_id, sha, message, committed_date, current, additions, deletions)
             VALUES (101, ?, ?, ?, 1, ?, ?)',
            ['abc123', 'first commit', $now - (2 * self::DAY), 10, 2],
        );

        $this->insertMr(102, 'merged', $now - (20 * self::DAY), $now - (10 * self::DAY), null, 2, 'Just a fix');

        $this->database->execute(
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
        self::assertIsString($meta['next_sync_at']);

        self::assertArrayHasKey('users', $payload);
        $users = $payload['users'];
        self::assertIsArray($users);
        self::assertCount(2, $users);

        self::assertArrayHasKey('mrs', $payload);
        $mrs = $payload['mrs'];
        self::assertIsArray($mrs);
        // The list shows open MRs only; the merged MR is kept for metrics but
        // hidden from the list.
        self::assertCount(1, $mrs);
        $openMr = $this->findMr($mrs, 101);

        self::assertSame('REC-1234', $openMr['jira_ticket']);
        self::assertSame('opened', $openMr['state']);
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
        $this->database->execute(
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
                $createdAt,
                $mergedAt,
                $closedAt,
                $createdAt,
                'https://gitlab.example.test/group/proj/-/merge_requests/' . $id,
            ],
        );
    }
}
