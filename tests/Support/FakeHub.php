<?php

declare(strict_types=1);

namespace App\Tests\Support;

use LogicException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;

use function is_string;
use function json_decode;

/**
 * In-memory HubInterface fake so tests never hit a real Mercure hub, mirroring
 * FakeGitLabClient's role for GitLabClientInterface.
 */
final class FakeHub implements HubInterface
{
    /**
     * @var list<array{topic: string, data: array<string, mixed>}>
     */
    public array $published = [];

    public function getUrl(): string
    {
        return 'http://mercure.test/.well-known/mercure';
    }

    public function getPublicUrl(): string
    {
        return $this->getUrl();
    }

    public function getProvider(): TokenProviderInterface
    {
        throw new LogicException('Not used by tests.');
    }

    public function getFactory(): null|TokenFactoryInterface
    {
        return null;
    }

    public function publish(Update $update): string
    {
        foreach ($update->getTopics() as $topic) {
            if (!is_string($topic)) {
                continue;
            }
            /** @var array<string, mixed> $data */
            $data = json_decode($update->getData(), true) ?? [];
            $this->published[] = ['topic' => $topic, 'data' => $data];
        }

        return 'fake-id';
    }
}
