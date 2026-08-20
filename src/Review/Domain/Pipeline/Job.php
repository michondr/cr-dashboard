<?php

declare(strict_types=1);

namespace App\Review\Domain\Pipeline;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'jobs')]
#[ORM\Entity]
class Job
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private int $id;

    #[ORM\Column(type: Types::INTEGER)]
    private int $pipelineId;

    #[ORM\Column(type: Types::INTEGER)]
    private int $mergeRequestId;

    #[ORM\Column(type: Types::STRING, length: 32)]
    private string $status;

    public function __construct(int $id, int $pipelineId, int $mergeRequestId, string $status)
    {
        $this->id = $id;
        $this->pipelineId = $pipelineId;
        $this->mergeRequestId = $mergeRequestId;
        $this->status = $status;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function pipelineId(): int
    {
        return $this->pipelineId;
    }

    public function mergeRequestId(): int
    {
        return $this->mergeRequestId;
    }

    public function status(): string
    {
        return $this->status;
    }
}
