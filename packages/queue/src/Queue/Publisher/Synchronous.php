<?php

declare(strict_types=1);

namespace Utopia\Queue\Publisher;

use Utopia\Queue\Queue;

/**
 * A publisher that hands messages to the broker synchronously: publish() blocks
 * until the broker accepts the message and returns whether it did. Brokers such
 * as Redis and Pool implement this directly; Broker\Background wraps one to add
 * background dispatch.
 */
interface Synchronous
{
    /**
     * Publishes a message onto the queue, blocking until the broker accepts it.
     *
     * @param array<string, mixed> $payload
     */
    public function publish(Queue $queue, array $payload): bool;

    /**
     * Publishes several messages, blocking until the broker accepts them.
     * Each payload becomes an independent message, as with publish().
     *
     * @param list<array<string, mixed>> $payloads
     */
    public function publishMany(Queue $queue, array $payloads): bool;

    /**
     * Retries failed jobs.
     */
    public function retry(Queue $queue, ?int $limit = null): void;

    /**
     * Messages waiting to be delivered.
     */
    public function getPendingCount(Queue $queue): int;

    /**
     * Messages this queue could not get through, whatever stopped them.
     *
     * A message leaves the work queue for more than one reason -- rejected with
     * attempts left, rejected terminally or out of attempts, or bytes no codec
     * here could read -- and an operator asking whether a queue is in trouble
     * means all of them. Each broker sums whatever destinations it keeps.
     */
    public function getFailedCount(Queue $queue): int;
}
