<?php

declare(strict_types=1);

namespace Tests\E2E\Adapter;

use Utopia\Queue\Adapter;
use Utopia\Queue\Broker\Redis;
use Utopia\Queue\Consumer;
use Utopia\Queue\Message;
use Utopia\Queue\Queue;

/**
 * Where a rejected message is parked, which decides whether it runs again.
 *
 * The failed list is a retry queue in all but name: retry() pops it and
 * re-enqueues, so a payload that fails identically on every attempt circulates
 * until maxAttempts or newerThan finally parks it on the dead list. A handler
 * that already knows the answer should not have to wait for that circuit.
 */
final class RedisRejectTest extends RedisTestCase
{
    public function testAnOrdinaryFailureIsParkedForTheRetrySweep(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a']);
        $message = $broker->receive($queue, 0)[0];
        $broker->reject($queue, $message);

        $this->assertSame(1, $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame([], $broker->receive($queue, 0));
        $this->assertSame(0, $connection->listSize($this->namespace . '.dead.audits'));
    }

    public function testATerminalFailureSkipsTheRetrySweep(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a']);
        $message = $broker->receive($queue, 0)[0];
        $broker->reject($queue, $message->terminal());

        // The dead list is where an exhausted message ends up anyway; a terminal
        // one gets there on the first failure instead of after N of them.
        $this->assertSame(0, $broker->getQueueSize($queue, failedJobs: true));
        $this->assertSame([], $broker->receive($queue, 0));
        $this->assertSame([$message->getPid()], $connection->listRange($this->namespace . '.dead.audits', 10, 0));
    }

    /**
     * The verdict end to end, against real storage: a handler whose payload cannot
     * satisfy its own signature throws, and the message is parked where nothing
     * will bring it back on its own.
     *
     * Asserted on where the message physically is rather than on the flag the
     * adapter set, because the flag is only a claim about the destination -- this
     * stays red if the broker ever routes a terminal reject to the failed list.
     */
    public function testATypeErrorOutOfAHandlerParksTheMessageOnTheDeadList(): void
    {
        $connection = $this->connection;
        $broker = new Redis($connection, $connection);

        $queue = new Queue('audits', $this->namespace);
        $broker->publish($queue, ['task' => 'a', 'project' => 'not-an-array']);
        $message = $broker->receive($queue, 0)[0];

        $adapter = new class ($broker) extends Adapter {
            public function __construct(Consumer $consumer)
            {
                parent::__construct($consumer, 1);
            }

            public function runOne(Queue $queue, Message $message, callable $handler): void
            {
                $this->processFrom($message, $handler, static function (): void {}, static function (): void {}, $queue, $this->consumer);
            }

            public function start(): self
            {
                return $this;
            }

            public function stop(): self
            {
                return $this;
            }

            public function workerStart(callable $callback): self
            {
                return $this;
            }

            public function workerStop(callable $callback): self
            {
                return $this;
            }
        };

        $adapter->runOne($queue, $message, static function (Message $message): void {
            // Thrown by PHP, not by the test: the payload carries a string where
            // the signature takes an array, which is the shape production hits
            // when an envelope holds an object a handler constructs from.
            (static fn(array $project): array => $project)($message->getPayload()['project']);
        });

        // The list, not the summed read: #302 makes getQueueSize(failedJobs: true)
        // count the dead list too, and this message is on it by design.
        $this->assertSame(0, $connection->listSize($this->namespace . '.failed.audits'), 'a type error must not join the retry sweep');
        $this->assertSame([], $broker->receive($queue, 0), 'and must not be delivered again');
        $this->assertSame([$message->getPid()], $connection->listRange($this->namespace . '.dead.audits', 10, 0));
    }
}
