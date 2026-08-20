<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Kernel;
use App\Storage\Database;
use App\Tests\Support\TestAppConfig;
use App\Tests\Support\TestSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use function is_file;
use function json_decode;
use function unlink;

final class RefreshControllerTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        // The kernel boots against this fixed path, so a previous run's file
        // would carry an old schema; drop it before the migration runs.
        foreach (['var/test.sqlite', 'var/test.sqlite-wal', 'var/test.sqlite-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $config = TestAppConfig::create('var/test.sqlite');
        $this->database = new Database($config);
        TestSchema::migrate($config);
        foreach (['sync_state', 'refresh_queue'] as $table) {
            $this->database->execute('DELETE FROM ' . $table);
        }
    }

    public function testPostAcceptsAndQueuesACycle(): void
    {
        $response = $this->post('/api/refresh?user=5');

        self::assertSame(202, $response->getStatusCode());
        $body = $this->decode($response->getContent());
        self::assertTrue($body['accepted']);
        self::assertSame('queued', $body['reason']);
    }

    public function testGetReturnsCycleStatus(): void
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $response = $kernel->handle(Request::create('/api/refresh', 'GET'));
        $kernel->terminate(Request::create('/api/refresh', 'GET'), $response);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response->getContent());
        self::assertArrayHasKey('active', $body);
        self::assertArrayHasKey('total', $body);
        self::assertArrayHasKey('done', $body);
    }

    private function post(string $uri): Response
    {
        $kernel = new Kernel('test', true);
        $kernel->boot();
        $request = Request::create($uri, 'POST');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(false|string $content): array
    {
        $decoded = json_decode((string) $content, true);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */

        return $decoded;
    }
}
