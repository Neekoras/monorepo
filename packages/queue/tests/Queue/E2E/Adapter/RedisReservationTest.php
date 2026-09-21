<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use PHPUnit\Framework\TestCase;
use Utopia\Queue\Broker\Redis as Broker;
use Utopia\Queue\Connection\Redis as Connection;
use Utopia\Queue\Queue;

final class RedisReservationTest extends TestCase
{
    private \Redis $redis;
    private Queue $queue;
    private Broker $broker;

    protected function setUp(): void
    {
        $this->redis = new \Redis();
        $this->redis->connect('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379));
        $this->queue = new Queue('reservation', '{test-' . bin2hex(random_bytes(8)) . '}');
        $this->broker = new Broker(new Connection('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)), new Connection('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)));
    }

    protected function tearDown(): void
    {
        $keys = $this->redis->keys($this->queue->namespace . '*');
        if ($keys !== []) {
            $this->redis->del($keys);
        }
        $this->redis->close();
    }

    public function testBatchClaimsAndIndependentOutcomes(): void
    {
        foreach (range(1, 100) as $n) {
            $this->broker->publish($this->queue, ['n' => $n]);
        }
        $messages = $this->broker->receive($this->queue, 0, 100);
        $this->assertCount(100, $messages);
        $this->assertSame(range(1, 100), array_map(fn(\Utopia\Queue\Message $m) => $m->getPayload()['n'], $messages));
        $this->broker->extend($this->queue, ...$messages);
        $this->broker->reject($this->queue, array_shift($messages));
        foreach ($messages as $message) {
            $this->broker->commit($this->queue, $message);
        }
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
        $this->assertSame(1, $this->broker->getQueueSize($this->queue, true));
        $this->assertSame('99', $this->redis->get($this->key('stats') . '.success'));
        try {
            $this->broker->commit($this->queue, $messages[0]);
            self::fail('Duplicate completion must not succeed');
        } catch (\RuntimeException) {
        }
        $this->assertSame('99', $this->redis->get($this->key('stats') . '.success'));
    }

    public function testDecodeCrashLeavesRecoverableReservation(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $receive = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
            public function execute(string $script, array $keys, array $args): mixed
            {
                if (str_starts_with($script, '-- KEYS: reservations, reservation,')) {
                    throw new \RuntimeException('Process lost before finalization');
                }
                return parent::execute($script, $keys, $args);
            }
        };
        $broker = new Broker($receive, $receive);
        try {
            $broker->receive($this->queue, 0, 100);
            self::fail('Expected simulated crash');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, $broker->getQueueSize($this->queue));
        $reservations = $this->redis->zRange($this->key('reservations'), 0, -1);
        $this->assertCount(1, $reservations);
        $this->assertSame(1, $this->redis->lLen($reservations[0]));
        $this->redis->zAdd($this->key('reservations'), 0, $reservations[0]);
        $broker->maintain();
        $this->assertSame(1, $broker->getQueueSize($this->queue));
        $this->assertSame(['n' => 1], $this->broker->receive($this->queue, 0)[0]->getPayload());
    }

    public function testPoisonAndStaleOwnership(): void
    {
        $this->redis->lPush($this->key('queue'), 'invalid');
        $this->broker->publish($this->queue, ['n' => 1]);
        $messages = $this->broker->receive($this->queue, 0, 100);
        $this->assertCount(1, $messages);
        $this->assertSame(1, $this->redis->lLen($this->key('poison')));
        $this->redis->set($this->key('claims') . '.' . $messages[0]->getPid(), 'new-owner');
        $this->broker->extend($this->queue, ...$messages);
        $this->assertSame('new-owner', $this->redis->get($this->key('claims') . '.' . $messages[0]->getPid()));
        $this->expectException(\RuntimeException::class);
        $this->broker->commit($this->queue, $messages[0]);
    }

    public function testKilledBlockingReceiverLeavesRecoverableBytes(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('Requires pcntl');
        }
        $pid = pcntl_fork();
        if ($pid === 0) {
            $receive = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
                public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false
                {
                    $raw = parent::rightPopLeftPush($queue, $destination, $timeout);
                    posix_kill(getmypid(), SIGKILL);
                    return $raw;
                }
            };
            new Broker($receive, $receive)->receive($this->queue, 3, 100);
            exit(1);
        }
        usleep(100_000);
        $this->broker->publish($this->queue, ['survives' => true]);
        pcntl_waitpid($pid, $status);
        $this->assertTrue(pcntl_wifsignaled($status));
        $reservations = $this->redis->zRange($this->key('reservations'), 0, -1);
        $this->assertCount(1, $reservations);
        $this->assertSame(1, $this->redis->lLen($reservations[0]));
        $this->redis->zAdd($this->key('reservations'), 0, $reservations[0]);
        // Register the queue without consuming its reservation, then recover it.
        $this->broker->receive($this->queue, 0);
        $this->broker->maintain();
        $message = $this->broker->receive($this->queue, 0)[0];
        $this->assertSame(['survives' => true], $message->getPayload());
        $this->broker->commit($this->queue, $message);
    }

    public function testLostSettlementReplyDoesNotDoubleCountOrReject(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $message = $this->broker->receive($this->queue, 0)[0];
        $commands = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
            public function execute(string $script, array $keys, array $args): mixed
            {
                parent::execute($script, $keys, $args);
                throw new \RuntimeException('Reply lost after server applied settlement');
            }
        };
        try {
            new Broker($commands, $commands)->commit($this->queue, $message);
            self::fail('Expected ambiguous reply');
        } catch (\RuntimeException) {
        }
        try {
            $this->broker->commit($this->queue, $message);
        } catch (\RuntimeException) {
        }
        $this->assertSame('1', $this->redis->get($this->key('stats') . '.success'));
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
        $this->assertSame(0, $this->broker->getQueueSize($this->queue, true));
    }

    public function testClusterRejectsUnsafePlacementBeforeRemovingWork(): void
    {
        $connection = new \Utopia\Queue\Connection\RedisCluster(['127.0.0.1:17000', '127.0.0.1:17001', '127.0.0.1:17002']);
        $broker = new Broker($connection, $connection);
        $queue = new Queue('cluster', 'unsafe-' . bin2hex(random_bytes(6)));
        $broker->publish($queue, ['n' => 1]);
        try {
            $broker->receive($queue, 0, 100);
            self::fail('Cross-slot claiming must fail before moving messages');
        } catch (\InvalidArgumentException $error) {
            $this->assertStringContainsString('shared hash tag', $error->getMessage());
        }
        $this->assertSame(1, $broker->getQueueSize($queue));
        $connection->remove($queue->namespace . '.queue.' . $queue->name);
        $connection->close();
    }

    public function testGroupedSettlementPreservesHealthyResultsWhenOneClaimIsInvalid(): void
    {
        $this->broker->enqueueMany($this->queue, array_fill(0, 3, ['n' => 1]));
        $messages = $this->broker->receive($this->queue, 0, 3);
        $claim = $this->key('claims') . '.' . $messages[1]->getPid();
        $this->redis->del($claim);
        $this->redis->lPush($claim, 'invalid');
        $commands = new class ('127.0.0.1', (int) (getenv('REDIS_PORT') ?: 16379)) extends Connection {
            public array $sizes = [];
            public function execute(string $script, array $keys, array $args): mixed
            {
                $this->sizes[] = \count($keys) / 6;
                // Let other completions arrive while the first request is in flight.
                \Swoole\Coroutine::sleep(0.01);
                return parent::execute($script, $keys, $args);
            }
        };
        $broker = new Broker($commands, $commands);
        $results = [];
        \Swoole\Coroutine\run(function () use ($broker, $messages, &$results): void {
            foreach ($messages as $index => $message) {
                \Swoole\Coroutine::create(function () use ($broker, $message, $index, &$results): void {
                    try {
                        $broker->commit($this->queue, $message);
                        $results[$index] = true;
                    } catch (\RedisException) {
                        $results[$index] = false;
                    }
                });
            }
        });
        ksort($results);
        $this->assertSame([true, false, true], $results);
        $this->assertSame([1, 2], $commands->sizes);
        $this->assertSame('2', $this->redis->get($this->key('stats') . '.success'));
        $this->assertSame(1, $this->redis->lLen($this->key('processing')));
    }

    public function testScriptCacheLossReloadsWithoutRepeatingCompletions(): void
    {
        $this->broker->publish($this->queue, ['n' => 1]);
        $first = $this->broker->receive($this->queue, 0)[0];
        $this->broker->commit($this->queue, $first);
        $this->broker->publish($this->queue, ['n' => 2]);
        $second = $this->broker->receive($this->queue, 0)[0];
        $this->redis->script('flush');
        $this->broker->commit($this->queue, $second);
        $this->assertSame('2', $this->redis->get($this->key('stats') . '.success'));
        $this->assertSame(0, $this->redis->lLen($this->key('processing')));
    }

    private function key(string $kind): string
    {
        return $this->queue->namespace . '.' . $kind . '.' . $this->queue->name;
    }
}
