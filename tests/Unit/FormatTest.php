<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Metrics\Format;
use PHPUnit\Framework\TestCase;

final class FormatTest extends TestCase
{
    public function testAgeFormatsFullDays(): void
    {
        $seconds = (12 * 86400) + (23 * 3600) + (59 * 60) + 59;

        self::assertSame('12d 23:59:59', Format::age($seconds));
    }

    public function testAgeFormatsSubDay(): void
    {
        self::assertSame('0d 04:05:06', Format::age((4 * 3600) + (5 * 60) + 6));
    }

    public function testDurationOmitsSeconds(): void
    {
        self::assertSame('1d 02:11', Format::duration(86400 + (2 * 3600) + (11 * 60)));
    }
}
