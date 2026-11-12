<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Worker;

use CrazyGoat\WorkermanBundle\Exception\UnsupportedListenSchemeException;
use CrazyGoat\WorkermanBundle\Worker\ListenScheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ListenSchemeTest extends TestCase
{
    #[DataProvider('provideValidListenUrls')]
    public function testFromListenReturnsCorrectScheme(string $listen, ListenScheme $expected): void
    {
        $this->assertSame($expected, ListenScheme::fromListen($listen));
    }

    /** @return iterable<string, array{string, ListenScheme}> */
    public static function provideValidListenUrls(): iterable
    {
        yield 'http with port' => ['http://0.0.0.0:80', ListenScheme::Http];
        yield 'http with ipv6' => ['http://[::]:8080', ListenScheme::Http];
        yield 'http with hostname' => ['http://localhost:8000', ListenScheme::Http];
        yield 'https with port' => ['https://0.0.0.0:443', ListenScheme::Https];
        yield 'https with hostname' => ['https://example.com:8443', ListenScheme::Https];
    }

    #[DataProvider('provideInvalidListenUrls')]
    public function testFromListenThrowsForUnsupportedScheme(string $listen): void
    {
        $this->expectException(UnsupportedListenSchemeException::class);
        $this->expectExceptionMessage(sprintf(
            'Unsupported listen scheme in "%s". Supported schemes: http://, https://',
            $listen,
        ));

        ListenScheme::fromListen($listen);
    }

    /** @return iterable<string, array{string}> */
    public static function provideInvalidListenUrls(): iterable
    {
        yield 'empty string' => [''];
        yield 'tcp scheme' => ['tcp://0.0.0.0:9090'];
        yield 'unix socket' => ['unix:///tmp/workerman.sock'];
        yield 'ftp scheme' => ['ftp://0.0.0.0:21'];
        yield 'no scheme just path' => ['/var/run/workerman.sock'];
        yield 'random string' => ['not-a-url'];
        yield 'only port' => [':8080'];
    }

    #[DataProvider('provideWebSocketListenUrls')]
    public function testFromListenRejectsWebSocketSchemes(string $listen): void
    {
        $this->expectException(UnsupportedListenSchemeException::class);
        $this->expectExceptionMessage(sprintf(
            'WebSocket listeners (ws://, wss://) are not supported yet in "%s". Use http:// or https://.',
            $listen,
        ));

        ListenScheme::fromListen($listen);
    }

    /** @return iterable<string, array{string}> */
    public static function provideWebSocketListenUrls(): iterable
    {
        yield 'ws with port' => ['ws://0.0.0.0:8080'];
        yield 'ws with hostname' => ['ws://chat.example.com:8080'];
        yield 'wss with port' => ['wss://0.0.0.0:8443'];
        yield 'wss with hostname' => ['wss://chat.example.com:443'];
    }

    #[DataProvider('providePrefixExpectations')]
    public function testWorkermanPrefix(ListenScheme $scheme, string $expectedPrefix): void
    {
        $this->assertSame($expectedPrefix, $scheme->workermanPrefix());
    }

    /** @return iterable<string, array{ListenScheme, string}> */
    public static function providePrefixExpectations(): iterable
    {
        yield 'http keeps http' => [ListenScheme::Http, 'http://'];
        yield 'https becomes http' => [ListenScheme::Https, 'http://'];
    }

    #[DataProvider('provideTransportExpectations')]
    public function testTransport(ListenScheme $scheme, string $expectedTransport): void
    {
        $this->assertSame($expectedTransport, $scheme->transport());
    }

    /** @return iterable<string, array{ListenScheme, string}> */
    public static function provideTransportExpectations(): iterable
    {
        yield 'http uses tcp' => [ListenScheme::Http, 'tcp'];
        yield 'https uses ssl' => [ListenScheme::Https, 'ssl'];
    }

    #[DataProvider('provideSslExpectations')]
    public function testRequiresSslContext(ListenScheme $scheme, bool $expected): void
    {
        $this->assertSame($expected, $scheme->requiresSslContext());
    }

    /** @return iterable<string, array{ListenScheme, bool}> */
    public static function provideSslExpectations(): iterable
    {
        yield 'http needs no ssl' => [ListenScheme::Http, false];
        yield 'https needs ssl' => [ListenScheme::Https, true];
    }

    public function testEnumValuesMatchListenPrefixes(): void
    {
        $this->assertSame('http', ListenScheme::Http->value);
        $this->assertSame('https', ListenScheme::Https->value);
        $this->assertSame(['http', 'https'], array_column(ListenScheme::cases(), 'value'));
    }
}
