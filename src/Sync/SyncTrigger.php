<?php

declare(strict_types=1);

namespace App\Sync;

use App\Config\AppConfig;
use App\Storage\Database;

use function escapeshellarg;
use function exec;

/**
 * Spawns a detached `app:sync` from a web request when the cache is stale.
 * The web process never acquires the sync lock itself; it only checks it, and
 * a `sync_queued_at` marker throttles spawning to once per refresh window so
 * concurrent users share one background sync.
 */
final class SyncTrigger
{
    public function __construct(
        private readonly Database $database,
        private readonly AppConfig $config,
    ) {
    }

    public function maybeSpawn(int $now): void
    {
        $lock = $this->database->queryValue("SELECT value FROM sync_state WHERE key = 'sync_lock'");
        if ($lock !== null && $now - (int) $lock < AppConfig::LOCK_TIMEOUT_SECONDS) {
            return;
        }

        $queued = $this->database->queryValue("SELECT value FROM sync_state WHERE key = 'sync_queued_at'");
        if ($queued !== null && $now - (int) $queued < AppConfig::CACHE_FRESH_SECONDS) {
            return;
        }

        $this->database->execute(
            "INSERT OR REPLACE INTO sync_state (key, value) VALUES ('sync_queued_at', ?)",
            [(string) $now],
        );

        $php = escapeshellarg(PHP_BINARY);
        $console = escapeshellarg($this->config->projectRoot . '/bin/console');
        exec($php . ' ' . $console . ' app:sync > /dev/null 2>&1 &');
    }
}
