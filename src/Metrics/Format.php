<?php

declare(strict_types=1);

namespace App\Metrics;

use function intdiv;
use function sprintf;

final class Format
{
    public static function age(int $seconds): string
    {
        $remaining = $seconds;

        return sprintf(
            '%dd %02d:%02d:%02d',
            intdiv($remaining, 86400),
            intdiv($remaining % 86400, 3600),
            intdiv($remaining % 3600, 60),
            $remaining % 60,
        );
    }

    public static function duration(int $seconds): string
    {
        return sprintf(
            '%dd %02d:%02d',
            intdiv($seconds, 86400),
            intdiv($seconds % 86400, 3600),
            intdiv($seconds % 3600, 60),
        );
    }
}
