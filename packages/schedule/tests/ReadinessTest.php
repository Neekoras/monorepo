<?php

declare(strict_types=1);

namespace Utopia\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Schedule\Clock\Test as TestClock;
use Utopia\Schedule\Scheduler;
use Utopia\Schedule\Source\Entry;
use Utopia\Schedule\Source\Row;
use Utopia\Schedule\Store\Memory;
use Utopia\Schedule\Trigger\Cron;

final class ReadinessTest extends TestCase
{
    public function testAnEmptySourceIsReadyAfterLoading(): void
    {
        $scheduler = new Scheduler(
            source: new SnapshotSource(fn(): array => [], fn(Row $row): Entry => new Entry(new Cron('* * * * *'))),
        );
        $this->assertFalse($scheduler->isReady());
        $scheduler->reconcile();
        $this->assertTrue($scheduler->isReady());
    }

    public function testFailedInitializationNeverClaimsOrAdvancesCoverage(): void
    {
        $store = new Memory();
        $attempts = 0;
        $scheduler = new Scheduler(
            source: new SnapshotSource(fn(): never => throw new \RuntimeException('source unavailable'), fn(Row $row): Entry => new Entry(new Cron('* * * * *'))),
            store: $store,
            clock: new TestClock(new \DateTimeImmutable('2026-08-18 03:00:00')),
            onError: function () use (&$scheduler, &$attempts, $store): void {
                $this->assertNotInstanceOf(\Utopia\Schedule\Claim::class, $store->load(), 'failed attempts must leave leadership available');
                if (++$attempts === 2) {
                    $scheduler?->stop();
                }
            },
        );
        $delivered = [];
        $scheduler->run(function (array $batch) use (&$delivered): void {
            $delivered = array_merge($delivered, $batch);
        });
        $this->assertFalse($scheduler->isReady());
        $this->assertNotInstanceOf(\Utopia\Schedule\Claim::class, $store->load());
        $this->assertSame([], $delivered);
    }
}
