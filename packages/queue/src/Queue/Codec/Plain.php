<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use ArrayObject;
use RuntimeException;
use stdClass;
use Utopia\Queue\Codec;

/**
 * Hands a handler arrays and scalars, whatever the publisher passed.
 *
 * {@see Json} flattens an object on the way out and returns an array on the way back;
 * {@see Igbinary} preserves the class. So the same payload reaches a handler as two
 * different types depending on which writer is configured, and a consumer written
 * against one breaks on the other -- with no compile-time warning and no failing test,
 * since a test over the JSON codec passes either way.
 *
 * Composing this makes the shape a property of the payload rather than of the format:
 *
 *     $codec = new Plain(new Compat(new Igbinary()));
 *
 * Both directions, because bytes outlive the build that wrote them -- queued, in flight,
 * and on dead letters that have no deadline.
 *
 * An ArrayObject becomes its storage and a stdClass becomes an array, at any depth. Any
 * other class is left alone: a codec quietly reshaping a type nobody considered is how
 * this class of defect is made, not how it is fixed.
 */
final class Plain implements Codec
{
    /**
     * json_encode's own default, and for the same reason: a value that refers to itself
     * has no flat form, and the cheapest way to say so is to stop descending.
     */
    private const int MAX_DEPTH = 512;

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
     * The cast, not getArrayCopy(): a subclass may define its own copy that walks nested
     * objects for you -- Utopia\Database\Document does -- and the descent then happens
     * inside that, where the depth below cannot see it. Casting hands back the storage
     * one level deep and leaves the walking here.
     *
     * Depth is what refuses a payload that refers to itself, whether the loop runs
     * through an object or through a native array with a reference in it. Both are
     * shapes igbinary will carry and json_encode refuses -- the same limit, for the same
     * reason, so a payload this accepts is one JSON would have accepted too.
     *
     * @throws RuntimeException on a payload that nests deeper than a message can
     */
    private function plain(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('Queue payload nests deeper than ' . self::MAX_DEPTH . ' levels, or refers to itself.');
        }

        if (\is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->plain($item, $depth + 1), $value);
        }

        if (!$value instanceof ArrayObject && !$value instanceof stdClass) {
            return $value;
        }

        return $this->plain((array) $value, $depth + 1);
    }
}
