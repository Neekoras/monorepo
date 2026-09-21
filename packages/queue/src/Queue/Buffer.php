<?php

declare(strict_types=1);

namespace Utopia\Queue;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/** Coalesce requests already waiting for the same transport, without a timer. */
final class Buffer
{
    private array $pending = [];
    private bool $running = false;

    /** @param \Closure(array, ?callable): array $send Returns one value or Throwable per request. */
    public function __construct(private readonly \Closure $send) {}

    public function request(mixed $request): mixed
    {
        if (!class_exists(Coroutine::class) || Coroutine::getCid() < 0) {
            $result = ($this->send)([$request], null)[0];
        } else {
            $reply = new Channel(1);
            $this->pending[] = [$request, $reply];
            if (!$this->running) {
                $this->running = true;
                Coroutine::create(function (): void {
                    try {
                        while ($this->pending !== []) {
                            $pending = array_splice($this->pending, 0, 1000);
                            try {
                                $delivered = [];
                                $results = ($this->send)(array_column($pending, 0), static function (int $index, mixed $result) use ($pending, &$delivered): void {
                                    $delivered[$index] = true;
                                    $pending[$index][1]->push($result);
                                });
                            } catch (\Throwable $error) {
                                $results = array_fill(0, \count($pending), $error);
                            }
                            foreach ($pending as $index => [, $reply]) {
                                if (!isset($delivered[$index])) {
                                    $reply->push($results[$index] ?? new \RuntimeException('Missing transport result'));
                                }
                            }
                        }
                    } finally {
                        $this->running = false;
                    }
                });
            }
            $result = $reply->pop();
            if ($reply->errCode !== 0) {
                throw new \RuntimeException('Queue confirmation wait was interrupted');
            }
        }

        if ($result instanceof \Throwable) {
            throw $result;
        }

        return $result;
    }
}
