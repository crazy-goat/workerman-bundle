<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Tests\Util;

use CrazyGoat\WorkermanBundle\Util\ServiceMethodHelper;
use PHPUnit\Framework\TestCase;

final class ServiceMethodHelperTest extends TestCase
{
    /** @dataProvider provideValidServiceStrings */
    public function testSplitReturnsServiceIdAndMethod(string $input, string $expectedServiceId, string $expectedMethod): void
    {
        [$serviceId, $method] = ServiceMethodHelper::split($input);

        $this->assertSame($expectedServiceId, $serviceId);
        $this->assertSame($expectedMethod, $method);
    }

    /** @return iterable<array{string, string, string}> */
    public static function provideValidServiceStrings(): iterable
    {
        yield 'simple service and method' => ['App\Service::handle', 'App\Service', 'handle'];
        yield 'service with dots' => ['app.my_service::execute', 'app.my_service', 'execute'];
        yield 'method with underscore' => ['SomeService::my_method', 'SomeService', 'my_method'];
    }

    /** @dataProvider provideInvalidServiceStrings */
    public function testSplitThrowsExceptionOnInvalidFormat(string $input, string $expectedMessage): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        ServiceMethodHelper::split($input);
    }

    /** @return iterable<array{string, string}> */
    public static function provideInvalidServiceStrings(): iterable
    {
        yield 'missing separator' => [
            'JustAService',
            'Invalid service method format "JustAService". Expected "serviceId::methodName".',
        ];
        yield 'empty service ID' => [
            '::method',
            'Invalid service method format "::method". Expected "serviceId::methodName".',
        ];
        yield 'empty method name' => [
            'service::',
            'Invalid service method format "service::". Expected "serviceId::methodName".',
        ];
        yield 'empty string' => [
            '',
            'Invalid service method format "". Expected "serviceId::methodName".',
        ];
        yield 'only separator' => [
            '::',
            'Invalid service method format "::". Expected "serviceId::methodName".',
        ];
    }
}
