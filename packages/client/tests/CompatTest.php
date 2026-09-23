<?php

declare(strict_types=1);

namespace Utopia\Client\Tests;

use PHPUnit\Framework\TestCase;
use Utopia\Client\Client;
use Utopia\Client\Psr18\StreamingClientInterface;

final class CompatTest extends TestCase
{
    public function testOldClientNameResolvesToTheMovedClass(): void
    {
        $old = 'Utopia\Client';
        if (!class_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(Client::class, new \ReflectionClass($old)->getName());
    }

    public function testOldStreamingInterfaceNameResolvesToTheMovedInterface(): void
    {
        $old = 'Utopia\Psr18\StreamingClientInterface';
        if (!interface_exists($old)) {
            $this->fail($old . ' does not resolve');
        }

        $this->assertSame(StreamingClientInterface::class, new \ReflectionClass($old)->getName());
    }
}
