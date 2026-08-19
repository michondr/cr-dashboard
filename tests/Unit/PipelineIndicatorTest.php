<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Metrics\PipelineIndicator;
use PHPUnit\Framework\TestCase;

final class PipelineIndicatorTest extends TestCase
{
    public function testNoPipelinesYieldsNone(): void
    {
        self::assertSame(
            ['status' => '', 'indicator' => 'none', 'tint' => null],
            PipelineIndicator::compute([], []),
        );
    }

    public function testSuccessYieldsCheck(): void
    {
        self::assertSame(
            ['status' => 'success', 'indicator' => 'check', 'tint' => null],
            PipelineIndicator::compute([['id' => 1, 'status' => 'success']], []),
        );
    }

    public function testFailedYieldsFail(): void
    {
        self::assertSame(
            ['status' => 'failed', 'indicator' => 'fail', 'tint' => null],
            PipelineIndicator::compute([['id' => 1, 'status' => 'failed']], []),
        );
    }

    public function testCanceledYieldsNeutral(): void
    {
        self::assertSame(
            ['status' => 'canceled', 'indicator' => 'neutral', 'tint' => null],
            PipelineIndicator::compute([['id' => 1, 'status' => 'canceled']], []),
        );
    }

    public function testRunningWithNoFinishedJobsIsPlainSpinner(): void
    {
        self::assertSame(
            ['status' => 'running', 'indicator' => 'spinner', 'tint' => null],
            PipelineIndicator::compute(
                [['id' => 1, 'status' => 'running']],
                [['pipeline_id' => 1, 'status' => 'running']],
            ),
        );
    }

    public function testRunningWithFailedJobTintsSpinnerRed(): void
    {
        self::assertSame(
            ['status' => 'running', 'indicator' => 'spinner', 'tint' => 'fail'],
            PipelineIndicator::compute(
                [['id' => 1, 'status' => 'running']],
                [
                    ['pipeline_id' => 1, 'status' => 'running'],
                    ['pipeline_id' => 1, 'status' => 'failed'],
                ],
            ),
        );
    }

    public function testRunningWithWarningJobTintsSpinnerOrange(): void
    {
        self::assertSame(
            ['status' => 'running', 'indicator' => 'spinner', 'tint' => 'warn'],
            PipelineIndicator::compute(
                [['id' => 1, 'status' => 'running']],
                [['pipeline_id' => 1, 'status' => 'warning']],
            ),
        );
    }

    public function testRunningFailedWinsOverWarning(): void
    {
        self::assertSame(
            ['status' => 'running', 'indicator' => 'spinner', 'tint' => 'fail'],
            PipelineIndicator::compute(
                [['id' => 1, 'status' => 'running']],
                [
                    ['pipeline_id' => 1, 'status' => 'warning'],
                    ['pipeline_id' => 1, 'status' => 'failed'],
                ],
            ),
        );
    }

    public function testLatestPipelineIsTheOneWithHighestId(): void
    {
        self::assertSame(
            ['status' => 'running', 'indicator' => 'spinner', 'tint' => 'fail'],
            PipelineIndicator::compute(
                [
                    ['id' => 10, 'status' => 'success'],
                    ['id' => 11, 'status' => 'running'],
                ],
                [['pipeline_id' => 11, 'status' => 'failed']],
            ),
        );
    }

    public function testJobsFromOlderPipelineDoNotTintTheSpinner(): void
    {
        self::assertSame(
            ['status' => 'running', 'indicator' => 'spinner', 'tint' => null],
            PipelineIndicator::compute(
                [['id' => 11, 'status' => 'running']],
                [['pipeline_id' => 10, 'status' => 'failed']],
            ),
        );
    }
}
