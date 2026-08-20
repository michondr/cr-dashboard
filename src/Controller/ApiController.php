<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiBuilder;
use App\Metrics\Buckets;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function ctype_digit;
use function in_array;
use function is_string;
use function time;

final class ApiController
{
    public function __construct(private readonly ApiBuilder $apiBuilder)
    {
    }

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        $granularity = $this->resolveGranularity($request);
        $user = $this->resolveUser($request);
        $now = time();

        // Freshness is driven by the SSE refresh worker (POST /api/refresh)
        // and the cron syncs; the web process never spawns a sync itself.
        return new JsonResponse($this->apiBuilder->build($granularity, $now, $user));
    }

    /**
     * Single-MR payload for SSE-driven row patches: same shape as one element
     * of `mrs` in `/api/data`, without the metrics/users/meta overhead. 404
     * (with `mr: null`) when the MR is not cached or no longer open, which the
     * frontend treats as "remove the row".
     */
    #[Route('/api/mr/{id}', name: 'api_mr', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function mr(int $id): JsonResponse
    {
        $mr = $this->apiBuilder->buildMr($id, time());

        return new JsonResponse(['mr' => $mr], $mr === null ? 404 : 200);
    }

    private function resolveGranularity(Request $request): string
    {
        $bucket = (string) $request->query->get('bucket', Buckets::DAY);

        return in_array($bucket, [Buckets::WEEK, Buckets::DAY, Buckets::HOUR], true)
            ? $bucket
            : Buckets::DAY;
    }

    /**
     * Optional `?user=<id>` "my view" filter for the MR list. Null when absent
     * or not a positive integer.
     */
    private function resolveUser(Request $request): null|int
    {
        $raw = $request->query->get('user');
        if (!is_string($raw) || $raw === '' || !ctype_digit($raw)) {
            return null;
        }

        $id = (int) $raw;

        return $id > 0 ? $id : null;
    }
}
