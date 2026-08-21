<?php

declare(strict_types=1);

namespace App\Review\Infrastructure\GitLab;

use function preg_match;
use function stripos;
use function trim;

final class LinkHeader
{
    /**
     * @param list<string> $headers Raw header lines as received from the transport.
     */
    public static function nextUrl(array $headers): null|string
    {
        foreach ($headers as $header) {
            $line = trim($header);
            if (stripos($line, 'link:') !== 0) {
                continue;
            }
            if (preg_match('/<([^>]+)>;\s*rel="next"/', $line, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
