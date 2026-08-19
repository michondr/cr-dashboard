<?php

declare(strict_types=1);

namespace App\Controller;

use App\Api\ApiBuilder;
use App\Config\AppConfig;
use App\Metrics\Buckets;
use App\Sync\Synchronizer;
use App\Sync\SyncTrigger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function in_array;
use function time;

final class ApiController
{
    public function __construct(
        private readonly ApiBuilder $apiBuilder,
        private readonly SyncTrigger $syncTrigger,
        private readonly Synchronizer $synchronizer,
    ) {
    }

    #[Route('/api/data', name: 'api_data', methods: ['GET'])]
    public function data(Request $request): JsonResponse
    {
        $granularity = $this->resolveGranularity($request);
        $now = time();

        // Stale-while-revalidate: serve an immediate answer, and spawn a
        // detached background sync when the cache is older than 60 seconds.
        $lastSync = $this->synchronizer->lastSync();
        if ($lastSync !== null && ($now - $lastSync) > AppConfig::CACHE_FRESH_SECONDS) {
            $this->syncTrigger->maybeSpawn($now);
        }

        return new JsonResponse($this->apiBuilder->build($granularity, $now));
    }

    private function resolveGranularity(Request $request): string
    {
        $bucket = (string) $request->query->get('bucket', Buckets::DAY);

        return in_array($bucket, [Buckets::WEEK, Buckets::DAY, Buckets::HOUR], true)
            ? $bucket
            : Buckets::DAY;
    }
}
