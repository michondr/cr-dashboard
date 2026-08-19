<?php

declare(strict_types=1);

namespace App\Metrics;

final class Series
{
    /** @var list<string> */
    public readonly array $buckets;

    /** @var list<int|float|null> Primary series (mean for duration/size metrics, raw count for count metrics). */
    public readonly array $values;

    /** @var list<int|float|null> Median series for duration/size metrics. */
    public readonly array $median;

    /**
     * @param list<string> $buckets
     * @param list<int|float|null> $values
     * @param list<int|float|null> $median
     */
    public function __construct(array $buckets, array $values, array $median = [])
    {
        $this->buckets = $buckets;
        $this->values = $values;
        $this->median = $median;
    }

    /**
     * @return array{
     *   buckets: list<string>,
     *   mean?: list<int|float|null>,
     *   median?: list<int|float|null>,
     *   values?: list<int|float|null>
     * }
     */
    public function toApiArray(bool $meanAndMedian): array
    {
        if ($meanAndMedian) {
            return ['buckets' => $this->buckets, 'mean' => $this->values, 'median' => $this->median];
        }

        return ['buckets' => $this->buckets, 'values' => $this->values];
    }
}
