<?php

declare(strict_types=1);

namespace App\Review\Domain\MergeRequest;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'merge_requests')]
#[ORM\Entity]
class MergeRequest
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private int $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $iid;

    #[ORM\Column(type: Types::INTEGER)]
    private int $projectId;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $title;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private null|string $description = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $authorId;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $state;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $draft = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private null|DateTimeImmutable $mergedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private null|DateTimeImmutable $closedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private null|string $webUrl = null;

    #[ORM\Column(type: Types::STRING, length: 64, options: ['default' => ''])]
    private string $mergeStatus = '';

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => false])]
    private bool $hasConflicts = false;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $labels = [];

    /**
     * @param list<string> $labels
     */
    public function __construct(
        int $id,
        int $iid,
        int $projectId,
        string $title,
        int $authorId,
        string $state,
        DateTimeImmutable $createdAt,
        DateTimeImmutable $updatedAt,
        null|string $description = null,
        bool $draft = false,
        null|DateTimeImmutable $mergedAt = null,
        null|DateTimeImmutable $closedAt = null,
        null|string $webUrl = null,
        string $mergeStatus = '',
        bool $hasConflicts = false,
        array $labels = [],
    ) {
        $this->id = $id;
        $this->iid = $iid;
        $this->projectId = $projectId;
        $this->title = $title;
        $this->authorId = $authorId;
        $this->state = $state;
        $this->createdAt = $createdAt;
        $this->updatedAt = $updatedAt;
        $this->description = $description;
        $this->draft = $draft;
        $this->mergedAt = $mergedAt;
        $this->closedAt = $closedAt;
        $this->webUrl = $webUrl;
        $this->mergeStatus = $mergeStatus;
        $this->hasConflicts = $hasConflicts;
        $this->labels = $labels;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function iid(): int
    {
        return $this->iid;
    }

    public function projectId(): int
    {
        return $this->projectId;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): null|string
    {
        return $this->description;
    }

    public function authorId(): int
    {
        return $this->authorId;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function isDraft(): bool
    {
        return $this->draft;
    }

    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function mergedAt(): null|DateTimeImmutable
    {
        return $this->mergedAt;
    }

    public function closedAt(): null|DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function webUrl(): null|string
    {
        return $this->webUrl;
    }

    public function mergeStatus(): string
    {
        return $this->mergeStatus;
    }

    public function hasConflicts(): bool
    {
        return $this->hasConflicts;
    }

    /** @return list<string> */
    public function labels(): array
    {
        return $this->labels;
    }
}
