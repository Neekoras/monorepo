# Batched consumption for Redis and NATS

Tracking: https://github.com/appwrite-labs/cloud/issues/5996

Research: [canonical clients and Redis-list implementations](prior-art.md).

## Objective and contract

Batch size controls prefetch; coroutines control concurrent handlers. Batch 100 with one coroutine must work. Reduce network round trips without acknowledging unfinished work or changing NATS's explicit, server-confirmed acknowledgements.

- Bound waiting, running, and completed-but-unconfirmed deliveries together by `max(batch, coroutines)`.
- Return available messages promptly on sparse queues; never wait to fill a batch.
- Keep independent outcomes for successes, failures, malformed messages, and uncertain confirmations. An acknowledgement error must not reject work whose handler succeeded.
- Retain at-least-once delivery. A crash after a side effect but before confirmation can produce duplicates; this is not an exactly-once contract.
- Keep Redis lists and existing opaque ready payloads. Redis Streams remains a separate migration.

## Implemented design

### Redis

Retain existing queue keys and codecs rather than requiring a coordinated ID/payload migration across producers. This needs a recoverable reservation phase:

1. A bounded Lua operation moves available raw payloads into a reservation list and registers its deadline atomically.
2. On an empty queue, register the reservation before a blocking move. Its deadline includes the blocking timeout, so a process killed immediately after the move leaves discoverable work.
3. Decode in PHP. A second bounded script establishes job records, ownership tokens, processing entries, counters, and poison entries, then removes the reservation.
4. Maintenance discovers expired reservations and passes their keys explicitly into recovery. Recovery rechecks deadlines atomically; this works with Dragonfly's declared-key requirement and prevents a stale candidate list from recovering a renewed reservation.

Completions already waiting on the broker are combined into a bounded script with one result per message. There is no timer and no wait for a full batch. This was selected after comparing it with pipelined per-message scripts: local batch-100 measurements showed substantially lower client CPU with grouped settlement. State cleanup and counters remain conditional on ownership, preventing duplicate counting and stale acknowledgement. Grouped renewal never recreates an expired claim.

Lua work is bounded to at most 1,000 reservations or completions per operation. List removal still scans processing entries; fewer network requests do not make server work constant. Validate key types and counters before transitions; Lua prevents interleaving but does not roll back runtime errors.

Redis Cluster requires a shared namespace hash tag before atomic multi-key operations can run. Reject unsafe placement before moving work. Existing Cluster queues need an explicit drain or key migration; do not silently change their namespace.

### NATS

Keep JetStream responsible for pending deliveries, retry accounting, and recovery. Keep the blocking first message, and no-wait top-up. Incremental delivery inside the NATS client remains a possible later simplification; no public iterator API change is needed here.

`Connection::requestBatch()` sends typed requests together using the same lifecycle as `request()`. `JetStream::ackBatch()` translates selected messages into individual acknowledgement requests and owns their confirmation semantics. Successful results are delivered before unrelated replies time out. Ambiguous writes are not replayed. The queue coalesces acknowledgements already waiting on its command connection, retaining per-message confirmation and error handling. If that connection disconnects, a broker configured with a connection factory drops its cached connection and handles under the lock. The next operation opens a new connection without replaying the failed operation or dropping other in-flight deliveries.

`AckAll` is cumulative across the shared consumer, so it could acknowledge another worker's unfinished messages. WorkQueue retention also requires explicit acknowledgement. Neither `AckAll`, unconfirmed `ack()`, nor a connection flush replaces the existing confirmation guarantee.

### Swoole and public APIs

Consolidate the two receive loops. Refill a bounded delivery buffer independently of processing coroutines, and renew all outstanding deliveries from one queue-level loop. A coroutine can begin the next handler while an earlier completion awaits confirmation. Each message retains its own context and success hook; success hooks follow their own confirmation and may run after a later handler has started.

Keep `receive`, `commit`, and `reject` contracts per message. `extend` accepts multiple messages. Optional `release` returns prefetched work that never entered a handler on graceful stop. Existing third-party consumers with single-message renewal still receive individual renewal calls; consumers without release support rely on their broker's recovery.

The process supervisor bounds shutdown with a configurable deadline, 30 seconds by default. Forced termination leaves unfinished deliveries recoverable. Pooled consumers retain their existing lease serialization; dedicated broker connections provide completion batching.

## Validation and remaining gates

Completed local checks include queue and NATS unit suites, the existing NATS broker suite, and real Redis/Redis Cluster/Dragonfly checks. Tests cover batch 100 with one coroutine, renewal of waiting messages past the NATS deadline, independent outcomes, stale ownership, process death after a blocking Redis move, ambiguous completion replies, script-cache loss, unsafe Cluster placement, returning work that never started, and bounded supervisor shutdown.

Compare batch 1, 8, 32, and 100 at fixed coroutine count, payloads, persistence, and confirmation guarantees. Record both wall time and client/server CPU, actual batch fill, requests, bytes, memory, retries, and queue latency. Local no-op measurements establish transport behavior, not a production batch recommendation. Exercise large historical processing lists and representative stats-usage handlers before a canary.

For stats-usage, validate buffered ClickHouse writes versus acknowledgement timing. Handler return can precede durable persistence. Check usage totals under forced failures and record any pre-existing durability issue separately; throughput cannot establish correctness.

## Release and rollout order

1. NATS transport support is released as [1.4.0](https://github.com/utopia-php/nats/releases/tag/1.4.0); the queue requires `^1.4`.
2. Merge and release queue as a new major version: custom Redis connections must implement `execute`, and Cluster requires shared key placement. Release Platform with the queue-major allowance.
3. Prepare Appwrite's package/lock updates, then Cloud's package/lock updates and removal of the batch-versus-coroutines clamp. Keep restrictions for adapters that cannot consume batches. Cloud must not enable this behavior against queue 4.
4. Test both Redis and NATS integrations in staging; current NATS-backed staging traffic cannot validate the Redis path.
5. Open a stats-usage deployment canary with one coroutine, comparing batch 1, 8, 32, and 100 under comparable load. No production default changes before the correctness and efficiency gates pass.
6. Expand only after queue progress, CPU efficiency, latency, and usage accounting checks pass.

Queue 5 removes the `priority` argument from `publish`, `enqueueMany`, and `enqueue`, and removes priority ordering. Redis keeps the same list and existing payloads. NATS keeps `q.<name>.normal` and the `worker` durable, so existing normal messages remain consumable. Before upgrading a queue that used priority, stop priority producers and drain `worker_priority` with old workers, including pending and unacknowledged deliveries. New workers do not consume `q.<name>.priority`. An empty legacy consumer may remain; this change does not delete consumers or queued messages automatically. Do not run old priority producers alongside new workers.

Before rollback, stop receiving, drain work, and recover expired reservation lists while a new consumer's maintenance path remains available. Verify the reservation registry is empty before retiring the last capable consumer. Old consumers can read the unchanged ready payloads but cannot recover new reservations. Reducing the batch to one alone is not a rollback procedure.

Record implementation, benchmark, and deployment findings on the tracking issue. No production settings change as part of implementation.
