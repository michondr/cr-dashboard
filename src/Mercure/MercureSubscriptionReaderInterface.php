<?php

declare(strict_types=1);

namespace App\Mercure;

/**
 * Reads active subscriber counts from the Mercure hub's subscription API
 * (item 7: "connected users" indicator). Abstracted behind an interface so
 * tests can fake it instead of hitting a real hub, mirroring
 * GitLabClientInterface and HubInterface.
 */
interface MercureSubscriptionReaderInterface
{
    /**
     * Number of distinct active subscribers currently subscribed to
     * `$topic`. Every connected dashboard subscribes to the `data` topic, so
     * that count is used as the "online" headcount.
     */
    public function activeSubscriberCount(string $topic): int;
}
