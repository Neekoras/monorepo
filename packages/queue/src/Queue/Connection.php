<?php

declare(strict_types=1);

namespace Utopia\Queue;

interface Connection
{
    public function rightPushArray(string $queue, array $payload): bool;

    /**
     * Push several already-encoded payloads in one command.
     *
     * @param list<string> $payloads
     */
    public function rightPushMany(string $queue, array $payloads): bool;
    public function rightPopArray(string $queue, int $timeout): array|false;
    public function rightPopLeftPushArray(string $queue, string $destination, int $timeout): array|false;
    public function leftPushArray(string $queue, array $payload): bool;

    /**
     * Push several already-encoded payloads in one command.
     *
     * @param list<string> $payloads
     */
    public function leftPushMany(string $queue, array $payloads): bool;
    public function leftPopArray(string $queue, int $timeout): array|false;
    public function rightPush(string $queue, string $payload): bool;
    public function rightPop(string $queue, int $timeout): string|false;

    /**
     * Pop up to $count payloads from the tail, in pop order, without blocking.
     *
     * The companion to {@see self::rightPop()} for a consumer draining a
     * backlog: one command for the messages already waiting, rather than one
     * round trip each. Returns fewer than asked -- including none -- when the
     * list runs out, which is the ordinary case and not an error.
     *
     * @return list<string>
     */
    public function rightPopMany(string $queue, int $count): array;
    public function rightPopLeftPush(string $queue, string $destination, int $timeout): string|false;
    public function leftPush(string $queue, string $payload): bool;
    public function leftPop(string $queue, int $timeout): string|false;
    public function listRemove(string $queue, string $key): bool;
    public function listSize(string $key): int;
    public function listRange(string $key, int $total, int $offset): array;
    public function remove(string $key): bool;
    public function set(string $key, string $value, int $ttl = 0): bool;
    public function get(string $key): array|string|null;
    public function setArray(string $key, array $value, int $ttl = 0): bool;
    public function increment(string $key): int;

    /** Add $by to a counter in one command, returning the new value. */
    public function incrementBy(string $key, int $by): int;
    public function decrement(string $key): int;
    public function ping(): bool;
    public function close(): void;
}
