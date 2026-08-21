<?php

declare(strict_types=1);

namespace App\Review\Application\Sync;

use App\Review\Infrastructure\GitLab\GitLabException;
use App\Review\Infrastructure\Slack\SlackNotifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function time;

#[AsCommand(
    name: 'app:sync',
    description: 'Fetch merge request data from GitLab and cache it in SQLite.',
)]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly Synchronizer $synchronizer,
        private readonly SlackNotifier $slackNotifier,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'full',
                null,
                InputOption::VALUE_NONE,
                'One-time full backfill of all projects, MRs and sub-resources',
            )
            ->addOption(
                'refresh-open',
                null,
                InputOption::VALUE_NONE,
                'Re-fetch sub-resources for all open MRs and prune retained MRs',
            )
            ->addOption(
                'notify-slack',
                null,
                InputOption::VALUE_NONE,
                'After the sync, post Slack notifications for new and stale MRs',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $notify = (bool) $input->getOption('notify-slack');
        $now = time();

        try {
            if ($input->getOption('full')) {
                $this->synchronizer->full($now, $io);
            } elseif ($input->getOption('refresh-open')) {
                $this->synchronizer->refreshOpen($now, $io);
            } else {
                $this->synchronizer->incremental($now, $io);
            }
        } catch (SyncLockedException $e) {
            $io->comment('Another sync is already running; skipping.');

            return Command::SUCCESS;
        } catch (GitLabException $e) {
            $io->error('Sync failed: ' . $e->getMessage());
            if ($notify) {
                $this->slackNotifier->notify($now, $e->getMessage());
            }

            return Command::FAILURE;
        }

        if ($notify) {
            $this->slackNotifier->notify($now);
        }

        $io->success('Sync complete.');

        return Command::SUCCESS;
    }
}
