<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Metrics\JiraTicket;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class JiraTicketTest extends TestCase
{
    /**
     * @return iterable<array{title: string, expected: string|null}>
     */
    public static function titles(): iterable
    {
        yield 'standard prefix title' => ['title' => 'REC-1234 - Add feature X', 'expected' => 'REC-1234'];
        yield 'ticket only' => ['title' => 'AB-12', 'expected' => 'AB-12'];
        yield 'digits in project key' => ['title' => 'REC2-34 Something', 'expected' => 'REC2-34'];
        yield 'no ticket' => ['title' => 'Add feature X', 'expected' => null];
        yield 'lowercase start is not a ticket' => ['title' => 'rec-1234 thing', 'expected' => null];
        yield 'regex stops at first digits' => ['title' => 'REC-1234X extra', 'expected' => 'REC-1234'];
        yield 'empty title' => ['title' => '', 'expected' => null];
    }

    #[DataProvider('titles')]
    public function testExtract(string $title, null|string $expected): void
    {
        self::assertSame($expected, JiraTicket::extract($title));
    }
}
