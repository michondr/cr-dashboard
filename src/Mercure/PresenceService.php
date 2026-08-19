<?php

declare(strict_types=1);

namespace App\Mercure;

/**
 * "Connected dashboards" headcount (item 7): every connected dashboard's
 * EventSource subscribes to the `data` topic, so the active-subscriber count
 * for that topic is the online count.
 */
final class PresenceService
{
    public function __construct(private readonly MercureSubscriptionReaderInterface $reader)
    {
    }

    public function onlineCount(): int
    {
        return $this->reader->activeSubscriberCount('data');
    }
}
