<?php

declare(strict_types=1);

namespace App\Review\Domain\User;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'users')]
#[ORM\Entity]
class User
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private int $id;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255)]
    private string $username;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private null|string $avatarUrl = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $mrCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private null|DateTimeImmutable $rankedAt = null;

    public function __construct(
        int $id,
        string $name,
        string $username,
        null|string $avatarUrl = null,
        int $mrCount = 0,
        null|DateTimeImmutable $rankedAt = null,
    ) {
        $this->id = $id;
        $this->name = $name;
        $this->username = $username;
        $this->avatarUrl = $avatarUrl;
        $this->mrCount = $mrCount;
        $this->rankedAt = $rankedAt;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function avatarUrl(): null|string
    {
        return $this->avatarUrl;
    }

    public function mrCount(): int
    {
        return $this->mrCount;
    }

    public function rankedAt(): null|DateTimeImmutable
    {
        return $this->rankedAt;
    }
}
