<?php

declare(strict_types=1);

namespace App\Storage;

final class Schema
{
    public static function apply(Database $database): void
    {
        $database->execute('CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            username TEXT NOT NULL,
            avatar_url TEXT
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS projects (
            id INTEGER PRIMARY KEY,
            path_with_namespace TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT \'\',
            avatar_url TEXT
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS merge_requests (
            id INTEGER PRIMARY KEY,
            iid INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            author_id INTEGER NOT NULL,
            state TEXT NOT NULL,
            draft INTEGER NOT NULL DEFAULT 0,
            created_at INTEGER NOT NULL,
            merged_at INTEGER,
            closed_at INTEGER,
            updated_at INTEGER NOT NULL,
            web_url TEXT,
            merge_status TEXT NOT NULL DEFAULT \'\',
            has_conflicts INTEGER NOT NULL DEFAULT 0
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mr_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS discussions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mr_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            resolved INTEGER NOT NULL DEFAULT 1
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS commits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mr_id INTEGER NOT NULL,
            sha TEXT NOT NULL,
            message TEXT,
            committed_date INTEGER,
            current INTEGER NOT NULL DEFAULT 1,
            additions INTEGER,
            deletions INTEGER,
            UNIQUE (mr_id, sha)
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS pipelines (
            id INTEGER PRIMARY KEY,
            mr_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at INTEGER,
            updated_at INTEGER
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS jobs (
            id INTEGER PRIMARY KEY,
            pipeline_id INTEGER NOT NULL,
            mr_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )');

        $database->execute('CREATE TABLE IF NOT EXISTS sync_state (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');

        // Per-cycle refresh queue for the SSE live-refresh worker (app:refresh-worker).
        // Wiped and repopulated at the start of every cycle; `state` progresses
        // queued -> fetching -> done|failed. `is_new` marks an MR id discovered
        // by the cycle's list call that was not yet cached (sorted to the front).
        $database->execute('CREATE TABLE IF NOT EXISTS refresh_queue (
            mr_id INTEGER PRIMARY KEY,
            is_new INTEGER NOT NULL DEFAULT 0,
            state TEXT NOT NULL DEFAULT \'queued\',
            requests_done INTEGER NOT NULL DEFAULT 0,
            requests_expected INTEGER NOT NULL DEFAULT 4,
            enqueued_at INTEGER NOT NULL
        )');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_refresh_queue_state ON refresh_queue (state)');

        $database->execute('CREATE INDEX IF NOT EXISTS idx_merge_requests_project ON merge_requests (project_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_approvals_mr ON approvals (mr_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_discussions_mr ON discussions (mr_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_commits_mr ON commits (mr_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_pipelines_mr ON pipelines (mr_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_jobs_mr ON jobs (mr_id)');
        $database->execute('CREATE INDEX IF NOT EXISTS idx_jobs_pipeline ON jobs (pipeline_id)');

        // Columns added after the initial release: `CREATE TABLE IF NOT EXISTS`
        // is a no-op for an existing database, so existing caches need the
        // columns added explicitly. Defaults keep old rows safe until the next
        // sync refreshes them.
        self::addColumnIfMissing(
            $database,
            'merge_requests',
            'merge_status',
            "TEXT NOT NULL DEFAULT ''",
        );
        self::addColumnIfMissing($database, 'merge_requests', 'has_conflicts', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($database, 'discussions', 'resolved', 'INTEGER NOT NULL DEFAULT 1');
        // Project display name and avatar for the leftmost MR-list column. Old
        // rows get an empty name (the frontend falls back to the path) and no
        // avatar until the next sync refreshes them.
        self::addColumnIfMissing($database, 'projects', 'name', "TEXT NOT NULL DEFAULT ''");
        self::addColumnIfMissing($database, 'projects', 'avatar_url', 'TEXT');
        // Per-user all-time MR count and the time it was last recomputed, written by
        // the daily `app:rank-users` job. Defaults keep old rows at "0 / never" until
        // the next rank run; sync's UPSERT never wipes them.
        self::addColumnIfMissing($database, 'users', 'mr_count', 'INTEGER NOT NULL DEFAULT 0');
        self::addColumnIfMissing($database, 'users', 'ranked_at', 'INTEGER');
    }

    /**
     * Adds a column to an existing table if it is not present already.
     */
    private static function addColumnIfMissing(
        Database $database,
        string $table,
        string $column,
        string $definition,
    ): void {
        foreach ($database->query('PRAGMA table_info(' . $table . ')') as $row) {
            if ((string) ($row['name'] ?? '') === $column) {
                return;
            }
        }

        $database->execute('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
    }
}
