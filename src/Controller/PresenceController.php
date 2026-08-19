<?php

declare(strict_types=1);

namespace App\Controller;

use App\Mercure\PresenceService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "N online" indicator (item 7): all connected dashboards share the GitLab
 * API rate limit, so knowing how many people are online explains why
 * refreshes sometimes take longer.
 */
final class PresenceController
{
    public function __construct(private readonly PresenceService $presence)
    {
    }

    #[Route('/api/presence', name: 'api_presence', methods: ['GET'])]
    public function presence(): JsonResponse
    {
        return new JsonResponse(['online' => $this->presence->onlineCount()]);
    }
}
