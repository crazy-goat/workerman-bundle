<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Attribute;

use CrazyGoat\WorkermanBundle\Attribute\AsTask;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(AsTask::class)]
final class AsTaskTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Constructor round-trip
    // ──────────────────────────────────────────────

    public function testConstructorWithDefaultValues(): void
    {
        $attribute = new AsTask();

        $this->assertNull($attribute->name);
        $this->assertNull($attribute->schedule);
        $this->assertNull($attribute->method);
        $this->assertNull($attribute->jitter);
    }

    public function testConstructorWithAllParameters(): void
    {
        $attribute = new AsTask(
            name: 'my_task',
            schedule: '*/5 * * * *',
            method: 'execute',
            jitter: 30,
        );

        $this->assertSame('my_task', $attribute->name);
        $this->assertSame('*/5 * * * *', $attribute->schedule);
        $this->assertSame('execute', $attribute->method);
        $this->assertSame(30, $attribute->jitter);
    }

    public function testConstructorWithOnlyName(): void
    {
        $attribute = new AsTask(name: 'hourly_cleanup');

        $this->assertSame('hourly_cleanup', $attribute->name);
        $this->assertNull($attribute->schedule);
        $this->assertNull($attribute->method);
        $this->assertNull($attribute->jitter);
    }

    public function testConstructorWithNameAndSchedule(): void
    {
        $attribute = new AsTask(
            name: 'daily_report',
            schedule: '0 0 * * *',
        );

        $this->assertSame('daily_report', $attribute->name);
        $this->assertSame('0 0 * * *', $attribute->schedule);
        $this->assertNull($attribute->method);
        $this->assertNull($attribute->jitter);
    }

    public function testConstructorWithNameAndMethod(): void
    {
        $attribute = new AsTask(
            name: 'cron_task',
            method: 'handle',
        );

        $this->assertSame('cron_task', $attribute->name);
        $this->assertNull($attribute->schedule);
        $this->assertSame('handle', $attribute->method);
        $this->assertNull($attribute->jitter);
    }

    public function testConstructorWithJitterZero(): void
    {
        $attribute = new AsTask(jitter: 0);

        $this->assertNull($attribute->name);
        $this->assertNull($attribute->schedule);
        $this->assertNull($attribute->method);
        $this->assertSame(0, $attribute->jitter);
    }

    // ──────────────────────────────────────────────
    // Type safety
    // ──────────────────────────────────────────────

    public function testJitterParameterIsNullableInt(): void
    {
        $reflection = new \ReflectionClass(AsTask::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'AsTask must have a constructor');

        $jitterParam = null;
        foreach ($constructor->getParameters() as $param) {
            if ($param->getName() === 'jitter') {
                $jitterParam = $param;
                break;
            }
        }

        $this->assertNotNull($jitterParam, 'jitter parameter must exist');

        $type = $jitterParam->getType();
        $this->assertNotNull($type, 'jitter must have a type hint');
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('int', $type->getName());
        $this->assertTrue($type->allowsNull());
    }

    // ──────────────────────────────────────────────
    // Reflection target
    // ──────────────────────────────────────────────

    public function testAttributeCanBeAppliedToClass(): void
    {
        $reflection = new \ReflectionClass(AsTask::class);
        $attributeInstance = $reflection->getAttributes(\Attribute::class)[0];
        $targets = $attributeInstance->getArguments()[0];

        $this->assertSame(\Attribute::TARGET_CLASS, $targets & \Attribute::TARGET_CLASS, 'AsTask must allow TARGET_CLASS');
    }

    // ──────────────────────────────────────────────
    // Reflecting off a fixture class
    // ──────────────────────────────────────────────

    public function testReflectAttributeReadsConstructorArguments(): void
    {
        $reflection = new \ReflectionClass(FixtureTask::class);
        $attributes = $reflection->getAttributes(AsTask::class);

        $this->assertCount(1, $attributes, 'FixtureTask should have one AsTask attribute');

        $attribute = $attributes[0]->newInstance();
        $this->assertSame('fixture_task', $attribute->name);
        $this->assertSame('0 */2 * * *', $attribute->schedule);
        $this->assertSame('process', $attribute->method);
        $this->assertSame(15, $attribute->jitter);
    }

    public function testReflectAttributeOnClassWithMinimalArguments(): void
    {
        $reflection = new \ReflectionClass(FixtureMinimalTask::class);
        $attributes = $reflection->getAttributes(AsTask::class);

        $this->assertCount(1, $attributes);

        $attribute = $attributes[0]->newInstance();
        $this->assertSame('minimal_task', $attribute->name);
        $this->assertNull($attribute->schedule);
        $this->assertNull($attribute->method);
        $this->assertNull($attribute->jitter);
    }

    public function testReflectAttributeOnClassWithOnlySchedule(): void
    {
        $reflection = new \ReflectionClass(FixtureScheduledTask::class);
        $attributes = $reflection->getAttributes(AsTask::class);

        $this->assertCount(1, $attributes);

        $attribute = $attributes[0]->newInstance();
        $this->assertNull($attribute->name);
        $this->assertSame('@daily', $attribute->schedule);
        $this->assertNull($attribute->method);
        $this->assertNull($attribute->jitter);
    }

    // ──────────────────────────────────────────────
    // Regression protection
    // ──────────────────────────────────────────────

    public function testConstructorParameterCountIsStable(): void
    {
        $reflection = new \ReflectionClass(AsTask::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'AsTask must have a constructor');

        $this->assertCount(4, $constructor->getParameters(), 'AsTask constructor must have exactly 4 parameters');
    }

    /** @depends testConstructorParameterCountIsStable */
    public function testConstructorParameterNamesAreStable(): void
    {
        $reflection = new \ReflectionClass(AsTask::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        $this->assertSame(['name', 'schedule', 'method', 'jitter'], $names);
    }

    public function testConstructorParameterTypesAreStable(): void
    {
        $reflection = new \ReflectionClass(AsTask::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();

        $expectedTypes = [
            'name' => ['type' => 'string', 'allowsNull' => true],
            'schedule' => ['type' => 'string', 'allowsNull' => true],
            'method' => ['type' => 'string', 'allowsNull' => true],
            'jitter' => ['type' => 'int', 'allowsNull' => true],
        ];

        foreach ($params as $param) {
            $name = $param->getName();
            $this->assertArrayHasKey($name, $expectedTypes, "Unexpected parameter: {$name}");

            $type = $param->getType();
            $this->assertNotNull($type, "Parameter {$name} must have a type hint");
            $this->assertInstanceOf(\ReflectionNamedType::class, $type, "Parameter {$name} type must be a named type");
            $this->assertSame($expectedTypes[$name]['type'], $type->getName(), "Parameter {$name} type mismatch");
            $this->assertSame($expectedTypes[$name]['allowsNull'], $type->allowsNull(), "Parameter {$name} nullable mismatch");
        }
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(AsTask::class);

        $this->assertTrue($reflection->isFinal(), 'AsTask must be final');
    }
}

// ──────────────────────────────────────────────
// Fixture classes
// ──────────────────────────────────────────────

#[AsTask(name: 'fixture_task', schedule: '0 */2 * * *', method: 'process', jitter: 15)]
final readonly class FixtureTask
{
    public function process(): void
    {
        echo 'processing';
    }
}

#[AsTask(name: 'minimal_task')]
final readonly class FixtureMinimalTask
{
    public function execute(): void
    {
        echo 'executing';
    }
}

#[AsTask(schedule: '@daily')]
final readonly class FixtureScheduledTask
{
    public function run(): void
    {
        echo 'running';
    }
}
