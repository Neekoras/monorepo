<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use ArrayObject;
use RuntimeException;
use SplObjectStorage;
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
    public function __construct(private readonly Codec $inner) {}

    public function encode(mixed $value): string
    {
        return $this->inner->encode($this->plain($value, new SplObjectStorage()));
    }

    public function decode(string $value): mixed
    {
        return $this->plain($this->inner->decode($value), new SplObjectStorage());
    }

    public function contentType(): string
    {
        return $this->inner->contentType();
    }

    /**
     * Arrays and scalars, all the way down.
     *
     * getArrayCopy() alone would not do: it flattens one level, so an ArrayObject nested
     * inside another survives it.
     *
     * $open holds the objects this branch is already inside, so a payload that refers
     * back to itself is refused rather than followed until the stack ends. json_encode
     * refuses the same graph for the same reason. The same object appearing twice in
     * one payload is not that -- it has a flat form -- which is why an object is
     * released again once its own branch is done.
     *
     * @param SplObjectStorage<object, bool> $open
     * @throws RuntimeException on a payload that contains itself
     */
    private function plain(mixed $value, SplObjectStorage $open): mixed
    {
        if (\is_array($value)) {
            return array_map(fn(mixed $item): mixed => $this->plain($item, $open), $value);
        }

        if (!$value instanceof ArrayObject && !$value instanceof stdClass) {
            return $value;
        }

        if (isset($open[$value])) {
            throw new RuntimeException('Payload contains itself; a queue message has to have a flat form.');
        }

        $open[$value] = true;
        $flat = $this->plain($value instanceof ArrayObject ? $value->getArrayCopy() : (array) $value, $open);
        unset($open[$value]);

        return $flat;
    }
}
