<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\AppConfig;

use function array_merge;
use function dirname;

final class TestAppConfig
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function create(string $databasePath, array $overrides = []): AppConfig
    {
        $arguments = self::defaults($databasePath, $overrides);
        /** @var array{
         *   databasePath: string,
         *   gitlabUrl: string,
         *   gitlabGroup: string,
         *   gitlabToken: string,
         *   gitlabRps: float,
         *   gitlabProjects: list<string>,
         *   retentionDays: int,
         *   requiredApprovals: int,
         *   jiraUrl: string,
         *   slackToken: string,
         *   slackChannel: string,
         *   appUrl: string,
         *   projectRoot: string
         * } $arguments
         */

        return new AppConfig(...$arguments);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function defaults(string $databasePath, array $overrides): array
    {
        return array_merge(
            [
                'databasePath' => $databasePath,
                'gitlabUrl' => 'https://gitlab.example.test',
                'gitlabGroup' => 'group',
                'gitlabToken' => 'test-token',
                'gitlabRps' => 1000.0,
                'gitlabProjects' => [],
                'retentionDays' => 90,
                'requiredApprovals' => 2,
                'jiraUrl' => 'https://jira.example.test/browse/',
                'slackToken' => '',
                'slackChannel' => '',
                'appUrl' => '',
                'projectRoot' => dirname(__DIR__, 2),
            ],
            $overrides,
        );
    }
}
