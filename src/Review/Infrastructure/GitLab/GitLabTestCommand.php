<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\GitLab;

use App\Config\AppConfig;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_array;
use function is_string;
use function json_decode;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use function trim;

/**
 * Connectivity probe: verifies the GitLab base URL, credentials and the
 * configured group/projects endpoints respond, with per-request timing so a
 * slow or unreachable host is obvious instead of looking like a frozen sync.
 */
#[AsCommand(
    name: 'app:gitlab:test',
    description: 'Probe GitLab reachability, credentials and group access.',
)]
final class GitLabTestCommand extends Command
{
    public function __construct(
        private readonly GitLabClientInterface $client,
        private readonly AppConfig $config,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = $this->config->gitlabUrl;
        $group = $this->config->gitlabGroup;
        $token = $this->config->gitlabToken;

        $io->title('GitLab connectivity probe');
        $io->definitionList(
            ['URL' => $url !== '' ? $url : '(not set)'],
            ['Group' => $group !== '' ? $group : '(not set)'],
            ['Token' => $this->maskToken($token)],
        );

        if ($url === '') {
            $io->error('GITLAB_URL is not configured.');

            return Command::FAILURE;
        }

        $ok = $this->probe($io, 'API base', 'version');

        if ($group !== '') {
            $ok = $this->probe($io, 'Group', 'groups/' . $group) && $ok;
            $ok = $this->probe(
                $io,
                'Projects (subgroups)',
                'groups/' . $group . '/projects',
                ['include_subgroups' => 'true', 'per_page' => 1],
            ) && $ok;
        } else {
            $io->warning('GITLAB_GROUP is not set; skipping group and project checks.');
        }

        if ($ok) {
            $io->success('GitLab is reachable and the group is accessible.');

            return Command::SUCCESS;
        }

        $io->error('GitLab probe failed; see details above.');

        return Command::FAILURE;
    }

    /**
     * @param array<string, int|string> $query
     */
    private function probe(SymfonyStyle $io, string $label, string $path, array $query = []): bool
    {
        $result = $this->client->rawGet($path, $query);
        $status = $result['status'];
        $statusText = $status === 0 ? 'FAIL' : 'HTTP ' . $status;

        $io->text(sprintf(
            '  %-22s %-9s %6.2fs  %s',
            $label,
            $statusText,
            $result['seconds'],
            $this->detail($label, $result['body'], $status),
        ));

        return $status !== 0 && $status < 400;
    }

    private function detail(string $label, string $body, int $status): string
    {
        if ($status === 0 || $status >= 400) {
            return $this->clip($body);
        }

        if ($label === 'API base') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && is_string($decoded['version'] ?? null)) {
                return 'GitLab ' . $decoded['version'];
            }
        }

        return '';
    }

    private function clip(string $body): string
    {
        $trimmed = trim($body);
        if (strlen($trimmed) <= 160) {
            return $trimmed;
        }

        return substr($trimmed, 0, 160) . '...';
    }

    private function maskToken(string $token): string
    {
        if ($token === '') {
            return '(not set)';
        }

        $length = strlen($token);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($token, -4);
    }
}
