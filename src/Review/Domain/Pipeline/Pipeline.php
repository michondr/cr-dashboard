<?php

declare(strict_types=1);

namespace App\Review\Domain\Pipeline;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'pipelines')]
#[ORM\Entity]
class Pipeline
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private int $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $mergeRequestId;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private null|DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private null|DateTimeImmutable $updatedAt = null;

    public function __construct(
        int $id,
        int $mergeRequestId,
        string $status,
        null|DateTimeImmutable $createdAt = null,
        null|DateTimeImmutable $updatedAt = null,
    ) {
        $this->id = $id;
        $this->mergeRequestId = $mergeRequestId;
        $this->status = $status;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function mergeRequestId(): int
    {
        return $this->mergeRequestId;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function createdAt(): null|DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): null|DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
