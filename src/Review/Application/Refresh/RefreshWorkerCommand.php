<?php

declare(strict_types=1);

namespace App\Review\Application\Refresh;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function time;
use function usleep;

/**
 * Long-running worker (a supervisord program, docker/supervisord.conf) that
 * polls the refresh queue and drives cycles. Replaces the 15-minute cron and
 * the `SyncTrigger` stale-while-revalidate spawn: this single process owns
 * the GitLab RPS budget for on-demand refreshes.
 */
#[AsCommand(
    name: 'app:refresh-worker',
    description: 'Poll the SSE refresh queue and drive per-MR refresh cycles.',
)]
final class RefreshWorkerCommand extends Command
{
    /** Idle poll interval when there is nothing to do. */
    private const IDLE_SLEEP_MICROSECONDS = 500_000;

    /** Set false to stop the loop; only ever flipped by tests. */
    private bool $running = true;

    public function __construct(private readonly RefreshWorker $worker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while ($this->running) {
            // The worker must survive anything a tick throws (transient DB
            // lock, unreachable hub, unexpected GitLab payload): log and keep
            // polling instead of dying and leaving supervisord to give up
            // after a few fast crashes (FATAL = no more refreshes at all).
            try {
                $didWork = $this->worker->tick(time());
            } catch (Throwable $e) {
                $output->writeln('tick failed: ' . $e->getMessage());
                $didWork = false;
            }
            if (!$didWork) {
                usleep(self::IDLE_SLEEP_MICROSECONDS);
            }
        }

        return Command::SUCCESS;
    }
}
