<?php

declare(strict_types=1);

namespace Utopia\Tests\Adapter;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Swoole\Coroutine;
use Utopia\Messaging\Adapter\Push as PushAdapter;
use Utopia\Messaging\Messages\Push;

/**
 * Network-free verification of the concurrent fan-out in requestMulti(): result
 * mapping, the concurrency ceiling, client reuse, and per-slot error isolation.
 */
final class RequestMultiTest extends TestCase
{
    public function testEveryRequestGetsAResultKeyedByItsIndex(): void
    {
        $adapter = new FanOutAdapter();

        $results = $adapter->fanOut(array_map(
            static fn(int $i): string => "http://127.0.0.1/send/{$i}",
            range(0, 39),
        ));

        $this->assertCount(40, $results);

        // Completion order is not the request order, so each result carries the
        // index its caller uses to map back to a recipient.
        $byIndex = array_column($results, null, 'index');
        ksort($byIndex);

        $this->assertSame(range(0, 39), array_keys($byIndex));

        foreach ($byIndex as $index => $result) {
            $this->assertSame("http://127.0.0.1/send/{$index}", $result['url']);
            $this->assertSame(200, $result['statusCode']);
            $this->assertSame(['ok' => $index], $result['response']);
            $this->assertSame('', $result['error']);
        }
    }

    public function testConcurrencyIsCappedAndClientsAreReusedAcrossRequests(): void
    {
        $adapter = new FanOutAdapter();

        $adapter->fanOut(array_fill(0, 200, 'http://127.0.0.1/send'));

        // The ceiling is what keeps a large recipient batch from opening 200
        // sockets at once.
        $this->assertSame(25, $adapter->peakConcurrent);

        // One client per worker, not one per request: a client holds a cURL
        // handle, so allocating them per request leaked native memory and file
        // descriptors on every send.
        $this->assertSame(25, $adapter->clientsCreated);
        $this->assertSame(200, $adapter->requestsSent);
    }

    public function testFewerRequestsThanTheCeilingCreateOnlyWhatIsNeeded(): void
    {
        $adapter = new FanOutAdapter();

        $adapter->fanOut(['http://127.0.0.1/a', 'http://127.0.0.1/b', 'http://127.0.0.1/c']);

        $this->assertSame(3, $adapter->clientsCreated);
    }

    public function testASingleRequestStillFansOut(): void
    {
        $adapter = new FanOutAdapter();

        $results = $adapter->fanOut(['http://127.0.0.1/only']);

        $this->assertCount(1, $results);
        $this->assertSame(1, $adapter->clientsCreated);
        $this->assertSame(0, $results[0]['index']);
    }

    public function testAFailingRequestDoesNotSinkTheRestOfTheBatch(): void
    {
        $adapter = new FanOutAdapter();
        $adapter->failUrls = ['http://127.0.0.1/send/3'];

        $results = $adapter->fanOut(array_map(
            static fn(int $i): string => "http://127.0.0.1/send/{$i}",
            range(0, 9),
        ));

        $byIndex = array_column($results, null, 'index');

        $this->assertSame(0, $byIndex[3]['statusCode']);
        $this->assertSame('Connection refused', $byIndex[3]['error']);
        $this->assertNull($byIndex[3]['response']);
        $this->assertSame('http://127.0.0.1/send/3', $byIndex[3]['url']);

        // The other nine still land.
        foreach ([0, 1, 2, 4, 5, 6, 7, 8, 9] as $index) {
            $this->assertSame(200, $byIndex[$index]['statusCode']);
        }
    }

    public function testAFailingClientFactoryIsReportedPerSlot(): void
    {
        $adapter = new FanOutAdapter();
        $adapter->failFactory = true;

        $results = $adapter->fanOut(['http://127.0.0.1/a', 'http://127.0.0.1/b']);

        $this->assertCount(2, $results);

        foreach ($results as $result) {
            $this->assertSame(0, $result['statusCode']);
            $this->assertSame('No client for you', $result['error']);
        }
    }

    public function testEmptyUrlsIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No URLs provided. Must provide at least one URL.');

        new FanOutAdapter()->fanOut([]);
    }
}

/**
 * A push adapter reduced to the fan-out, with a counting in-memory client in
 * place of the network.
 */
final class FanOutAdapter extends PushAdapter
{
    public int $clientsCreated = 0;

    public int $requestsSent = 0;

    public int $concurrent = 0;

    public int $peakConcurrent = 0;

    public bool $failFactory = false;

    /**
     * @var array<string>
     */
    public array $failUrls = [];

    public function __construct()
    {
        parent::__construct(clientFactory: function (): ClientInterface {
            if ($this->failFactory) {
                throw new \RuntimeException('No client for you');
            }

            ++$this->clientsCreated;

            return new CountingClient($this);
        });
    }

    public function getName(): string
    {
        return 'FanOut';
    }

    public function getMaxMessagesPerRequest(): int
    {
        return 5000;
    }

    /**
     * @param  array<string>  $urls
     * @return array<array<string, mixed>>
     */
    public function fanOut(array $urls): array
    {
        return $this->requestMulti(
            method: 'POST',
            urls: $urls,
            headers: ['Content-Type: application/json'],
            bodies: [['payload' => true]],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function process(Push $message): array
    {
        return [];
    }
}

final readonly class CountingClient implements ClientInterface
{
    public function __construct(private FanOutAdapter $adapter) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $url = (string) $request->getUri();

        ++$this->adapter->requestsSent;
        ++$this->adapter->concurrent;
        $this->adapter->peakConcurrent = max($this->adapter->peakConcurrent, $this->adapter->concurrent);

        try {
            // Yield so the workers genuinely overlap; without a suspension point
            // each would run to completion and peak concurrency would read as 1.
            Coroutine::sleep(0.01);

            if (\in_array($url, $this->adapter->failUrls, true)) {
                throw new ConnectionFailed('Connection refused');
            }

            $index = (int) (explode('/', $url)[\count(explode('/', $url)) - 1] ?: 0);

            return new Response(200, ['Content-Type' => 'application/json'], json_encode(['ok' => $index]));
        } finally {
            --$this->adapter->concurrent;
        }
    }
}

final class ConnectionFailed extends \RuntimeException implements \Psr\Http\Client\ClientExceptionInterface {}
