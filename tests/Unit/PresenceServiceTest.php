<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Mercure\PresenceService;
use App\Tests\Support\FakeMercureSubscriptionReader;
use PHPUnit\Framework\TestCase;

final class PresenceServiceTest extends TestCase
{
    public function testOnlineCountReadsTheDataTopicSubscriberCount(): void
    {
        $reader = new FakeMercureSubscriptionReader();
        $reader->countsByTopic = ['data' => 3, 'refresh' => 3];

        $service = new PresenceService($reader);

        self::assertSame(3, $service->onlineCount());
    }

    public function testOnlineCountIsZeroWhenNobodyIsSubscribed(): void
    {
        $service = new PresenceService(new FakeMercureSubscriptionReader());

        self::assertSame(0, $service->onlineCount());
    }
}
