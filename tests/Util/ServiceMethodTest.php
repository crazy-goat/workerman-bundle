<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Util;

use CrazyGoat\WorkermanBundle\Util\ServiceMethod;
use PHPUnit\Framework\TestCase;

final class ServiceMethodTest extends TestCase
{
    public function testConstructorSetsProperties(): void
    {
        $serviceMethod = new ServiceMethod('my_service', 'my_method');

        $this->assertSame('my_service', $serviceMethod->serviceId);
        $this->assertSame('my_method', $serviceMethod->method);
    }

    public function testToStringReturnsCombinedFormat(): void
    {
        $serviceMethod = new ServiceMethod('my_service', 'my_method');

        $this->assertSame('my_service::my_method', $serviceMethod->toString());
    }

    public function testToStringWithNamespaceService(): void
    {
        $serviceMethod = new ServiceMethod('App\Service\MyService', 'execute');

        $this->assertSame('App\Service\MyService::execute', $serviceMethod->toString());
    }

    public function testIsReadonly(): void
    {
        $reflection = new \ReflectionClass(ServiceMethod::class);

        $this->assertTrue($reflection->isReadOnly());
    }
}
