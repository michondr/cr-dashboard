<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\Slack;

use App\Config\AppConfig;
use App\Shared\Infrastructure\Persistence\SqliteDateTime;
use App\Shared\Infrastructure\Persistence\SqliteRows;
use App\Shared\Infrastructure\Persistence\SyncStateStore;
use Doctrine\DBAL\Connection;

use function curl_exec;
use function curl_init;
use function curl_setopt;
use function implode;
use function json_encode;
use function max;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * Phase 2 - Slack notifications for new and stale MRs, posted as a flag on the
 * sync command. On sync failure the notifications are still posted from the
 * cached data and the message states the failure and its reason.
 */
final class SlackNotifier
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SyncStateStore $syncState,
        private readonly AppConfig $config,
    ) {
    }

    /**
     * Posts the "new MR" message immediately when the refresh worker's cycle
     * list call discovers an MR id not yet cached (item 3), rather than
     * waiting for the nightly run. Bumps `last_notify` forward to (at least)
     * this MR's `created_at` so the nightly run's `newMrsSince()` diff does
     * not notify about it again.
     */
    public function notifyNewMr(int $mrId): void
    {
        if ($this->config->slackToken === '' || $this->config->slackChannel === '') {
            return;
        }

        $row = SqliteRows::first($this->connection, 'SELECT * FROM merge_requests WHERE id = ?', [$mrId]);
        if ($row === null) {
            return;
        }

        $mr = $this->decorateMr($row);
        $this->post($this->formatNewMrs([$mr]));

        $createdAt = (int) $row['created_at'];
        $lastNotify = $this->getLastNotify($createdAt);
        if ($createdAt > $lastNotify) {
            $this->setLastNotify($createdAt);
        }
    }

    public function notify(int $now, null|string $syncFailure = null): void
    {
        if ($this->config->slackToken === '' || $this->config->slackChannel === '') {
            return;
        }

        $messages = $this->buildMessages($now, $syncFailure);
        $this->setLastNotify($now);

        if ($messages === []) {
            return;
        }

        $this->post(implode("\n\n", $messages));
    }

    /**
     * @return list<string>
     */
    public function buildMessages(int $now, null|string $syncFailure = null): array
    {
        $lastNotify = $this->getLastNotify($now);
        $newMrs = $this->newMrsSince($lastNotify);
        $staleMrs = $this->staleMrsSince($lastNotify, $now);

        $messages = [];
        if ($newMrs !== []) {
            $messages[] = $this->formatNewMrs($newMrs);
        }
        if ($staleMrs !== []) {
            $messages[] = $this->formatStaleMrs($staleMrs);
        }
        if ($syncFailure !== null) {
            $messages[] = 'Sync failed: ' . $syncFailure;
        }

        return $messages;
    }

    private function getLastNotify(int $now): int
    {
        $value = $this->syncState->get('last_notify');
        if ($value === null) {
            // First enablement: only MRs created after now are notified.
            $this->setLastNotify($now);

            return $now;
        }

        return (int) $value;
    }

    private function setLastNotify(int $now): void
    {
        $this->syncState->set('last_notify', (string) $now);
    }

    /**
     * @return list<array{title: string, author: string, url: string, needed: int}>
     */
    private function newMrsSince(int $lastNotify): array
    {
        $rows = SqliteRows::list(
            $this->connection,
            'SELECT * FROM merge_requests WHERE created_at > ?',
            [SqliteDateTime::toStorage($lastNotify)],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->decorateMr($row);
        }

        return $result;
    }

    /**
     * @return list<array{title: string, author: string, url: string, needed: int}>
     */
    private function staleMrsSince(int $lastNotify, int $now): array
    {
        $staleSeconds = AppConfig::STALE_DAYS * 86400;
        $rows = SqliteRows::list(
            $this->connection,
            'SELECT * FROM merge_requests
             WHERE state = ? AND created_at > ? AND created_at <= ?',
            [
                'opened',
                SqliteDateTime::toStorage($lastNotify - $staleSeconds),
                SqliteDateTime::toStorage($now - $staleSeconds),
            ],
        );
        $result = [];
        foreach ($rows as $row) {
            $result[] = $this->decorateMr($row);
        }

        return $result;
    }

    /**
     * @param array<string, int|float|string|null> $row
     *
     * @return array{title: string, author: string, url: string, needed: int}
     */
    private function decorateMr(array $row): array
    {
        $authorId = (int) $row['author_id'];
        $authorName = SqliteRows::value($this->connection, 'SELECT name FROM users WHERE id = ?', [$authorId]);
        $approvals = (int) SqliteRows::value(
            $this->connection,
            'SELECT COUNT(*) FROM approvals WHERE merge_request_id = ?',
            [(int) $row['id']],
        );

        return [
            'title' => (string) $row['title'],
            'author' => $authorName === null ? 'unknown' : (string) $authorName,
            'url' => (string) ($row['web_url'] ?? ''),
            'needed' => max(0, $this->config->requiredApprovals - $approvals),
        ];
    }

    /**
     * @param list<array{title: string, author: string, url: string, needed: int}> $mrs
     */
    private function formatNewMrs(array $mrs): string
    {
        $lines = ['New MRs since last check:'];
        foreach ($mrs as $mr) {
            $lines[] = sprintf(
                '- %s by %s — %s — needs %d more approvals',
                $mr['title'],
                $mr['author'],
                $mr['url'],
                $mr['needed'],
            );
        }
        if ($this->config->appUrl !== '') {
            $lines[] = 'Dashboard: ' . $this->config->appUrl;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array{title: string, author: string, url: string, needed: int}> $mrs
     */
    private function formatStaleMrs(array $mrs): string
    {
        $lines = [];
        foreach ($mrs as $mr) {
            $line = sprintf('%s by %s turned stale — %s', $mr['title'], $mr['author'], $mr['url']);
            if ($this->config->appUrl !== '') {
                $line .= ' — Dashboard: ' . $this->config->appUrl;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    private function post(string $text): void
    {
        $payload = json_encode(
            ['channel' => $this->config->slackChannel, 'text' => $text],
            JSON_THROW_ON_ERROR,
        );

        $handle = curl_init('https://slack.com/api/chat.postMessage');
        if ($handle === false) {
            return;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($handle, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->config->slackToken,
            'Content-Type: application/json',
        ]);
        curl_exec($handle);
    }
}
