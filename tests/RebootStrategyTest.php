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
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\AlwaysRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\ExceptionRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\MaxJobsRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\MemoryRebootStrategy
 * @covers \CrazyGoat\WorkermanBundle\Reboot\Strategy\StackRebootStrategy
 */
final class RebootStrategyTest extends TestCase
{
    public function testAlwaysRebootStrategyAlwaysReturnsTrue(): void
    {
        $strategy = new AlwaysRebootStrategy();

        $this->assertTrue($strategy->shouldReboot());
        $this->assertTrue($strategy->shouldReboot());
        $this->assertTrue($strategy->shouldReboot());
    }

    public function testMaxJobsRebootStrategyReturnsFalseUntilLimit(): void
    {
        $strategy = new MaxJobsRebootStrategy(3, 0);

        $this->assertFalse($strategy->shouldReboot());
        $this->assertFalse($strategy->shouldReboot());
        $this->assertFalse($strategy->shouldReboot());
        $this->assertTrue($strategy->shouldReboot());
    }

    public function testMaxJobsRebootStrategyWithDispersion(): void
    {
        $strategy = new MaxJobsRebootStrategy(100, 50);

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

        $rebootAt = null;
        for ($i = 1; $i <= 10; ++$i) {
            if ($strategy->shouldReboot()) {
                $rebootAt = $i;
                break;
            }
        }

        $this->assertSame(6, $rebootAt, 'Should reboot after exactly 5 jobs with 0% dispersion');
    }

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

    public function testExceptionRebootStrategyStateIsResetAfterShouldReboot(): void
    {
        $strategy = new ExceptionRebootStrategy();
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \RuntimeException('Test exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        $strategy->onException($event);

        $this->assertTrue($strategy->shouldReboot());
        $this->assertFalse($strategy->shouldReboot());
        $this->assertFalse($strategy->shouldReboot());
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

    public function testExceptionRebootStrategyIgnoresSubclassOfAllowedException(): void
    {
        $strategy = new ExceptionRebootStrategy([\RuntimeException::class]);
        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = new Request();
        $exception = new \UnexpectedValueException('Subclass of allowed exception');

        $event = new ExceptionEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $exception);
        $strategy->onException($event);

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testMemoryRebootStrategyReturnsFalseWhenUnderLimit(): void
    {
        $strategy = new MemoryRebootStrategy(PHP_INT_MAX, null);

        $this->assertFalse($strategy->shouldReboot());
    }

    public function testMemoryRebootStrategyReturnsTrueWhenOverLimit(): void
    {
        $strategy = new MemoryRebootStrategy(1, null);

        $this->assertTrue($strategy->shouldReboot());
    }

    public function testMemoryRebootStrategyTriggersGcWhenOverGcLimit(): void
    {
        $strategy = new MemoryRebootStrategy(PHP_INT_MAX, 1);

        $this->assertFalse($strategy->shouldReboot());
    }

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

        $countingStrategy = new class implements \CrazyGoat\WorkermanBundle\Reboot\Strategy\RebootStrategyInterface {
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

        $this->assertSame(0, $countingStrategy->getCallCount());
    }
}
