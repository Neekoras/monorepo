<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;

final class RequestsTest extends TestCase
{
    public function testPipelinesRequestsAndRetainsOutOfOrderPartialReplies(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): \Utopia\NATS\Tests\Unit\Support\FakeTransport => $fake));
        $groups = [];
        $fake->onWrite = static function (string $wire, FakeTransport $fake) use (&$groups): void {
            preg_match_all('/PUB work\.(\d+) ([^ ]+) 0\r\n\r\n/', $wire, $matches, PREG_SET_ORDER);
            if ($matches === []) {
                return;
            }
            $groups[] = \count($matches);
            foreach (array_reverse($matches) as $match) {
                if ($match[1] === '2') {
                    continue;
                }
                $subject = $match[2];
                $fake->pushInbound("MSG {$subject} 1 1\r\n{$match[1]}\r\n");
            }
        };
        $resolved = [];
        $results = $connection->requests([
            ['subject' => 'work.1'], ['subject' => 'work.2'], ['subject' => 'work.3'],
        ], 0.01, static function (int $index) use (&$resolved): void {
            $resolved[] = $index;
        });
        $this->assertSame([3], $groups, 'all requests are sent before waiting for replies');
        $this->assertSame('1', $results[0]->data);
        $this->assertInstanceOf(TimeoutException::class, $results[1]);
        $this->assertSame('3', $results[2]->data);
        $this->assertSame([2, 0, 1], $resolved, 'successful confirmations are delivered before the missing reply times out');
        $property = new \ReflectionProperty($connection, 'pendingRequests');
        $this->assertSame([], $property->getValue($connection));
        $connection->close();
    }
    public function testAmbiguousWriteIsNotReplayedAndClearsPendingRequests(): void
    {
        $fake = new FakeTransport();
        $connection = Connection::connect(new ConnectionOptions(transportFactory: fn(): FakeTransport => $fake));
        $writes = 0;
        $fake->onWrite = static function (string $wire) use (&$writes): void {
            if (str_starts_with($wire, 'PUB ')) {
                $writes++;
                throw new \Utopia\NATS\Exception\ConnectionException('Lost connection after write');
            }
        };
        $results = $connection->requests([['subject' => 'first'], ['subject' => 'second']]);
        $this->assertSame(1, $writes);
        $this->assertInstanceOf(\Utopia\NATS\Exception\ConnectionException::class, $results[0]);
        $this->assertInstanceOf(\Utopia\NATS\Exception\ConnectionException::class, $results[1]);
        $this->assertFalse($connection->isConnected());
        $this->assertSame([], new \ReflectionProperty($connection, 'pendingRequests')->getValue($connection));
        $connection->close();
    }

}
