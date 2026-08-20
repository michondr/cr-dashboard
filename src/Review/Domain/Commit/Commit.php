<?php

declare(strict_types=1);

namespace App\Review\Domain\Commit;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'commits')]
#[ORM\UniqueConstraint(name: 'commits_mr_sha_unique', columns: ['merge_request_id', 'sha'])]
#[ORM\Entity]
class Commit
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private int $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $mergeRequestId;

    #[ORM\Column(type: Types::STRING, length: 64)]
    private string $sha;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private null|string $message = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private null|DateTimeImmutable $committedDate = null;

    #[ORM\Column(type: Types::BOOLEAN, options: ['default' => true])]
    private bool $current = true;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private null|int $additions = null;

    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private null|int $deletions = null;

    public function __construct(
        int $id,
        int $mergeRequestId,
        string $sha,
        null|string $message = null,
        null|DateTimeImmutable $committedDate = null,
        bool $current = true,
        null|int $additions = null,
        null|int $deletions = null,
    ) {
        $this->id = $id;
        $this->mergeRequestId = $mergeRequestId;
        $this->sha = $sha;
        $this->message = $message;
        $this->committedDate = $committedDate;
        $this->current = $current;
        $this->additions = $additions;
        $this->deletions = $deletions;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function mergeRequestId(): int
    {
        return $this->mergeRequestId;
    }

    public function sha(): string
    {
        return $this->sha;
    }

    public function message(): null|string
    {
        return $this->message;
    }

    public function committedDate(): null|DateTimeImmutable
    {
        return $this->committedDate;
    }

    public function isCurrent(): bool
    {
        return $this->current;
    }

    public function additions(): null|int
    {
        return $this->additions;
    }

    public function deletions(): null|int
    {
        return $this->deletions;
    }
}
