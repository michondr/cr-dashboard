<?php

declare(strict_types=1);

namespace App\Controller;

use App\Refresh\RefreshQueue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

use function ctype_digit;
use function is_string;
use function time;

/**
 * Enqueues a refresh cycle for the SSE live-refresh worker (App\Refresh\RefreshWorker).
 * Identity is the existing "My view" user-filter selection, no auth.
 */
final class RefreshController
{
    public function __construct(private readonly RefreshQueue $queue)
    {
    }

    #[Route('/api/refresh', name: 'api_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $now = time();
        $result = $this->queue->requestCycle($now, $this->resolveUser($request));

        return new JsonResponse([
            'accepted' => $result['accepted'],
            'reason' => $result['reason'],
            'cooldown_remaining' => $result['cooldownRemaining'],
        ], $result['accepted'] ? 202 : 429);
    }

    #[Route('/api/refresh', name: 'api_refresh_status', methods: ['GET'])]
    public function status(): JsonResponse
    {
        return new JsonResponse($this->queue->status());
    }

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
