<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test\Attribute;

use CrazyGoat\WorkermanBundle\Attribute\AsProcess;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\CoversClass(AsProcess::class)]
final class AsProcessTest extends TestCase
{
    // ──────────────────────────────────────────────
    // Constructor round-trip
    // ──────────────────────────────────────────────

    public function testConstructorWithDefaultValues(): void
    {
        $attribute = new AsProcess();

        $this->assertNull($attribute->name);
        $this->assertNull($attribute->processes);
        $this->assertNull($attribute->method);
    }

    public function testConstructorWithAllParameters(): void
    {
        $attribute = new AsProcess(
            name: 'my_process',
            processes: 4,
            method: 'execute',
        );

        $this->assertSame('my_process', $attribute->name);
        $this->assertSame(4, $attribute->processes);
        $this->assertSame('execute', $attribute->method);
    }

    public function testConstructorWithOnlyName(): void
    {
        $attribute = new AsProcess(name: 'worker');

        $this->assertSame('worker', $attribute->name);
        $this->assertNull($attribute->processes);
        $this->assertNull($attribute->method);
    }

    public function testConstructorWithOnlyProcesses(): void
    {
        $attribute = new AsProcess(processes: 2);

        $this->assertNull($attribute->name);
        $this->assertSame(2, $attribute->processes);
        $this->assertNull($attribute->method);
    }

    public function testConstructorProcessesParameterIsNullableInt(): void
    {
        $reflection = new \ReflectionClass(AsProcess::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'AsProcess must have a constructor');

        $params = $constructor->getParameters();
        $this->assertCount(3, $params);

        // Second parameter (index 1) is $processes
        $processesParam = $params[1];
        $this->assertSame('processes', $processesParam->getName());

        $type = $processesParam->getType();
        $this->assertNotNull($type, 'processes parameter must have a type hint');
        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('int', $type->getName());
        $this->assertTrue($type->allowsNull());
    }

    // ──────────────────────────────────────────────
    // Reflection target
    // ──────────────────────────────────────────────

    public function testAttributeCanBeAppliedToClass(): void
    {
        $reflection = new \ReflectionClass(AsProcess::class);
        $attributeInstance = $reflection->getAttributes(\Attribute::class)[0];
        $targets = $attributeInstance->getArguments()[0];

        $this->assertSame(\Attribute::TARGET_CLASS, $targets & \Attribute::TARGET_CLASS, 'AsProcess must allow TARGET_CLASS');
    }

    // ──────────────────────────────────────────────
    // Reflecting off a fixture class
    // ──────────────────────────────────────────────

    public function testReflectAttributeReadsConstructorArguments(): void
    {
        $reflection = new \ReflectionClass(FixtureProcess::class);
        $attributes = $reflection->getAttributes(AsProcess::class);

        $this->assertCount(1, $attributes, 'FixtureProcess should have one AsProcess attribute');

        $attribute = $attributes[0]->newInstance();
        $this->assertSame('fixture_process', $attribute->name);
        $this->assertSame(3, $attribute->processes);
        $this->assertSame('run', $attribute->method);
    }

    public function testReflectAttributeOnClassWithoutArguments(): void
    {
        $reflection = new \ReflectionClass(FixtureSimpleProcess::class);
        $attributes = $reflection->getAttributes(AsProcess::class);

        $this->assertCount(1, $attributes);

        $attribute = $attributes[0]->newInstance();
        $this->assertNull($attribute->name);
        $this->assertNull($attribute->processes);
        $this->assertNull($attribute->method);
    }

    // ──────────────────────────────────────────────
    // Regression protection
    // ──────────────────────────────────────────────

    public function testConstructorParameterCountIsStable(): void
    {
        $reflection = new \ReflectionClass(AsProcess::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor, 'AsProcess must have a constructor');

        $this->assertCount(3, $constructor->getParameters(), 'AsProcess constructor must have exactly 3 parameters');
    }

    /** @depends testConstructorParameterCountIsStable */
    public function testConstructorParameterNamesAreStable(): void
    {
        $reflection = new \ReflectionClass(AsProcess::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $names = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters(),
        );

        $this->assertSame(['name', 'processes', 'method'], $names);
    }

    public function testConstructorParameterTypesAreStable(): void
    {
        $reflection = new \ReflectionClass(AsProcess::class);
        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);

        $params = $constructor->getParameters();

        $expectedTypes = [
            'name' => ['type' => 'string', 'allowsNull' => true],
            'processes' => ['type' => 'int', 'allowsNull' => true],
            'method' => ['type' => 'string', 'allowsNull' => true],
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
        $reflection = new \ReflectionClass(AsProcess::class);

        $this->assertTrue($reflection->isFinal(), 'AsProcess must be final');
    }
}

// ──────────────────────────────────────────────
// Fixture classes
// ──────────────────────────────────────────────

#[AsProcess(name: 'fixture_process', processes: 3, method: 'run')]
final readonly class FixtureProcess
{
    public function run(): void
    {
        echo 'running';
    }
}

#[AsProcess]
final readonly class FixtureSimpleProcess
{
    public function execute(): void
    {
        echo 'executing';
    }
}
