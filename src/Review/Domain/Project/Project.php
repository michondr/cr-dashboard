<?php

declare(strict_types=1);

namespace App\Review\Domain\Project;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'projects')]
#[ORM\Entity]
class Project
{
    #[ORM\Id]
    #[ORM\Column(type: Types::INTEGER)]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    private int $id;

    #[ORM\Column(type: Types::STRING, length: 500)]
    private string $pathWithNamespace;

    #[ORM\Column(type: Types::STRING, length: 255, options: ['default' => ''])]
    private string $name = '';

    #[ORM\Column(type: Types::STRING, length: 500, nullable: true)]
    private null|string $avatarUrl = null;

    public function __construct(int $id, string $pathWithNamespace, string $name = '', null|string $avatarUrl = null)
    {
        $this->id = $id;
        $this->pathWithNamespace = $pathWithNamespace;
        $this->name = $name;
        $this->avatarUrl = $avatarUrl;
    }

    public function id(): int
    {
        return $this->id;
    }

    public function pathWithNamespace(): string
    {
        return $this->pathWithNamespace;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function avatarUrl(): null|string
    {
        return $this->avatarUrl;
    }
}
