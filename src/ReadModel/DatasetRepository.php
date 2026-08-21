<?php

declare(strict_types=1);

namespace App\ReadModel;

/**
 * Read side of the shared kernel: loads the whole cache snapshot the metric
 * functions and the API builder work on. Returns rows with Doctrine-native
 * column names (`merge_request_id`, ...) and epoch-second timestamps decoded
 * back from storage — the canonical read-model shape described on
 * {@see Dataset}.
 */
interface DatasetRepository
{
    public function load(): Dataset;

    /**
     * @return array<int, array{id: int, path_with_namespace: string, name: string, avatar_url: string|null}>
     */
    public function projectInfos(): array;
}
