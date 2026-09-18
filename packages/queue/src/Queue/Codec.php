<?php

declare(strict_types=1);

namespace Utopia\Queue;

/**
 * Turns a message envelope into the bytes a broker carries, and back.
 *
 * Both directions throw on failure: an unencodable value, or bytes that are
 * not a payload this codec wrote. A broker treats a decode failure as a poison
 * message and parks it -- unlike a cache, it cannot shrug one off as a miss,
 * because the bytes are the only copy of somebody's work.
 */
interface Codec
{
    /**
     * @throws \Throwable
     */
    public function encode(mixed $value): string;

    /**
     * @throws \Throwable
     */
    public function decode(string $value): mixed;
}
