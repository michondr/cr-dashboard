<?php

declare(strict_types=1);

namespace App\Review\Domain\Project;

/**
 * Persistence boundary for the `projects` cache table (per-table aggregate).
 * The sync backfills project rows up front and the read model only consults
 * them, so the repository is write-only plus the id list the sync reconciles
 * against.
 */
interface ProjectRepository
{
    /**
     * @param array<string, int|float|string|null> $project
     */
    public function upsert(array $project): void;

    /**
     * @return list<int>
     */
    public function allIds(): array;
}
