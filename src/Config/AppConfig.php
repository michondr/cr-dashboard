<?php

declare(strict_types=1);

namespace App\Config;

final readonly class AppConfig
{
    public const STALE_DAYS = 60;
    public const WINDOW_DAYS = 60;
    public const COVERAGE_WINDOW_DAYS = 30;
    public const MERGED_WINDOW_DAYS = 30;
    public const SYNC_INTERVAL_SECONDS = 900;
    public const CACHE_FRESH_SECONDS = 60;
    public const LOCK_TIMEOUT_SECONDS = 1800;

    public function __construct(
        public string $databasePath,
        public string $gitlabUrl,
        public string $gitlabGroup,
        public string $gitlabToken,
        public float $gitlabRps,
        /** @var list<string> */
        public array $gitlabProjects,
        public int $retentionDays,
        public int $requiredApprovals,
        public string $jiraUrl,
        public string $slackToken,
        public string $slackChannel,
        public string $appUrl,
        public string $projectRoot,
    ) {
    }
}
