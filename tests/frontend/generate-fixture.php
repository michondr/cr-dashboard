<?php

declare(strict_types=1);

use App\Api\ApiBuilder;
use App\Metrics\MetricCalculator;
use App\Storage\Database;
use App\Sync\Synchronizer;
use App\Tests\Support\FakeGitLabClient;
use App\Tests\Support\TestAppConfig;

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
$database = new Database($config);
$synchronizer = new Synchronizer($client, $database, $config);

$client->projects = [
    ['id' => 1, 'path_with_namespace' => 'group/app'],
];

$blockedMr = $mr(208, 'opened', $now - (2 * DAY), null, 2, 'REC-204 - Export the web');
$blockedMr['merge_status'] = 'cannot_be_merged';
$blockedMr['has_conflicts'] = true;
$draftMr = $mr(209, 'opened', $now - DAY, null, 1, 'REC-205 - Draft the docs');
$draftMr['draft'] = true;

$client->mergeRequests['all'] = [
    $mr(301, 'merged', $now - (6 * DAY), $now - (2 * DAY), 1, 'REC-101 - Ship the parser'),
    $mr(302, 'merged', $now - (20 * DAY), $now - (12 * DAY), 2, 'REC-102 - Refactor the cache'),
    $mr(201, 'opened', $now - (2 * DAY), null, 1, 'REC-200 - Add an export button'),
    $mr(202, 'opened', $now - (75 * DAY), null, 2, 'REC-150 - Migrate the legacy API'),
    $mr(203, 'closed', $now - (30 * DAY), null, 3, 'REC-300 - Try websockets'),
    $mr(204, 'opened', $now - DAY, null, 3, 'REC-310 - Fix login redirect'),
    $mr(205, 'opened', $now - (3 * DAY), null, 1, 'REC-201 - Polish the onboarding'),
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

$builder = new ApiBuilder($database, new MetricCalculator(), $config, $synchronizer);
$payload = $builder->build('day', $now);

file_put_contents(
    __DIR__ . '/fixture-data.json',
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
);
unlink($path);

echo "fixture written\n";
