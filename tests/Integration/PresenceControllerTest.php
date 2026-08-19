<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

use function is_int;
use function json_decode;

final class PresenceControllerTest extends TestCase
{
    public function testGetReturnsAnOnlineCount(): void
    {
        // No real Mercure hub is running in the test environment;
        // HttpMercureSubscriptionReader degrades to 0 rather than erroring.
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $request = Request::create('/api/presence', 'GET');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        self::assertSame(200, $response->getStatusCode());
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('online', $decoded);
        self::assertTrue(is_int($decoded['online']));
    }
}
