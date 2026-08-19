<?php

declare(strict_types=1);

namespace App\Metrics;

final class MetricResult
{
    /** @var array<string, Series> */
    public readonly array $persons;

    /**
     * @param array<int|string, Series> $persons Keyed by user id.
     */
    public function __construct(
        public readonly string $bucket,
        public readonly string $unit,
        public readonly bool $meanAndMedian,
        array $persons,
    ) {
        $normalized = [];
        foreach ($persons as $userId => $series) {
            $normalized[(string) $userId] = $series;
        }
        $this->persons = $normalized;
    }

    /**
     * @return array{bucket: string, unit: string, persons: array<string, array<string, mixed>>}
     */
    public function toApiArray(): array
    {
        $persons = [];
        foreach ($this->persons as $userId => $series) {
            $persons[$userId] = $series->toApiArray($this->meanAndMedian);
        }

        return ['bucket' => $this->bucket, 'unit' => $this->unit, 'persons' => $persons];
    }
}
