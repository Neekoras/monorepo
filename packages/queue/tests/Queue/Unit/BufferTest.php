<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Utopia\Queue\Buffer;

final class BufferTest extends TestCase
{
    public function testCoalescesPendingRequestsAndPreservesIndependentResults(): void
    {
        $groups = $results = [];
        Coroutine\run(function () use (&$groups, &$results): void {
            $buffer = new Buffer(function (array $requests, ?callable $resolved) use (&$groups): array {
                $groups[] = \count($requests);
                Coroutine::sleep(0.01);
                return array_map(fn($n) => $n === 3 ? new \RuntimeException('uncertain') : $n, $requests);
            });
            foreach (range(1, 100) as $n) {
                Coroutine::create(function () use ($buffer, $n, &$results): void {
                    try {
                        $results[$n] = $buffer->request($n);
                    } catch (\RuntimeException $error) {
                        $results[$n] = $error->getMessage();
                    }
                });
            }
        });
        $this->assertSame([1, 99], $groups);
        $this->assertCount(100, $results);
        $this->assertSame('uncertain', $results[3]);
        $this->assertSame(100, $results[100]);
    }
}
