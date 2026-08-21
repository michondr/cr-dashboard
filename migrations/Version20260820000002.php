<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Converts the baseline cache schema to the Doctrine-native shape the DBAL
 * repositories write once the sync/read code has moved onto them: UTC
 * DATETIME columns ('Y-m-d H:i:s', what {@see SqliteDateTime::toStorage}
 * produces) instead of epoch-second INTEGERs, `merge_request_id` instead of
 * `mr_id`, and BOOLEAN columns for the 0/1 flags. SQLite cannot ALTER a
 * column's type or rename a column, so every affected table is rebuilt:
 * CREATE the new shape, INSERT ... SELECT with `datetime(col, 'unixepoch')`,
 * DROP the old, RENAME. Existing data is preserved through the conversion.
 */
final class Version20260820000002 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $this->rebuildUsers();
        $this->rebuildMergeRequests();
        $this->rebuildApprovals();
        $this->rebuildDiscussions();
        $this->rebuildCommits();
        $this->rebuildPipelines();
        $this->rebuildJobs();
    }

    public function down(Schema $schema): void
    {
        $this->rebuildUsersToLegacy();
        $this->rebuildMergeRequestsToLegacy();
        $this->rebuildApprovalsToLegacy();
        $this->rebuildDiscussionsToLegacy();
        $this->rebuildCommitsToLegacy();
        $this->rebuildPipelinesToLegacy();
        $this->rebuildJobsToLegacy();
    }

    private function rebuildUsers(): void
    {
        $this->addSql('CREATE TABLE users_new (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            username TEXT NOT NULL,
            avatar_url TEXT,
            mr_count INTEGER NOT NULL DEFAULT 0,
            ranked_at DATETIME
        )');
        $this->addSql(
            'INSERT INTO users_new (id, name, username, avatar_url, mr_count, ranked_at)
             SELECT id, name, username, avatar_url, mr_count, datetime(ranked_at, \'unixepoch\')
             FROM users',
        );
        $this->addSql('DROP TABLE users');
        $this->addSql('ALTER TABLE users_new RENAME TO users');
    }

    private function rebuildMergeRequests(): void
    {
        $this->addSql('CREATE TABLE merge_requests_new (
            id INTEGER PRIMARY KEY,
            iid INTEGER NOT NULL,
            project_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            description TEXT,
            author_id INTEGER NOT NULL,
            state TEXT NOT NULL,
            draft INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            merged_at DATETIME,
            closed_at DATETIME,
            updated_at DATETIME NOT NULL,
            web_url TEXT,
            merge_status TEXT NOT NULL DEFAULT \'\',
            has_conflicts INTEGER NOT NULL DEFAULT 0,
            labels TEXT NOT NULL DEFAULT \'[]\'
        )');
        $this->addSql(
            'INSERT INTO merge_requests_new (
                id, iid, project_id, title, description, author_id, state, draft,
                created_at, merged_at, closed_at, updated_at, web_url,
                merge_status, has_conflicts, labels
             )
             SELECT
                id, iid, project_id, title, description, author_id, state, draft,
                datetime(created_at, \'unixepoch\'), datetime(merged_at, \'unixepoch\'),
                datetime(closed_at, \'unixepoch\'), datetime(updated_at, \'unixepoch\'),
                web_url, merge_status, has_conflicts, labels
             FROM merge_requests',
        );
        $this->addSql('DROP TABLE merge_requests');
        $this->addSql('ALTER TABLE merge_requests_new RENAME TO merge_requests');
        $this->addSql('CREATE INDEX idx_merge_requests_project ON merge_requests (project_id)');
    }

    private function rebuildApprovals(): void
    {
        $this->addSql('CREATE TABLE approvals_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            merge_request_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL
        )');
        $this->addSql(
            'INSERT INTO approvals_new (id, merge_request_id, user_id, created_at)
             SELECT id, mr_id, user_id, datetime(created_at, \'unixepoch\') FROM approvals',
        );
        $this->addSql('DROP TABLE approvals');
        $this->addSql('ALTER TABLE approvals_new RENAME TO approvals');
        $this->addSql('CREATE INDEX idx_approvals_merge_request ON approvals (merge_request_id)');
    }

    private function rebuildDiscussions(): void
    {
        $this->addSql('CREATE TABLE discussions_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            merge_request_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            resolved INTEGER NOT NULL DEFAULT 1
        )');
        $this->addSql(
            'INSERT INTO discussions_new (id, merge_request_id, user_id, created_at, resolved)
             SELECT id, mr_id, user_id, datetime(created_at, \'unixepoch\'), resolved FROM discussions',
        );
        $this->addSql('DROP TABLE discussions');
        $this->addSql('ALTER TABLE discussions_new RENAME TO discussions');
        $this->addSql('CREATE INDEX idx_discussions_merge_request ON discussions (merge_request_id)');
    }

    private function rebuildCommits(): void
    {
        $this->addSql('CREATE TABLE commits_new (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            merge_request_id INTEGER NOT NULL,
            sha TEXT NOT NULL,
            message TEXT,
            committed_date DATETIME,
            current INTEGER NOT NULL DEFAULT 1,
            additions INTEGER,
            deletions INTEGER,
            UNIQUE (merge_request_id, sha)
        )');
        $this->addSql(
            'INSERT INTO commits_new (
                id, merge_request_id, sha, message, committed_date, current, additions, deletions
             )
             SELECT
                id, mr_id, sha, message, datetime(committed_date, \'unixepoch\'),
                current, additions, deletions
             FROM commits',
        );
        $this->addSql('DROP TABLE commits');
        $this->addSql('ALTER TABLE commits_new RENAME TO commits');
        $this->addSql('CREATE INDEX idx_commits_merge_request ON commits (merge_request_id)');
    }

    private function rebuildPipelines(): void
    {
        $this->addSql('CREATE TABLE pipelines_new (
            id INTEGER PRIMARY KEY,
            merge_request_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at DATETIME,
            updated_at DATETIME
        )');
        $this->addSql(
            'INSERT INTO pipelines_new (id, merge_request_id, status, created_at, updated_at)
             SELECT
                id, mr_id, status, datetime(created_at, \'unixepoch\'),
                datetime(updated_at, \'unixepoch\')
             FROM pipelines',
        );
        $this->addSql('DROP TABLE pipelines');
        $this->addSql('ALTER TABLE pipelines_new RENAME TO pipelines');
        $this->addSql('CREATE INDEX idx_pipelines_merge_request ON pipelines (merge_request_id)');
    }

    private function rebuildJobs(): void
    {
        $this->addSql('CREATE TABLE jobs_new (
            id INTEGER PRIMARY KEY,
            pipeline_id INTEGER NOT NULL,
            merge_request_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )');
        $this->addSql(
            'INSERT INTO jobs_new (id, pipeline_id, merge_request_id, status)
             SELECT id, pipeline_id, mr_id, status FROM jobs',
        );
        $this->addSql('DROP TABLE jobs');
        $this->addSql('ALTER TABLE jobs_new RENAME TO jobs');
        $this->addSql('CREATE INDEX idx_jobs_merge_request ON jobs (merge_request_id)');
        $this->addSql('CREATE INDEX idx_jobs_pipeline ON jobs (pipeline_id)');
    }

    private function rebuildUsersToLegacy(): void
    {
        $this->addSql('CREATE TABLE users_old (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            username TEXT NOT NULL,
            avatar_url TEXT,
            mr_count INTEGER NOT NULL DEFAULT 0,
            ranked_at INTEGER
        )');
        $this->addSql(
            'INSERT INTO users_old (id, name, username, avatar_url, mr_count, ranked_at)
             SELECT id, name, username, avatar_url, mr_count, strftime(\'%s\', ranked_at) FROM users',
        );
        $this->addSql('DROP TABLE users');
        $this->addSql('ALTER TABLE users_old RENAME TO users');
    }

    private function rebuildMergeRequestsToLegacy(): void
    {
        $this->addSql('CREATE TABLE merge_requests_old (
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
        $this->addSql(
            'INSERT INTO merge_requests_old (
                id, iid, project_id, title, description, author_id, state, draft,
                created_at, merged_at, closed_at, updated_at, web_url,
                merge_status, has_conflicts, labels
             )
             SELECT
                id, iid, project_id, title, description, author_id, state, draft,
                strftime(\'%s\', created_at), strftime(\'%s\', merged_at),
                strftime(\'%s\', closed_at), strftime(\'%s\', updated_at),
                web_url, merge_status, has_conflicts, labels
             FROM merge_requests',
        );
        $this->addSql('DROP TABLE merge_requests');
        $this->addSql('ALTER TABLE merge_requests_old RENAME TO merge_requests');
        $this->addSql('CREATE INDEX idx_merge_requests_project ON merge_requests (project_id)');
    }

    private function rebuildApprovalsToLegacy(): void
    {
        $this->addSql('CREATE TABLE approvals_old (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mr_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL
        )');
        $this->addSql(
            'INSERT INTO approvals_old (id, mr_id, user_id, created_at)
             SELECT id, merge_request_id, user_id, strftime(\'%s\', created_at) FROM approvals',
        );
        $this->addSql('DROP TABLE approvals');
        $this->addSql('ALTER TABLE approvals_old RENAME TO approvals');
        $this->addSql('CREATE INDEX idx_approvals_mr ON approvals (mr_id)');
    }

    private function rebuildDiscussionsToLegacy(): void
    {
        $this->addSql('CREATE TABLE discussions_old (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mr_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            created_at INTEGER NOT NULL,
            resolved INTEGER NOT NULL DEFAULT 1
        )');
        $this->addSql(
            'INSERT INTO discussions_old (id, mr_id, user_id, created_at, resolved)
             SELECT id, merge_request_id, user_id, strftime(\'%s\', created_at), resolved
             FROM discussions',
        );
        $this->addSql('DROP TABLE discussions');
        $this->addSql('ALTER TABLE discussions_old RENAME TO discussions');
        $this->addSql('CREATE INDEX idx_discussions_mr ON discussions (mr_id)');
    }

    private function rebuildCommitsToLegacy(): void
    {
        $this->addSql('CREATE TABLE commits_old (
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
        $this->addSql(
            'INSERT INTO commits_old (
                id, mr_id, sha, message, committed_date, current, additions, deletions
             )
             SELECT
                id, merge_request_id, sha, message, strftime(\'%s\', committed_date),
                current, additions, deletions
             FROM commits',
        );
        $this->addSql('DROP TABLE commits');
        $this->addSql('ALTER TABLE commits_old RENAME TO commits');
        $this->addSql('CREATE INDEX idx_commits_mr ON commits (mr_id)');
    }

    private function rebuildPipelinesToLegacy(): void
    {
        $this->addSql('CREATE TABLE pipelines_old (
            id INTEGER PRIMARY KEY,
            mr_id INTEGER NOT NULL,
            status TEXT NOT NULL,
            created_at INTEGER,
            updated_at INTEGER
        )');
        $this->addSql(
            'INSERT INTO pipelines_old (id, mr_id, status, created_at, updated_at)
             SELECT
                id, merge_request_id, status, strftime(\'%s\', created_at),
                strftime(\'%s\', updated_at)
             FROM pipelines',
        );
        $this->addSql('DROP TABLE pipelines');
        $this->addSql('ALTER TABLE pipelines_old RENAME TO pipelines');
        $this->addSql('CREATE INDEX idx_pipelines_mr ON pipelines (mr_id)');
    }

    private function rebuildJobsToLegacy(): void
    {
        $this->addSql('CREATE TABLE jobs_old (
            id INTEGER PRIMARY KEY,
            pipeline_id INTEGER NOT NULL,
            mr_id INTEGER NOT NULL,
            status TEXT NOT NULL
        )');
        $this->addSql(
            'INSERT INTO jobs_old (id, pipeline_id, mr_id, status)
             SELECT id, pipeline_id, merge_request_id, status FROM jobs',
        );
        $this->addSql('DROP TABLE jobs');
        $this->addSql('ALTER TABLE jobs_old RENAME TO jobs');
        $this->addSql('CREATE INDEX idx_jobs_mr ON jobs (mr_id)');
        $this->addSql('CREATE INDEX idx_jobs_pipeline ON jobs (pipeline_id)');
    }
}
