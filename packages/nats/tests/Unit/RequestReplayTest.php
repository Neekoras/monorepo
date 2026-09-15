<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Utopia\NATS\Connection;
use Utopia\NATS\ConnectionOptions;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Tests\Unit\Support\FakeTransport;
use Utopia\NATS\Transport\Transport;

final class RequestReplayTest extends TestCase
{
    /**
     * A server that had already closed the socket when the request was written:
     * the client's PUB goes nowhere, and the next read yields the -ERR the
     * server queued before closing. Once reconnected, the server answers.
     */
    private function connect(StaleThenAnsweringTransport $transport, bool $allowReconnect = true): Connection
    {
        return Connection::connect(new ConnectionOptions(
            servers: 'nats://127.0.0.1:4222',
            allowReconnect: $allowReconnect,
            reconnectWait: 0.0,
            reconnectJitter: 0.0,
            transportFactory: fn(string $scheme): Transport => $transport,
        ));
    }

    public function testRequestIsReplayedOnceAfterAClosingErrorReconnects(): void
    {
        $transport = new StaleThenAnsweringTransport();
        $conn = $this->connect($transport);

        $reply = $conn->request('orders.create', '{"id":1}', 1.0);

        $this->assertSame('accepted', $reply->data);
        $this->assertSame(2, $transport->requests, 'The request must be written once to the dead socket and once to the rebuilt one');

        $conn->close();
    }

    public function testRequestIsNotReplayedWhenTheConnectionCannotReconnect(): void
    {
        $transport = new StaleThenAnsweringTransport();
        $conn = $this->connect($transport, allowReconnect: false);

        try {
            $conn->request('orders.create', '{"id":1}', 1.0);
            $this->fail('Expected the -ERR to surface');
        } catch (ProtocolException) {
            // expected
        }

        $this->assertSame(1, $transport->requests, 'Without a live connection there is nothing to replay on');
        $this->assertFalse($conn->isConnected());
    }
}

/**
 * Answers the first request with the -ERR a server sends as it closes a stale
 * connection, and every later one with a reply on the request's inbox.
 */
final class StaleThenAnsweringTransport implements Transport
{
    public int $requests = 0;

    private readonly FakeTransport $inner;

    private string $inboxSid = '';

    public function __construct()
    {
        $this->inner = new FakeTransport();
    }

    public function connect(string $host, int $port, float $timeout): void
    {
        $this->inner->connect($host, $port, $timeout);
    }

    public function write(string $data): int
    {
        if (preg_match('/^SUB \S+ (\S+)\r\n/m', $data, $sub) === 1) {
            $this->inboxSid = $sub[1];
        }

        if (preg_match('/^(?:H)?PUB \S+ (\S+) \d+\r\n/m', $data, $pub) === 1) {
            $this->requests++;

            $this->inner->pushInbound($this->requests === 1
                ? "-ERR 'Stale Connection'\r\n"
                : "MSG {$pub[1]} {$this->inboxSid} 8\r\naccepted\r\n");
        }

        return $this->inner->write($data);
    }

    public function read(int $maxBytes, ?float $timeout = null): string
    {
        return $this->inner->read($maxBytes, $timeout);
    }

    public function readLine(?float $timeout = null): string
    {
        return $this->inner->readLine($timeout);
    }

    public function upgradeTls(array $options): void
    {
        $this->inner->upgradeTls($options);
    }

    public function isConnected(): bool
    {
        return $this->inner->isConnected();
    }

    public function close(): void
    {
        $this->inner->close();
    }
}
