<?php

declare(strict_types=1);

namespace App\Refresh;

use App\Config\AppConfig;
use App\GitLab\GitLabClientInterface;
use App\GitLab\GitLabException;
use App\Sync\SlackNotifier;
use App\Sync\Synchronizer;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

use function array_key_exists;
use function gmdate;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;

/**
 * Drives one refresh cycle at a time (see docs/feature-sse-refresh.md item 2).
 * `tick()` does at most one unit of work and returns whether it did anything,
 * so the console command can loop it and tests can step it deterministically
 * without a real event loop or sleep.
 *
 * This process owns the whole GitLab RPS budget (App\GitLab\Client's blocking
 * throttle): it is the only thing that talks to GitLab outside the cron syncs.
 */
final class RefreshWorker
{
    /** @var array<int, array<array-key, mixed>> mr_id => raw GitLab MR payload, for the running cycle. */
    private array $cyclePayloads = [];

    public function __construct(
        private readonly RefreshQueue $queue,
        private readonly GitLabClientInterface $client,
        private readonly Synchronizer $synchronizer,
        private readonly SlackNotifier $slackNotifier,
        private readonly HubInterface $hub,
        private readonly AppConfig $config,
    ) {
    }

    public function tick(int $now): bool
    {
        if (!$this->queue->isActive()) {
            return $this->maybeStartCycle($now);
        }

        return $this->processNextJob($now);
    }

    private function maybeStartCycle(int $now): bool
    {
        if (!$this->queue->hasPendingRequest()) {
            return false;
        }

        $userId = $this->queue->pendingUserId();
        $this->queue->clearPending();
        $this->queue->beginCycle($now, $userId);

        $lastSync = $this->synchronizer->lastSync() ?? ($now - 60);
        $mrs = $this->client->groupMergeRequests($this->config->gitlabGroup, [
            'state' => 'opened',
            'updated_after' => gmdate(DATE_ATOM, $lastSync - 60),
        ]);

        $allowedProjects = $this->synchronizer->cachedProjectIds();
        $this->cyclePayloads = [];

        foreach ($mrs as $mr) {
            if (!is_array($mr)) {
                continue;
            }
            $id = $this->intValue($mr, 'id');
            $projectId = $this->intValue($mr, 'project_id');
            if ($id === 0 || ($allowedProjects !== [] && !array_key_exists($projectId, $allowedProjects))) {
                continue;
            }

            if ($this->stringValue($mr, 'state') === 'closed') {
                $this->synchronizer->removeMergeRequest($id);
                continue;
            }

            $isNew = !$this->synchronizer->isMergeRequestCached($id);
            $this->cyclePayloads[$id] = $mr;
            $this->queue->enqueue($id, $isNew, $now);
        }

        $this->publish('refresh', ['type' => 'cycle_started', 'total' => $this->queue->totalCount()]);

        return true;
    }

    private function processNextJob(int $now): bool
    {
        $job = $this->queue->nextQueuedJob($this->queue->activeUserId());
        if ($job === null) {
            $status = $this->queue->status();
            $this->queue->endCycle($now);
            $this->publish('refresh', ['type' => 'cycle_done', 'total' => $status['total'], 'done' => $status['done']]);
            $this->cyclePayloads = [];

            return true;
        }

        $mrId = $job['mr_id'];
        $mr = $this->cyclePayloads[$mrId] ?? null;
        $this->queue->markFetching($mrId);

        if ($mr === null) {
            $this->queue->markFailed($mrId);

            return true;
        }

        try {
            $this->synchronizer->syncMergeRequestForRefresh(
                $mr,
                function (int $done, int $expected) use ($mrId): void {
                    $this->queue->recordProgress($mrId, $done, $expected);
                    $this->publish('refresh', [
                        'type' => 'progress',
                        'mr_id' => $mrId,
                        'requests_done' => $done,
                        'requests_expected' => $expected,
                    ]);
                },
            );
            $this->queue->markDone($mrId);
            $this->publish('refresh', ['type' => 'done', 'mr_id' => $mrId]);
            $this->publish('data', ['type' => 'changed', 'mr_id' => $mrId]);

            if ($job['is_new']) {
                $this->slackNotifier->notifyNewMr($mrId);
            }
        } catch (GitLabException) {
            $this->queue->markFailed($mrId);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publish(string $topic, array $payload): void
    {
        $this->hub->publish(new Update($topic, (string) json_encode($payload)));
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function intValue(array $data, string $key): int
    {
        $value = $data[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
