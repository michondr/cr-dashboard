<?php

declare(strict_types=1);

namespace App\Metrics;

use function preg_match;

final class JiraTicket
{
    public static function extract(string $title): null|string
    {
        if (preg_match('/^([A-Z][A-Z0-9]+-\d+)/', $title, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }
}
