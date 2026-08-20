<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Baseline schema, byte-for-byte what the pre-refactor `App\Storage\Schema::apply`
 * produced for a fresh database (all columns that were ever added live directly
 * in the CREATE TABLEs). The write path still runs the legacy shape; a follow-up
 * migration converts the tables to the Doctrine-native types the new repositories
 * target once the sync/read code has moved onto them.
 */
final class Version20260820000001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            username TEXT NOT NULL,
            avatar_url TEXT,
            mr_count INTEGER NOT NULL DEFAULT 0,
            ranked_at INTEGER
        )');

        $this->addSql('CREATE TABLE projects (
            id INTEGER PRIMARY KEY,
            path_with_namespace TEXT NOT NULL,
            name TEXT NOT NULL DEFAULT \'\',
            avatar_url TEXT
        )');

        $this->addSql('CREATE TABLE merge_requests (
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
            has_conflicts INTEGER NOT NULL DEFAULT 0,
            labels TEXT NOT NULL DEFAULT \'[]\'
        )');

        $this->addSql('CREATE TABLE approvals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mr_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )');

        $this->addSql('CREATE TABLE discussions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mr_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            resolved INTEGER NOT NULL DEFAULT 1
        )');

        $this->addSql('CREATE TABLE commits (
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

        $this->addSql('CREATE TABLE pipelines (
            id INTEGER PRIMARY KEY,
            mr_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at INTEGER,
            updated_at INTEGER
        )');

        $this->addSql('CREATE TABLE jobs (
            id INTEGER PRIMARY KEY,
            pipeline_id INTEGER NOT NULL,
            mr_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )');

        $this->addSql('CREATE TABLE sync_state (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )');

        $this->addSql('CREATE TABLE refresh_queue (
            mr_id INTEGER PRIMARY KEY,
            is_new INTEGER NOT NULL DEFAULT 0,
            state TEXT NOT NULL DEFAULT \'queued\',
            requests_done INTEGER NOT NULL DEFAULT 0,
            requests_expected INTEGER NOT NULL DEFAULT 4,
            enqueued_at INTEGER NOT NULL
        )');

        $this->addSql('CREATE INDEX idx_refresh_queue_state ON refresh_queue (state)');
        $this->addSql('CREATE INDEX idx_merge_requests_project ON merge_requests (project_id)');
        $this->addSql('CREATE INDEX idx_approvals_mr ON approvals (mr_id)');
        $this->addSql('CREATE INDEX idx_discussions_mr ON discussions (mr_id)');
        $this->addSql('CREATE INDEX idx_commits_mr ON commits (mr_id)');
        $this->addSql('CREATE INDEX idx_pipelines_mr ON pipelines (mr_id)');
        $this->addSql('CREATE INDEX idx_jobs_mr ON jobs (mr_id)');
        $this->addSql('CREATE INDEX idx_jobs_pipeline ON jobs (pipeline_id)');
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES_IN_REVERSE as $table) {
            $this->addSql('DROP TABLE IF EXISTS ' . $table);
        }
    }

    private const TABLES_IN_REVERSE = [
        'refresh_queue',
        'sync_state',
        'jobs',
        'pipelines',
        'commits',
        'discussions',
        'approvals',
        'merge_requests',
        'projects',
        'users',
    ];
}
