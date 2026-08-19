<?php

declare(strict_types=1);

namespace App\GitLab;

interface GitLabClientInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function groupProjects(string $groupPath): array;

    /**
     * @param array<string, int|string> $query
     *
     * @return list<array<string, mixed>>
     */
    public function groupMergeRequests(string $groupPath, array $query): array;

    /**
     * @return array<string, mixed>
     */
    public function approvals(int $projectId, int $iid): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function discussions(int $projectId, int $iid): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function pipelines(int $projectId, int $iid): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function jobs(int $projectId, int $pipelineId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function commits(int $projectId, int $iid): array;

    /**
     * @return array<string, mixed>
     */
    public function commitStats(int $projectId, string $sha): array;

    /**
     * All-time merge request count authored by the given user within the configured
     * group. Used by the daily user-ranking job.
     */
    public function authorMergeRequestCount(int $authorId): int;

    /**
     * Performs a GET without throwing on a non-2xx response, returning the raw
     * status, body and elapsed seconds. A transport failure yields status 0.
     *
     * @param array<string, int|string> $query
     *
     * @return array{status: int, body: string, seconds: float}
     */
    public function rawGet(string $path, array $query = []): array;
}
