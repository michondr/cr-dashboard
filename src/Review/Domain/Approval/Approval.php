<?php

declare(strict_types=1);

namespace App\Review\Domain\Approval;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'approvals')]
#[ORM\Entity]
class Approval
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

    public function __construct(int $id, int $mergeRequestId, int $userId, DateTimeImmutable $createdAt)
    {
        $this->id = $id;
        $this->mergeRequestId = $mergeRequestId;
        $this->userId = $userId;
        $this->createdAt = $createdAt;
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
}
