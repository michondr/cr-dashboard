<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Mercure\MercureSubscriptionReaderInterface;

final class FakeMercureSubscriptionReader implements MercureSubscriptionReaderInterface
{
    /**
     * @var array<string, int>
     */
    public array $countsByTopic = [];

    public function activeSubscriberCount(string $topic): int
    {
        return $this->countsByTopic[$topic] ?? 0;
    }
}
