<?php

declare(strict_types=1);

namespace App\Tests\Integration\Persistence;

use App\Review\Domain\MergeRequest\MergeRequestRepository;
use App\Review\Infrastructure\Persistence\DbalApprovalRepository;
use App\Review\Infrastructure\Persistence\DbalCommitRepository;
use App\Review\Infrastructure\Persistence\DbalDiscussionRepository;
use App\Review\Infrastructure\Persistence\DbalMergeRequestRepository;
use App\Review\Infrastructure\Persistence\DbalPipelineRepository;
use App\Review\Infrastructure\Persistence\DbalProjectRepository;
use App\Review\Infrastructure\Persistence\DbalUserRepository;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use App\Tests\Support\TestAppConfig;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_merge;
use function dirname;
use function sys_get_temp_dir;
use function uniqid;

use const CASE_LOWER;

/**
 * Exercises the DBAL repositories against a schema built from the Doctrine
 * entity metadata — the Doctrine-native shape a follow-up migration lands once
 * the sync/read code moves onto the repositories — so they are verified on the
 * types they target.
 */
final class RepositoriesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $config = TestAppConfig::create(
            sys_get_temp_dir() . '/cr-dashboard-repos-' . uniqid('', true) . '.sqlite',
        );
        $this->connection = (new ConnectionFactory($config))->create();

        $ormConfig = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Review/Domain'], true);
        $ormConfig->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $ormConfig->setProxyDir(sys_get_temp_dir());
        $ormConfig->setProxyNamespace('App\\Tests\\Proxies');
        $em = new EntityManager($this->connection, $ormConfig);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    public function testMergeRequestUpsertIsIdempotentAndStoresDoctrineNativeTypes(): void
    {
        $repo = $this->mergeRequestRepository();
        $repo->upsert($this->mr(1, ['iid' => 7, 'title' => 'Fix bug', 'labels' => '["frontend"]']));

        $cached = $repo->findById(1);
        self::assertNotNull($cached);
        self::assertSame(7, $cached['iid']);
        self::assertSame('Fix bug', $cached['title']);
        self::assertSame(1000, $cached['created_at']);
        self::assertSame(2000, $cached['updated_at']);
        self::assertNull($cached['merged_at']);
        self::assertSame('["frontend"]', $cached['labels']);
        self::assertTrue($repo->isCached(1));
        self::assertSame('1970-01-01 00:16:40', $this->column('merge_requests', 'created_at'));

        $repo->upsert($this->mr(1, ['iid' => 8, 'updated_at' => 3000, 'labels' => '["frontend","urgent"]']));
        $cached = $repo->findById(1);
        self::assertNotNull($cached);
        self::assertSame(8, $cached['iid']);
        self::assertSame(3000, $cached['updated_at']);
        self::assertSame('["frontend","urgent"]', $cached['labels']);
        self::assertSame([1], $repo->allIds());

        $repo->remove(1);
        self::assertFalse($repo->isCached(1));
        self::assertSame([], $repo->allIds());
    }

    public function testMergeRequestOpenRefsAndRetentionFilterOnStateAndTimestamp(): void
    {
        $repo = $this->mergeRequestRepository();
        $repo->upsert($this->mr(1, ['state' => 'opened', 'created_at' => 100, 'updated_at' => 100]));
        $repo->upsert($this->mr(2, ['state' => 'opened', 'created_at' => 500, 'updated_at' => 500]));
        $repo->upsert($this->mr(3, [
            'state' => 'merged',
            'created_at' => 100,
            'merged_at' => 300,
            'updated_at' => 300,
        ]));
        $repo->upsert($this->mr(4, [
            'state' => 'closed',
            'created_at' => 100,
            'closed_at' => 700,
            'updated_at' => 700,
        ]));

        $refs = $repo->openRefsCreatedAfter(200);
        self::assertSame([2], array_column($refs, 'id'));
        self::assertSame(3, $refs[0]['project_id']);
        self::assertSame(9, $refs[0]['author_id']);

        // Only merged/closed rows whose terminal timestamp predates the cutoff.
        self::assertSame([3, 4], $repo->retentionIdsBefore(800));
    }

    public function testUserUpsertAndRanking(): void
    {
        $repo = new DbalUserRepository($this->connection);
        $repo->upsert(['id' => 1, 'name' => 'Alice', 'username' => 'alice', 'avatar_url' => null]);
        $repo->upsert(['id' => 1, 'name' => 'Alice B.', 'username' => 'alice', 'avatar_url' => 'http://a/1']);

        self::assertSame([1], $repo->allIds());
        self::assertSame('Alice B.', $this->column('users', 'name'));
        self::assertSame('http://a/1', $this->column('users', 'avatar_url'));

        $repo->updateRank(1, 12, 5000);
        self::assertSame(12, (int) $this->column('users', 'mr_count'));
        self::assertSame('1970-01-01 01:23:20', $this->column('users', 'ranked_at'));
    }

    public function testProjectUpsertIsIdempotent(): void
    {
        $repo = new DbalProjectRepository($this->connection);
        $repo->upsert(['id' => 3, 'path_with_namespace' => 'group/app', 'name' => 'app', 'avatar_url' => null]);
        $repo->upsert(['id' => 3, 'path_with_namespace' => 'group/app', 'name' => 'Application', 'avatar_url' => 'x']);

        self::assertSame([3], $repo->allIds());
        self::assertSame('Application', $this->column('projects', 'name'));
        self::assertSame('x', $this->column('projects', 'avatar_url'));
    }

    public function testApprovalReplaceWipesPreviousRows(): void
    {
        $repo = new DbalApprovalRepository($this->connection);
        $repo->replaceForMergeRequest(1, [
            ['user_id' => 2, 'created_at' => 100],
            ['user_id' => 3, 'created_at' => 200],
        ]);

        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM approvals'));

        $repo->replaceForMergeRequest(1, [['user_id' => 4, 'created_at' => 300]]);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM approvals'));
        self::assertSame(4, (int) $this->scalar('SELECT user_id FROM approvals'));
        self::assertSame('1970-01-01 00:05:00', $this->scalar('SELECT created_at FROM approvals'));
    }

    public function testDiscussionReplaceStoresResolvedFlag(): void
    {
        $repo = new DbalDiscussionRepository($this->connection);
        $repo->replaceForMergeRequest(1, [
            ['user_id' => 2, 'created_at' => 100, 'resolved' => true],
            ['user_id' => 3, 'created_at' => 200, 'resolved' => false],
        ]);

        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM discussions'));
        self::assertSame(1, (int) $this->scalar('SELECT resolved FROM discussions WHERE user_id = 2'));
        self::assertSame(0, (int) $this->scalar('SELECT resolved FROM discussions WHERE user_id = 3'));
    }

    public function testCommitUpsertKeepsOneRowPerShaAndRefreshesIt(): void
    {
        $repo = new DbalCommitRepository($this->connection);
        $repo->upsert(1, 'abc', ['message' => 'first', 'committed_date' => 100, 'additions' => 10, 'deletions' => 2]);
        $repo->upsert(1, 'def', ['message' => 'second', 'committed_date' => 200, 'additions' => 0, 'deletions' => 0]);

        self::assertTrue($repo->isCached(1, 'abc'));
        self::assertTrue($repo->isCached(1, 'def'));
        self::assertFalse($repo->isCached(1, 'xyz'));
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM commits'));

        $repo->markAllNonCurrent(1);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM commits WHERE current = 1'));

        // Re-push of `def` refreshes the same row and flips it back current.
        $repo->upsert(1, 'def', [
            'message' => 'second v2',
            'committed_date' => 210,
            'additions' => 1,
            'deletions' => 0,
        ]);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM commits'));
        self::assertSame('second v2', $this->scalar('SELECT message FROM commits WHERE sha = \'def\''));
        self::assertSame(1, (int) $this->scalar('SELECT current FROM commits WHERE sha = \'def\''));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM commits WHERE current = 1'));
    }

    public function testPipelineUpsertsJobsAndTracksRunningPipelines(): void
    {
        $repo = new DbalPipelineRepository($this->connection);
        $repo->upsertPipeline([
            'id' => 10,
            'merge_request_id' => 1,
            'status' => 'running',
            'created_at' => 100,
            'updated_at' => 200,
        ]);
        $repo->upsertPipeline([
            'id' => 11,
            'merge_request_id' => 1,
            'status' => 'success',
            'created_at' => 150,
            'updated_at' => 250,
        ]);
        $repo->upsertPipeline([
            'id' => 12,
            'merge_request_id' => 2,
            'status' => 'running',
            'created_at' => 300,
            'updated_at' => 300,
        ]);
        $repo->upsertJob(['id' => 1, 'pipeline_id' => 10, 'merge_request_id' => 1, 'status' => 'running']);
        $repo->upsertJob(['id' => 2, 'pipeline_id' => 12, 'merge_request_id' => 2, 'status' => 'pending']);

        // MR 1's newest pipeline is `success`; MR 2's newest is `running`.
        self::assertSame([2], $repo->runningPipelineMrIds());

        $repo->deleteByMergeRequest(1);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM pipelines WHERE merge_request_id = 1'));
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM jobs WHERE merge_request_id = 1'));
        $repo->deleteJobsByMr(1);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM jobs WHERE merge_request_id = 1'));
        $repo->deleteJobsByMr(2);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM jobs'));
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    private function mergeRequestRepository(): MergeRequestRepository
    {
        return new DbalMergeRequestRepository($this->connection);
    }

    /**
     * A full merge-request row with defaults, overridable per test.
     *
     * @param array<string, int|string|null> $overrides
     *
     * @return array<string, int|string|null>
     */
    private function mr(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'iid' => $id,
            'project_id' => 3,
            'title' => 'MR ' . $id,
            'description' => null,
            'author_id' => 9,
            'state' => 'opened',
            'draft' => 0,
            'created_at' => 1000,
            'merged_at' => null,
            'closed_at' => null,
            'updated_at' => 2000,
            'web_url' => '',
            'merge_status' => '',
            'has_conflicts' => 0,
            'labels' => '[]',
        ], $overrides);
    }

    private function column(string $table, string $column): string
    {
        return (string) $this->scalar(
            'SELECT ' . $column . ' FROM ' . $table . ' LIMIT 1',
        );
    }

    private function scalar(string $sql): null|int|float|string
    {
        $value = $this->connection->fetchOne($sql);
        if ($value === false || $value === null) {
            return null;
        }

        /** @var int|float|string $value */
        return $value;
    }
}
