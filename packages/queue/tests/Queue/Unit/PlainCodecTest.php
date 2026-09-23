<?php

declare(strict_types=1);

namespace Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Utopia\Queue\Codec;
use Utopia\Queue\Codec\Igbinary;
use Utopia\Queue\Codec\Json;
use Utopia\Queue\Codec\Plain;

/**
 * Every assertion here rides a codec that preserves types, because that is the only kind
 * that can fail. Json flattens an object on the way out and returns an array on the way
 * back, so a test written over it passes whether Plain is there or not -- the same
 * accident that hides this defect in a consumer until the writer is switched.
 *
 * ext-igbinary is not on every machine, so the preserving codec is PHP's own serialize().
 * It has the property that matters: an object goes in and the same class comes back. The
 * igbinary case is covered separately and skips where the extension is missing.
 */
final class PlainCodecTest extends TestCase
{
    public function testAnArrayObjectDoesNotReachAConsumerAsAnObject(): void
    {
        $preserving = $this->preserving();

        // The control: without Plain, this is what a consumer is handed.
        $raw = $preserving->decode($preserving->encode(['payload' => new ArrayObject(['id' => 'p1'])]));
        $this->assertInstanceOf(ArrayObject::class, $raw['payload'], 'the inner codec preserves types, or this test proves nothing');

        $codec = new Plain($preserving);
        $decoded = $codec->decode($codec->encode(['payload' => new ArrayObject(['id' => 'p1', 'name' => 'test'])]));

        $this->assertSame([], $this->objectsIn($decoded));
        $this->assertSame('p1', $decoded['payload']['id']);
        $this->assertSame('test', $decoded['payload']['name']);
    }

    public function testAnArrayObjectNestedInsideAnotherIsFlattenedToo(): void
    {
        // getArrayCopy() flattens one level, so the inner one survives it. This is the
        // case a reduction inside a message class misses.
        $codec = new Plain($this->preserving());

        $decoded = $codec->decode($codec->encode([
            'payload' => new ArrayObject(['id' => 'p1', 'team' => new ArrayObject(['id' => 't1'])]),
        ]));

        $this->assertSame([], $this->objectsIn($decoded));
        $this->assertSame('t1', $decoded['payload']['team']['id']);
    }

    public function testAnEmptyMapRenderedForJsonArrivesAsAnEmptyArray(): void
    {
        // A rendered API response carries new stdClass() wherever a map is empty, because
        // that is what {} has to look like in JSON. A consumer already receives [] for
        // these under Json, so this keeps what it sees rather than changing it.
        $codec = new Plain($this->preserving());

        $decoded = $codec->decode($codec->encode(['payload' => ['prefs' => new \stdClass(), 'name' => 'test']]));

        $this->assertSame([], $this->objectsIn($decoded));
        $this->assertSame([], $decoded['payload']['prefs']);
        $this->assertSame('test', $decoded['payload']['name']);
    }

    public function testBytesAnOlderReleaseWroteAreDecodedPlain(): void
    {
        // Bytes outlive the build that wrote them: queued, in flight, and on dead letters
        // that have no deadline. A message published before this existed still has to
        // reach a handler as arrays.
        $preserving = $this->preserving();
        $bytes = $preserving->encode(['payload' => new ArrayObject(['id' => 'p1'])]);

        $decoded = (new Plain($preserving))->decode($bytes);

        $this->assertSame([], $this->objectsIn($decoded));
        $this->assertSame('p1', $decoded['payload']['id']);
    }

    public function testAClassThisDoesNotKnowIsLeftAlone(): void
    {
        // The boundary, on purpose. Reshaping a type nobody considered is how this class
        // of defect is made; a consumer that publishes one should see it, not have it
        // quietly turned into something else.
        $codec = new Plain($this->preserving());

        $decoded = $codec->decode($codec->encode(['payload' => new \DateTimeImmutable('2026-01-01 00:00:00')]));

        $this->assertInstanceOf(\DateTimeImmutable::class, $decoded['payload']);
    }

    public function testTheSameHoldsThroughIgbinary(): void
    {
        if (!\extension_loaded('igbinary')) {
            $this->markTestSkipped('igbinary is what preserves the type here; this build cannot write it.');
        }

        $bare = new Igbinary();
        $raw = $bare->decode($bare->encode(['payload' => new ArrayObject(['id' => 'p1'])]));
        $this->assertInstanceOf(ArrayObject::class, $raw['payload'], 'igbinary preserves the class, or this test proves nothing');

        $codec = new Plain(new Igbinary());
        $decoded = $codec->decode($codec->encode(['payload' => new ArrayObject(['id' => 'p1'])]));

        $this->assertSame([], $this->objectsIn($decoded));
        $this->assertSame('p1', $decoded['payload']['id']);
    }

    public function testJsonIsUnchangedByIt(): void
    {
        // The reason a test over Json proves nothing, stated as a test: Json already
        // answers arrays, so wrapping it changes nothing a consumer can see.
        $bare = new Json();
        $wrapped = new Plain(new Json());
        $value = ['payload' => new ArrayObject(['id' => 'p1', 'team' => new ArrayObject(['id' => 't1'])])];

        $this->assertSame($bare->decode($bare->encode($value)), $wrapped->decode($wrapped->encode($value)));
    }

    /** A codec with igbinary's defining property, available on every build. */
    private function preserving(): Codec
    {
        return new class implements Codec {
            public function encode(mixed $value): string
            {
                return serialize($value);
            }

            public function decode(string $value): mixed
            {
                return unserialize($value, ['allowed_classes' => true]);
            }

            public function contentType(): string
            {
                return 'application/vnd.php.serialized';
            }
        };
    }

    /**
     * Every path holding something a consumer could not construct from.
     *
     * @return list<string> paths, so a failure names the field rather than the count
     */
    private function objectsIn(mixed $value, string $path = ''): array
    {
        if (\is_array($value)) {
            $found = [];
            foreach ($value as $key => $child) {
                $found = [...$found, ...$this->objectsIn($child, $path === '' ? (string) $key : "{$path}.{$key}")];
            }

            return $found;
        }

        return \is_object($value) ? [$path . ' (' . get_debug_type($value) . ')'] : [];
    }
}
