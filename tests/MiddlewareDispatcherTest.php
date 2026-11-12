<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\MiddlewareDispatcher;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response as WorkermanResponse;

final class MiddlewareDispatcherTest extends TestCase
{
    public function testMiddlewaresExecuteInRegistrationOrder(): void
    {
        /** @var list<string> $order */
        $order = [];

        $mwA = $this->trackingMiddleware('A', $order);
        $mwB = $this->trackingMiddleware('B', $order);
        $mwC = $this->trackingMiddleware('C', $order);

        $controller = fn(Request $r): WorkermanResponse => new WorkermanResponse(200, [], 'ok');

        $dispatcher = new MiddlewareDispatcher([$mwA, $mwB, $mwC], $controller);
        $dispatcher(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertSame(['A', 'B', 'C'], $order, 'First registered must execute first (outermost)');
    }

    public function testReversedRegistrationOrderProducesDifferentSequence(): void
    {
        /** @var list<string> $orderCorrect */
        $orderCorrect = [];
        /** @var list<string> $orderReversed */
        $orderReversed = [];

        $mwA = $this->trackingMiddleware('A', $orderCorrect);
        $mwB = $this->trackingMiddleware('B', $orderCorrect);
        $mwC = $this->trackingMiddleware('C', $orderCorrect);

        $controller = fn(Request $r): WorkermanResponse => new WorkermanResponse(200, [], 'ok');

        $d1 = new MiddlewareDispatcher([$mwA, $mwB, $mwC], $controller);
        $d1(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        $mwA2 = $this->trackingMiddleware('A', $orderReversed);
        $mwB2 = $this->trackingMiddleware('B', $orderReversed);
        $mwC2 = $this->trackingMiddleware('C', $orderReversed);

        $d2 = new MiddlewareDispatcher(array_reverse([$mwA2, $mwB2, $mwC2]), $controller);
        $d2(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertSame(['A', 'B', 'C'], $orderCorrect);
        self::assertSame(['C', 'B', 'A'], $orderReversed, 'Reversed array produces reversed execution — confirming the correct test is meaningful');
        self::assertNotSame($orderCorrect, $orderReversed);
    }

    public function testControllerRunsAfterAllMiddlewares(): void
    {
        /** @var list<string> $order */
        $order = [];

        $mw = $this->trackingMiddleware('mw', $order);
        $controller = function (Request $r) use (&$order): WorkermanResponse {
            $order[] = 'controller';

            return new WorkermanResponse(200, [], 'ok');
        };

        $dispatcher = new MiddlewareDispatcher([$mw], $controller);
        $dispatcher(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertSame(['mw', 'controller'], $order);
    }

    public function testZeroMiddlewaresDispatchesDirectlyToController(): void
    {
        $called = false;
        $controller = function (Request $r) use (&$called): WorkermanResponse {
            $called = true;

            return new WorkermanResponse(200, [], 'ok');
        };

        $dispatcher = new MiddlewareDispatcher([], $controller);
        $response = $dispatcher(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertTrue($called, 'Controller must be called when no middlewares are registered');
        self::assertSame(200, $response->getStatusCode());
    }

    public function testShortCircuitSkipsSubsequentMiddlewaresAndController(): void
    {
        /** @var list<string> $order */
        $order = [];

        $before = $this->trackingMiddleware('before', $order);
        $shortCircuit = new class implements MiddlewareInterface {
            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                return new WorkermanResponse(200, ['X-Short' => '1'], 'short');
            }
        };
        $after = $this->trackingMiddleware('after', $order);

        $controller = function (Request $r) use (&$order): WorkermanResponse {
            $order[] = 'controller';

            return new WorkermanResponse(200, [], 'ok');
        };

        $dispatcher = new MiddlewareDispatcher([$before, $shortCircuit, $after], $controller);
        $response = $dispatcher(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertSame(['before'], $order, 'Only middlewares before the short-circuit should run');
        self::assertSame('short', $response->rawBody());
    }

    public function testDoubleNextCallDispatchesRemainingChainTwice(): void
    {
        /** @var list<string> $order */
        $order = [];

        $doubleCaller = new class ($order) implements MiddlewareInterface {
            /** @var list<string> */
            public array $order;

            /** @param list<string> $order */
            public function __construct(array &$order)
            {
                $this->order = &$order;
            }

            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                $this->order[] = 'double-start';
                $next($request);
                $this->order[] = 'between';
                $response = $next($request);
                $this->order[] = 'double-end';

                return $response;
            }
        };

        $inner = $this->trackingMiddleware('inner', $order);

        $controller = function (Request $r) use (&$order): WorkermanResponse {
            $order[] = 'controller';

            return new WorkermanResponse(200, [], 'ok');
        };

        $dispatcher = new MiddlewareDispatcher([$doubleCaller, $inner], $controller);
        $dispatcher(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertSame(
            ['double-start', 'inner', 'controller', 'between', 'inner', 'controller', 'double-end'],
            $order,
            'Each $next call must dispatch the full remaining chain from the same position (try/finally index restore)',
        );
    }

    public function testFreshDispatcherStartsAtZeroIndex(): void
    {
        $calls = 0;
        $controller = function (Request $r) use (&$calls): WorkermanResponse {
            ++$calls;

            return new WorkermanResponse(200, [], 'ok');
        };

        /** @var list<string> $unused */
        $unused = [];
        $mw = $this->trackingMiddleware('mw', $unused);

        $d1 = new MiddlewareDispatcher([$mw], $controller);
        $d1(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        $d2 = new MiddlewareDispatcher([$mw], $controller);
        $d2(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertSame(2, $calls, 'Each fresh dispatcher must reach the controller independently');
    }

    /**
     * @param list<string> $order
     */
    private function trackingMiddleware(string $name, array &$order): MiddlewareInterface
    {
        return new class ($name, $order) implements MiddlewareInterface {
            /** @var list<string> */
            public array $order;

            /** @param list<string> $order */
            public function __construct(
                private readonly string $name,
                array &$order,
            ) {
                $this->order = &$order;
            }

            public function __invoke(Request $request, callable $next): WorkermanResponse
            {
                $this->order[] = $this->name;

                return $next($request);
            }
        };
    }
}
