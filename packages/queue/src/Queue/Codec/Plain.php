<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use ArrayObject;
use stdClass;
use Utopia\Queue\Codec;

/**
 * Hands a handler arrays and scalars, whatever the publisher passed.
 *
 * The two codecs in this package do not agree on what a handler receives. {@see Json}
 * flattens an object on the way out and `json_decode(assoc: true)` returns an array on
 * the way back; {@see Igbinary} preserves the class. So the same payload arrives as two
 * different types depending on a configuration value, and a consumer written against one
 * breaks on the other with no compile-time warning and no test to catch it -- the test
 * over a JSON codec passes either way.
 *
 * That is not theoretical. A consumer whose handlers do `new Document($payload['project'])`
 * -- an idiom throughout this ecosystem, where Document extends ArrayObject -- ran on JSON
 * for years and wedged within minutes of switching the writer to igbinary: every delivery
 * of every affected message threw a TypeError, each one holding a `maxAckPending` slot
 * through its whole backoff, until the consumer had no slot left to deliver into.
 *
 * Wrapping the codec in this makes the payload's shape a property of the payload rather
 * than of the format, in both directions:
 *
 *     $codec = new Plain(new Compat(new Igbinary()));
 *
 * Reading matters as much as writing. Bytes outlive the build that wrote them -- queued,
 * in flight, and on dead letters that have no deadline -- so a message written by an
 * older release, or by a pod that has not rolled yet, still decodes through this.
 *
 * Deliberately narrow: an {@see ArrayObject} becomes its storage and a {@see stdClass}
 * becomes an array, at any depth. Both are what an envelope actually carries -- a
 * document-shaped value, and the empty map a JSON renderer leaves behind for `{}`.
 * Any other class is passed through untouched, because a codec quietly reshaping a
 * type nobody considered is how this class of defect is made, not how it is fixed.
 *
 * Opt in by composing it. Nothing here wraps anything on its own: a consumer that means
 * to round-trip objects through igbinary is doing something this package supports.
 */
final class Plain implements Codec
{
    public function __construct(private readonly Codec $inner) {}

    public function encode(mixed $value): string
    {
        return $this->inner->encode($this->plain($value));
    }

    public function decode(string $value): mixed
    {
        return $this->plain($this->inner->decode($value));
    }

    public function contentType(): string
    {
        return $this->inner->contentType();
    }

    /**
     * Arrays and scalars, all the way down.
     *
     * getArrayCopy() alone would not do it: that flattens one level, so an ArrayObject
     * nested inside another survives it.
     */
    private function plain(mixed $value): mixed
    {
        if ($value instanceof ArrayObject) {
            return $this->plain($value->getArrayCopy());
        }

        if ($value instanceof stdClass) {
            return $this->plain((array) $value);
        }

        if (\is_array($value)) {
            return array_map($this->plain(...), $value);
        }

        return $value;
    }
}
