<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\GitLab\GitLabClientInterface;

final class FakeGitLabClient implements GitLabClientInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $projects = [];

    /**
     * @var array<string, list<array<string, mixed>>> Keyed by MR state.
     */
    public array $mergeRequests = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $approvalsByIid = [];

    /**
     * @var array<int, list<array<string, mixed>>>
     */
    public array $discussionsByIid = [];

    /**
     * @var array<int, list<array<string, mixed>>>
     */
    public array $pipelinesByIid = [];

    /**
     * @var array<int, list<array<string, mixed>>>
     */
    public array $jobsByPipeline = [];

    /**
     * @var array<int, list<array<string, mixed>>>
     */
    public array $commitsByIid = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $commitStatsBySha = [];

    public int $groupProjectsCalls = 0;
    public int $groupMergeRequestsCalls = 0;
    public int $jobsCalls = 0;
    public int $commitStatsCalls = 0;

    /**
     * @var list<array<string, mixed>>
     */
    public array $mergeRequestQueries = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function groupProjects(string $groupPath): array
    {
        $this->groupProjectsCalls++;

        return $this->projects;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupMergeRequests(string $groupPath, array $query): array
    {
        $this->groupMergeRequestsCalls++;
        $this->mergeRequestQueries[] = $query;
        $state = (string) ($query['state'] ?? 'all');

        return $this->mergeRequests[$state] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function approvals(int $projectId, int $iid): array
    {
        return $this->approvalsByIid[$iid] ?? ['approved_by' => []];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discussions(int $projectId, int $iid): array
    {
        return $this->discussionsByIid[$iid] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pipelines(int $projectId, int $iid): array
    {
        return $this->pipelinesByIid[$iid] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jobs(int $projectId, int $pipelineId): array
    {
        $this->jobsCalls++;

        return $this->jobsByPipeline[$pipelineId] ?? [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commits(int $projectId, int $iid): array
    {
        return $this->commitsByIid[$iid] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function commitStats(int $projectId, string $sha): array
    {
        $this->commitStatsCalls++;

        return $this->commitStatsBySha[$sha] ?? ['stats' => ['additions' => 0, 'deletions' => 0]];
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array{status: int, body: string, seconds: float}
     */
    public function rawGet(string $path, array $query = []): array
    {
        return ['status' => 200, 'body' => '', 'seconds' => 0.0];
    }
}
