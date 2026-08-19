<?php

declare(strict_types=1);

$page = array_key_exists('page', $_GET) ? (int) $_GET['page'] : 1;

if ($page === 1) {
    header(
        'Link: <http://127.0.0.1:' . $_SERVER['SERVER_PORT']
        . '/api/v4/groups/group/merge_requests?page=2>; rel="next"',
    );
    echo json_encode([
        ['id' => 1, 'title' => 'first-page-a'],
        ['id' => 2, 'title' => 'first-page-b'],
    ]);
} else {
    echo json_encode([
        ['id' => 3, 'title' => 'second-page-a'],
    ]);
}
