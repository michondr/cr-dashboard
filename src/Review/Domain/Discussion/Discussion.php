<?php

declare(strict_types=1);

namespace App\Review\Domain\Discussion;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'discussions')]
#[ORM\Entity]
class Discussion
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private int $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $mergeRequestId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $userId;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $resolved = true;

    public function __construct(
        int $id,
        int $mergeRequestId,
        int $userId,
        DateTimeImmutable $createdAt,
        bool $resolved = true,
    ) {
        $this->id = $id;
        $this->mergeRequestId = $mergeRequestId;
        $this->userId = $userId;
        $this->createdAt = $createdAt;
        $this->resolved = $resolved;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function mergeRequestId(): int
    {
        return $this->mergeRequestId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function resolved(): bool
    {
        return $this->resolved;
    }
}
