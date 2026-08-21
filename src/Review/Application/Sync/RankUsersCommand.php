<?php

declare(strict_types=1);

namespace App\Review\Application\Sync;

use App\Review\Infrastructure\GitLab\GitLabException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function time;

/**
 * Recomputes each user's all-time MR count from GitLab so the "My view" user list can
 * be ordered by activity. Decoupled from `app:sync` and run daily via cron; it never wipes
 * a count it cannot refresh, so a GitLab hiccup leaves the previous ranking intact.
 */
#[AsCommand(
    name: 'app:rank-users',
    description: 'Recompute per-user all-time MR counts from GitLab for user-list ordering.',
)]
final class RankUsersCommand extends Command
{
    public function __construct(
        private readonly Synchronizer $synchronizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $this->synchronizer->rankUsers(time(), $io);
        } catch (GitLabException $e) {
            $io->error('Ranking failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Ranking complete.');

        return Command::SUCCESS;
    }
}
