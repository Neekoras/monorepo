<?php

declare(strict_types=1);

namespace Utopia\UserAgent\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Utopia\UserAgent\UserAgent;

final class NativeAppDetectionTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string}>
     */
    public static function applications(): array
    {
        return [
            'Flutter iPhone' => ['com.example.myapp/1.0.0 iPhone17,1 iOS/18.1', 'com.example.myapp', '1.0'],
            'Flutter iPad' => ['org.example.Reader/2.4.1 iPad14,3 iOS/18.2', 'org.example.Reader', '2.4'],
            'Flutter iPod' => ['com.example.player/3.2 iPod9,1 iOS/15.8.4', 'com.example.player', '3.2'],
            'Apple silicon simulator' => ['com.example.my-app/1.2.3 arm64 iOS/18.1', 'com.example.my-app', '1.2'],
            'Intel simulator' => ['com.example.myapp/1.0 x86_64 iOS/18.1', 'com.example.myapp', '1.0'],
        ];
    }

    #[DataProvider('applications')]
    public function testNativeApp(string $value, string $name, string $version): void
    {
        $agent = UserAgent::parse($value);

        $this->assertSame('mobile app', $agent->client()->type);
        $this->assertSame($name, $agent->client()->name);
        $this->assertSame($version, $agent->client()->version);
        $this->assertNull($agent->client()->code);
        $this->assertNull($agent->client()->engine);
        $this->assertFalse($agent->client()->isBrowser());
        $this->assertSame($value, $agent->raw());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownClients(): array
    {
        return [
            'no application identifier' => ['iPhone17,1 iOS/18.1'],
            'no platform' => ['com.example.myapp/1.0.0'],
            'no version' => ['com.example.myapp iPhone17,1 iOS/18.1'],
            'not a bundle identifier' => ['Unknown/1.0.0 iPhone17,1 iOS/18.1'],
            'empty identifier segment' => ['com..myapp/1.0.0 iPhone17,1 iOS/18.1'],
            'invalid identifier' => ['com.example?myapp/1.0.0 iPhone17,1 iOS/18.1'],
            'invalid app version' => ['com.example.myapp/1..0 iPhone17,1 iOS/18.1'],
            'invalid OS version' => ['com.example.myapp/1.0.0 iPhone17,1 iOS/unknown'],
            'unknown device' => ['com.example.myapp/1.0.0 Anything iOS/18.1'],
            'invalid machine identifier' => ['com.example.myapp/1.0.0 iPhoneGarbage iOS/18.1'],
            'incomplete machine identifier' => ['com.example.myapp/1.0.0 iPhone17 iOS/18.1'],
            'newline suffix' => ["com.example.myapp/1.0.0 iPhone17,1 iOS/18.1\n"],
            'extra tokens' => ['com.example.myapp/1.0.0 iPhone17,1 iOS/18.1 extra'],
            'unrelated product' => ['com.example.library/1.0.0 (Linux; U; Ubuntu 24.04)'],
        ];
    }

    #[DataProvider('unknownClients')]
    public function testUnknownClient(string $value): void
    {
        $this->assertFalse(UserAgent::parse($value)->client()->isKnown());
    }

    public function testNativeAppPreservesPlatform(): void
    {
        $agent = UserAgent::parse('com.example.myapp/1.0.0 iPhone17,1 iOS/18.1');

        $this->assertSame('mobile app', $agent->client()->type);
        $this->assertSame([
            'code' => 'IOS',
            'name' => 'iOS',
            'version' => '18',
        ], $agent->operatingSystem()->toArray());
        $this->assertSame([
            'type' => 'smartphone',
            'brand' => 'Apple',
            'model' => 'iPhone',
        ], $agent->device()->toArray());
    }

    public function testBrowserTokensKeepTheirIdentity(): void
    {
        $agent = UserAgent::parse(
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) '
            . 'AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.1 Mobile/15E148 Safari/604.1 '
            . 'com.example.myapp/1.0.0 iPhone17,1 iOS/18.1',
        );

        $this->assertSame('browser', $agent->client()->type);
        $this->assertSame('Mobile Safari', $agent->client()->name);
        $this->assertSame('WebKit', $agent->client()->engine);
    }

    public function testLibraryTokensKeepTheirIdentity(): void
    {
        $agent = UserAgent::parse('Dart/3.7.0 (dart:io) com.example.myapp/1.0.0 iPhone17,1 iOS/18.1');

        $this->assertSame('library', $agent->client()->type);
        $this->assertSame('Dart', $agent->client()->name);
        $this->assertFalse($agent->client()->isBrowser());
    }
}
