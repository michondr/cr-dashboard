<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Review\Infrastructure\GitLab\Client;
use App\Tests\Support\TestAppConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function dirname;
use function fclose;
use function fsockopen;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function stream_socket_get_name;
use function stream_socket_server;
use function strrpos;
use function substr;
use function usleep;

final class PaginationTest extends TestCase
{
    private static int $port;
    private static mixed $serverProcess = null;

    public static function setUpBeforeClass(): void
    {
        $router = dirname(__DIR__) . '/Fixtures/paginator.php';
        self::$port = self::findFreePort();
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];
        // An array command execs PHP directly; a string command would route
        // through /bin/sh and leave the real server as an orphaned grandchild.
        self::$serverProcess = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, $router],
            $descriptors,
            $pipes,
        );
        if (is_resource(self::$serverProcess)) {
            fclose($pipes[0]);
        }
        self::waitForServer();
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess, 9);
            proc_close(self::$serverProcess);
        }
    }

    private static function waitForServer(): void
    {
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', self::$port, $errorNumber, $errorString, 0.2);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('Pagination fixture server did not start in time.');
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorString);
        if ($socket === false) {
            throw new RuntimeException('Unable to find a free port: ' . $errorString);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($name === false) {
            throw new RuntimeException('Unable to determine the free port.');
        }

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    public function testListAllFollowsLinkHeadersToCompletion(): void
    {
        $config = TestAppConfig::create('/tmp/cr-dashboard-pagination.sqlite', [
            'gitlabUrl' => 'http://127.0.0.1:' . self::$port,
            'gitlabGroup' => 'group',
        ]);
        $client = new Client($config);

        $mrs = $client->groupMergeRequests('group', ['state' => 'all']);

        self::assertCount(3, $mrs);
        self::assertSame(1, $mrs[0]['id']);
        self::assertSame(3, $mrs[2]['id']);
    }
}
