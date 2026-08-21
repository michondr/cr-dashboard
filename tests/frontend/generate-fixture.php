<?php

declare(strict_types=1);

use App\Metrics\MetricCalculator;
use App\ReadModel\ApiBuilder;
use App\ReadModel\DbalDatasetRepository;
use App\Review\Application\Sync\Synchronizer;
use App\Review\Infrastructure\Persistence\DbalApprovalRepository;
use App\Review\Infrastructure\Persistence\DbalCommitRepository;
use App\Review\Infrastructure\Persistence\DbalDiscussionRepository;
use App\Review\Infrastructure\Persistence\DbalMergeRequestRepository;
use App\Review\Infrastructure\Persistence\DbalPipelineRepository;
use App\Review\Infrastructure\Persistence\DbalProjectRepository;
use App\Review\Infrastructure\Persistence\DbalUserRepository;
use App\Shared\Infrastructure\Persistence\ConnectionFactory;
use App\Shared\Infrastructure\Persistence\SyncStateStore;
use App\Tests\Support\FakeGitLabClient;
use App\Tests\Support\TestAppConfig;
use App\Tests\Support\TestSchema;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const DAY = 86400;

$now = time();
$iso = static fn (int $ts): string => gmdate(DATE_ATOM, $ts);
$user = static fn (int $id): array => [
    'id' => $id,
    'name' => ['Jane Doe', 'John Roe', 'Ann Lee'][$id - 1],
    'username' => ['jdoe', 'jroe', 'alee'][$id - 1],
    'avatar_url' => null,
];
$mr = static function (
    int $id,
    string $state,
    int $created,
    null|int $merged,
    int $authorId,
    string $title,
) use (
    $iso,
    $user,
): array {
    return [
        'id' => $id,
        'iid' => $id,
        'project_id' => 1,
        'title' => $title,
        'description' => 'This is a description of MR ' . $id
            . ' with enough text to overflow the 50px collapsed height'
            . ' and force the expand/collapse behaviour on click.',
        'author' => $user($authorId),
        'state' => $state,
        'draft' => false,
        'created_at' => $iso($created),
        'merged_at' => $merged === null ? null : $iso($merged),
        'closed_at' => null,
        'updated_at' => $iso($created),
        'web_url' => 'https://gitlab.example.test/group/app/-/merge_requests/' . $id,
        'merge_status' => 'can_be_merged',
        'has_conflicts' => false,
        'labels' => [],
    ];
};
$commit = static fn (string $sha, string $message, int $when): array => [
    'id' => $sha,
    'title' => $message,
    'committed_date' => $iso($when),
];

$path = sys_get_temp_dir() . '/cr-dashboard-fixture-' . uniqid('', true) . '.sqlite';
$config = TestAppConfig::create($path);
$client = new FakeGitLabClient();
TestSchema::migrate($config);
$connection = (new ConnectionFactory($config))->create();
$synchronizer = new Synchronizer(
    $client,
    new DbalMergeRequestRepository($connection),
    new DbalUserRepository($connection),
    new DbalProjectRepository($connection),
    new DbalApprovalRepository($connection),
    new DbalDiscussionRepository($connection),
    new DbalCommitRepository($connection),
    new DbalPipelineRepository($connection),
    new SyncStateStore($connection),
    $connection,
    $config,
);

$client->projects = [
    ['id' => 1, 'path_with_namespace' => 'group/app', 'name' => 'App', 'avatar_url' => null],
];

$blockedMr = $mr(208, 'opened', $now - (2 * DAY), null, 2, 'REC-204 - Export the web');
$blockedMr['merge_status'] = 'cannot_be_merged';
$blockedMr['has_conflicts'] = true;
$blockedMr['labels'] = ['chore'];
$draftMr = $mr(209, 'opened', $now - DAY, null, 1, 'REC-205 - Draft the docs');
$draftMr['draft'] = true;
$draftMr['labels'] = ['docs'];

// A few MRs carry labels so the smoke test can assert the label badges render
// alongside the status badges.
$labeledMr201 = $mr(201, 'opened', $now - (2 * DAY), null, 1, 'REC-200 - Add an export button');
$labeledMr201['labels'] = ['frontend', 'urgent'];
$labeledMr202 = $mr(202, 'opened', $now - (75 * DAY), null, 2, 'REC-150 - Migrate the legacy API');
$labeledMr202['labels'] = ['legacy'];
$labeledMr205 = $mr(205, 'opened', $now - (3 * DAY), null, 1, 'REC-201 - Polish the onboarding');
$labeledMr205['labels'] = ['frontend'];

// MR 204 carries a markdown description so the frontend smoke test can assert
// the hover tooltip renders markdown (heading, strong, inline code, list,
// blockquote, fenced code block) instead of raw text.
$markdownMr = $mr(204, 'opened', $now - DAY, null, 3, 'REC-310 - Fix login redirect');
$markdownMr['description'] = implode("\n", [
    '## Summary',
    '',
    'Fixes **the redirect loop** when `oauth2` returns an expired `state`.',
    '',
    '- Validates the `state` parameter',
    '- **Bails out** with a clear error',
    '- Keeps the *return_to* query intact',
    '',
    '> The `redirect_uri` must stay whitelisted.',
    '',
    '```php',
    '$client->getRedirect($request);',
    '```',
]);
$markdownMr['labels'] = ['bugfix'];

$client->mergeRequests['all'] = [
    $mr(301, 'merged', $now - (6 * DAY), $now - (2 * DAY), 1, 'REC-101 - Ship the parser'),
    $mr(302, 'merged', $now - (20 * DAY), $now - (12 * DAY), 2, 'REC-102 - Refactor the cache'),
    $labeledMr201,
    $labeledMr202,
    $mr(203, 'closed', $now - (30 * DAY), null, 3, 'REC-300 - Try websockets'),
    $markdownMr,
    $labeledMr205,
    $mr(206, 'opened', $now - (5 * DAY), null, 2, 'REC-202 - Cache invalidation fix'),
    $mr(207, 'opened', $now - (8 * DAY), null, 3, 'REC-203 - Add an audit log'),
    $blockedMr,
    $draftMr,
];
$client->mergeRequests['opened'] = array_values(array_filter(
    $client->mergeRequests['all'],
    static fn (array $m): bool => $m['state'] === 'opened',
));
$client->mergeRequests['merged'] = array_values(array_filter(
    $client->mergeRequests['all'],
    static fn (array $m): bool => $m['state'] === 'merged',
));

$client->approvalsByIid[301] = ['approved_by' => [
    ['user' => $user(2), 'approved_at' => $iso($now - (3 * DAY))],
    ['user' => $user(3), 'approved_at' => $iso($now - DAY)],
]];
$client->approvalsByIid[302] = ['approved_by' => [
    ['user' => $user(1), 'approved_at' => $iso($now - (15 * DAY))],
]];
$client->approvalsByIid[201] = ['approved_by' => [
    ['user' => $user(2), 'approved_at' => $iso($now - DAY)],
    ['user' => $user(3), 'approved_at' => $iso($now - DAY + 3600)],
]];
$client->approvalsByIid[203] = ['approved_by' => []];
$client->approvalsByIid[204] = ['approved_by' => [
    ['user' => $user(1), 'approved_at' => $iso($now - 7200)],
    ['user' => $user(2), 'approved_at' => $iso($now - 3600)],
]];
$client->approvalsByIid[206] = ['approved_by' => [
    ['user' => $user(1), 'approved_at' => $iso($now - DAY)],
    ['user' => $user(3), 'approved_at' => $iso($now - 3600)],
]];

$client->discussionsByIid[201] = [
    ['notes' => [['system' => false, 'author' => $user(3), 'created_at' => $iso($now - DAY + 3600)]]],
];
$client->discussionsByIid[204] = [
    ['notes' => [[
        'system' => false,
        'author' => $user(2),
        'created_at' => $iso($now - 7200),
        'resolvable' => true,
        'resolved' => false,
    ]]],
];

$client->pipelinesByIid[201] = [
    [
        'id' => 10,
        'status' => 'success',
        'created_at' => $iso($now - (2 * DAY)),
        'updated_at' => $iso($now - (2 * DAY) + 600),
    ],
];
$client->pipelinesByIid[301] = [
    [
        'id' => 11,
        'status' => 'success',
        'created_at' => $iso($now - (4 * DAY)),
        'updated_at' => $iso($now - (4 * DAY) + 900),
    ],
];
$client->pipelinesByIid[204] = [
    ['id' => 12, 'status' => 'running', 'created_at' => $iso($now - 3600), 'updated_at' => $iso($now - 300)],
];
$client->jobsByPipeline[12] = [
    ['id' => 1, 'status' => 'running'],
    ['id' => 2, 'status' => 'warning'],
];

$client->commitsByIid[201] = [$commit('aaa111', 'feat: export button', $now - (2 * DAY))];
$client->commitStatsBySha['aaa111'] = ['stats' => ['additions' => 120, 'deletions' => 30]];
$client->commitsByIid[301] = [
    $commit('bbb222', 'feat: parser', $now - (6 * DAY)),
    $commit('bbb223', 'fix: edge case', $now - (5 * DAY)),
];
$client->commitStatsBySha['bbb222'] = ['stats' => ['additions' => 300, 'deletions' => 40]];
$client->commitStatsBySha['bbb223'] = ['stats' => ['additions' => 20, 'deletions' => 5]];
$client->commitsByIid[302] = [$commit('ccc333', 'refactor: cache', $now - (20 * DAY))];
$client->commitStatsBySha['ccc333'] = ['stats' => ['additions' => 500, 'deletions' => 200]];
$client->commitsByIid[202] = [$commit('ddd444', 'migrate legacy API', $now - (75 * DAY))];
$client->commitStatsBySha['ddd444'] = ['stats' => ['additions' => 800, 'deletions' => 600]];
$client->commitsByIid[203] = [$commit('eee555', 'try websockets', $now - (30 * DAY))];
$client->commitStatsBySha['eee555'] = ['stats' => ['additions' => 60, 'deletions' => 10]];
$client->commitsByIid[204] = [$commit('fff666', 'fix login redirect', $now - DAY)];
$client->commitStatsBySha['fff666'] = ['stats' => ['additions' => 15, 'deletions' => 3]];

$synchronizer->full($now);

// Rank users so the fixture demonstrates the by-MR-count dropdown ordering:
// John Roe (7), Jane Doe (4), Ann Lee (2).
$client->mrCountByAuthor = [1 => 4, 2 => 7, 3 => 2];
$synchronizer->rankUsers($now);

$builder = new ApiBuilder(
    new DbalDatasetRepository($connection),
    new MetricCalculator(),
    $config,
    new SyncStateStore($connection),
);
$payload = $builder->build('day', $now);

file_put_contents(
    __DIR__ . '/fixture-data.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
);
unlink($path);

echo "fixture written\n";
