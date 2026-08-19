<?php

declare(strict_types=1);

namespace App\GitLab;

use App\Config\AppConfig;
use RuntimeException;

use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function http_build_query;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function rawurlencode;
use function rtrim;
use function sprintf;
use function strlen;
use function usleep;

/**
 * Minimal GitLab REST API v4 client. Every list endpoint is followed to its
 * last page via the `Link: rel="next"` header, and requests are throttled to
 * `GITLAB_RPS` (default 8/s).
 */
final class Client implements GitLabClientInterface
{
    private float $lastRequestAt = 0.0;

    public function __construct(private readonly AppConfig $config)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function groupProjects(string $groupPath): array
    {
        return $this->listAll(
            'groups/' . rawurlencode($groupPath) . '/projects',
            ['include_subgroups' => 'true', 'per_page' => 100],
        );
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return list<array<string, mixed>>
     */
    public function groupMergeRequests(string $groupPath, array $query): array
    {
        return $this->listAll(
            'groups/' . rawurlencode($groupPath) . '/merge_requests',
            ['per_page' => 100] + $query,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function approvals(int $projectId, int $iid): array
    {
        return $this->getMap('projects/' . $projectId . '/merge_requests/' . $iid . '/approvals');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function discussions(int $projectId, int $iid): array
    {
        return $this->listAll(
            'projects/' . $projectId . '/merge_requests/' . $iid . '/discussions',
            ['per_page' => 100],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pipelines(int $projectId, int $iid): array
    {
        return $this->listAll('projects/' . $projectId . '/merge_requests/' . $iid . '/pipelines', ['per_page' => 100]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function jobs(int $projectId, int $pipelineId): array
    {
        return $this->listAll('projects/' . $projectId . '/pipelines/' . $pipelineId . '/jobs', ['per_page' => 100]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function commits(int $projectId, int $iid): array
    {
        return $this->listAll('projects/' . $projectId . '/merge_requests/' . $iid . '/commits', ['per_page' => 100]);
    }

    /**
     * @return array<string, mixed>
     */
    public function commitStats(int $projectId, string $sha): array
    {
        return $this->getMap('projects/' . $projectId . '/repository/commits/' . $sha, ['stats' => 'true']);
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return list<array<string, mixed>>
     */
    private function listAll(string $path, array $query = []): array
    {
        $result = [];
        $url = $this->buildUrl($path, $query);

        while ($url !== null) {
            $response = $this->requestUrl($url);
            $decoded = json_decode($response['body'], true);
            if (!is_array($decoded)) {
                throw new RuntimeException('GitLab returned invalid JSON for ' . $url);
            }
            /** @var array<string, mixed> $item */
            foreach ($decoded as $item) {
                if (is_array($item)) {
                    $result[] = $item;
                }
            }
            $url = LinkHeader::nextUrl($response['headers']);
        }

        return $result;
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array<string, mixed>
     */
    private function getMap(string $path, array $query = []): array
    {
        $response = $this->requestUrl($this->buildUrl($path, $query));
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('GitLab returned invalid JSON for ' . $path);
        }
        /** @var array<string, mixed> $decoded */

        return $decoded;
    }

    /**
     * @param array<string, int|string> $query
     */
    private function buildUrl(string $path, array $query = []): string
    {
        $url = rtrim($this->config->gitlabUrl, '/') . '/api/v4/' . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * @return array{status: int, body: string, headers: list<string>}
     */
    private function requestUrl(string $url): array
    {
        $this->throttle();

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize curl for GitLab request.');
        }

        $headers = [];
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_HTTPHEADER, [
            'PRIVATE-TOKEN: ' . $this->config->gitlabToken,
            'Accept: application/json',
        ]);
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($handle, string $header) use (&$headers): int {
            $headers[] = $header;

            return strlen($header);
        });

        $body = curl_exec($handle);
        if (!is_string($body)) {
            throw new RuntimeException('GitLab request failed: ' . curl_error($handle));
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        $this->lastRequestAt = microtime(true);

        if ($status < 200 || $status >= 300) {
            throw new GitLabException(sprintf('GitLab API error %d for %s', $status, $url));
        }

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    private function throttle(): void
    {
        $interval = 1.0 / max(0.1, $this->config->gitlabRps);
        $elapsed = microtime(true) - $this->lastRequestAt;
        $sleepSeconds = $interval - $elapsed;
        if ($sleepSeconds > 0.0) {
            usleep((int) ($sleepSeconds * 1_000_000));
        }
    }
}
