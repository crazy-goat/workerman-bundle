<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Reboot\Strategy\AlwaysRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\ExceptionRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy;
use CrazyGoat\WorkermanBundle\Reboot\Strategy\StackRebootStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests for Reboot Strategies.
 *
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\AlwaysRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\ExceptionRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\StackRebootStrategy
 */
final class RebootStrategyTest extends TestCase
{
    // AlwaysRebootStrategy Tests

    public function testAlwaysRebootStrategyAlwaysReturnsTrue(): void
    {
        $strategy = new AlwaysRebootStrategy();

        $this->assertTrue($strategy->shouldReboot());
        $this->assertTrue($strategy->shouldReboot());
        $this->assertTrue($strategy->shouldReboot());
    }

    // MaxJobsRebootStrategy Tests

    public function testMaxJobsRebootStrategyReturnsFalseUntilLimit(): void
    {
        $strategy = new MaxJobsRebootStrategy(3, 0);

        $this->assertFalse($strategy->shouldReboot()); // Job 1
        $this->assertFalse($strategy->shouldReboot()); // Job 2
        $this->assertFalse($strategy->shouldReboot()); // Job 3
        $this->assertTrue($strategy->shouldReboot());  // Job 4 - exceeds limit
    }

    public function testMaxJobsRebootStrategyWithDispersion(): void
    {
        // With 50% dispersion and maxJobs=100, actual limit will be between 50-100
        $strategy = new MaxJobsRebootStrategy(100, 50);

        // We can't predict exact limit due to randomness, but we can verify
        // it doesn't reboot immediately and eventually does
        $rebootCount = 0;
        for ($i = 0; $i < 150; ++$i) {
            if ($strategy->shouldReboot()) {
                ++$rebootCount;
                break;
            }
        }

        $this->assertSame(1, $rebootCount, 'Should reboot at most once');
    }

    public function testMaxJobsRebootStrategyWithZeroDispersion(): void
    {
        $strategy = new MaxJobsRebootStrategy(5, 0);

        // With 0% dispersion, maxJobs should be exactly 5
        // random_int(5, 5) always returns 5
        $rebootAt = null;
        for ($i = 1; $i <= 10; ++$i) {
            if ($strategy->shouldReboot()) {
                $rebootAt = $i;
                break;
            }
        }

        // Should reboot at job 6 (after 5 jobs processed)
        $this->assertSame(6, $rebootAt, 'Should reboot after exactly 5 jobs with 0% dispersion');
    }

    // ExceptionRebootStrategy Tests

    public function testExceptionRebootStrategyReturnsFalseInitially(): void
    {
        $strategy = new ExceptionRebootStrategy();

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testExceptionRebootStrategyReturnsTrueAfterException(): void
    {
        $strategy = new ExceptionRebootStrategy();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        $strategy->onException($event);

        $this->assertTrue($strategy->shouldReboot());
    }

    public function testExceptionRebootStrategyIgnoresSubRequests(): void
    {
        $strategy = new ExceptionRebootStrategy();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::SUB_REQUEST, $exception);
        $strategy->onException($event);

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testExceptionRebootStrategyIgnoresAllowedExceptions(): void
    {
        $strategy = new ExceptionRebootStrategy([\InvalidArgumentException::class]);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \InvalidArgumentException('Allowed exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        $strategy->onException($event);

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testExceptionRebootStrategyRebootsOnNonAllowedExceptions(): void
    {
        $strategy = new ExceptionRebootStrategy([\InvalidArgumentException::class]);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Not allowed exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        $strategy->onException($event);

        $this->assertTrue($strategy->shouldReboot());
    }

    // MemoryRebootStrategy Tests

    public function testMemoryRebootStrategyReturnsFalseWhenUnderLimit(): void
    {
        $strategy = new MemoryRebootStrategy(PHP_INT_MAX, null);

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testMemoryRebootStrategyReturnsTrueWhenOverLimit(): void
    {
        // Set limit to 1 byte - we're definitely using more than that
        $strategy = new MemoryRebootStrategy(1, null);

        $this->assertTrue($strategy->shouldReboot());
    }

    public function testMemoryRebootStrategyTriggersGcWhenOverGcLimit(): void
    {
        // This test verifies the GC is triggered when memory exceeds gcLimit
        // We can't easily test that GC was actually called, but we can verify
        // the method doesn't throw and returns correct value
        $strategy = new MemoryRebootStrategy(PHP_INT_MAX, 1);

        // Should trigger GC (since we're using more than 1 byte)
        // but return false since we're under the main limit
        $this->assertFalse($strategy->shouldReboot());
    }

    // StackRebootStrategy Tests

    public function testStackRebootStrategyReturnsFalseWithEmptyStack(): void
    {
        $strategy = new StackRebootStrategy([]);

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testStackRebootStrategyReturnsTrueIfAnyStrategyReturnsTrue(): void
    {
        $alwaysReboot = new AlwaysRebootStrategy();
        $strategy = new StackRebootStrategy([$alwaysReboot]);

        $this->assertTrue($strategy->shouldReboot());
    }

    public function testStackRebootStrategyReturnsFalseIfAllStrategiesReturnFalse(): void
    {
        // Create a mock strategy that always returns false
        $neverReboot = new class implements \CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface {
            public function shouldReboot(): bool
            {
                return false;
            }
        };

        $strategy = new StackRebootStrategy([$neverReboot, $neverReboot]);

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testStackRebootStrategyShortCircuitsOnFirstTrue(): void
    {
        $alwaysReboot = new AlwaysRebootStrategy();
        $callCount = 0;

        $countingStrategy = new class($callCount) implements \CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface {
            private int $callCount = 0;

            public function shouldReboot(): bool
            {
                ++$this->callCount;
                return false;
            }

            public function getCallCount(): int
            {
                return $this->callCount;
            }
        };

        $strategy = new StackRebootStrategy([$alwaysReboot, $countingStrategy]);
        $strategy->shouldReboot();

        // The second strategy should never be called because first returns true
        $this->assertSame(0, $countingStrategy->getCallCount());
    }
}
