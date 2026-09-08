<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Test;

use CrazyGoat\WorkermanBundle\Http\MiddlewareDispatcher;
use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use PHPUnit\Framework\TestCase;
use Workerman\Protocols\Http\Response as WorkermanResponse;

/**
 * Pins the contract of {@see MiddlewareDispatcher} — the shipped index-based
 * dispatcher that replaced the nested-closure pipeline composition (#563).
 *
 * These tests exercise the dispatcher directly (not via HttpRequestHandler)
 * so that flipping the index walk, removing the try/finally restore, or
 * reverting to a closure chain would fail a test here.
 */
final class MiddlewareDispatcherTest extends TestCase
{
    /**
     * Middlewares must execute in registration order: the first registered
     * middleware is the outermost (runs first), the last registered is the
     * innermost (runs last, closest to the controller).
     */
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

    /**
     * If the middleware array were accidentally reversed (as the old
     * array_reverse composition did), the order would be wrong. This test
     * documents what "wrong" looks like so the correct order test is
     * meaningful — and guards against a future revert to reverse composition.
     */
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

    /**
     * The controller is the innermost layer — it runs after all middlewares.
     */
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

    /**
     * With zero middlewares the dispatcher delegates directly to the
     * controller — no error, no skipped layer.
     */
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

    /**
     * A middleware that short-circuits (returns without calling $next) must
     * prevent all subsequent middlewares and the controller from running.
     */
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

    /**
     * The try/finally index restore allows a middleware to call $next more
     * than once — each call dispatches the remaining chain from the same
     * position. Deleting the restore would cause the second $next call to
     * skip directly to the controller (index permanently advanced).
     *
     * This test pins the re-entrant double-$next parity semantics claimed
     * in the MiddlewareDispatcher class docblock.
     */
    public function testDoubleNextCallDispatchesRemainingChainTwice(): void
    {
        /** @var list<string> $order */
        $order = [];

        // This middleware calls $next twice, recording the inner
        // middleware's marker each time.
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

        // The inner middleware records each time it is entered.
        $inner = $this->trackingMiddleware('inner', $order);

        $controller = function (Request $r) use (&$order): WorkermanResponse {
            $order[] = 'controller';

            return new WorkermanResponse(200, [], 'ok');
        };

        $dispatcher = new MiddlewareDispatcher([$doubleCaller, $inner], $controller);
        $dispatcher(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        // The inner middleware and controller must each run twice — once
        // per $next call. Without the try/finally restore, the second
        // $next call would skip 'inner' and hit 'controller' only once.
        self::assertSame(
            ['double-start', 'inner', 'controller', 'between', 'inner', 'controller', 'double-end'],
            $order,
            'Each $next call must dispatch the full remaining chain from the same position (try/finally index restore)',
        );
    }

    /**
     * A fresh dispatcher instance must start with index 0 — no state leaks
     * from a previous dispatch. (The handler creates a new dispatcher per
     * request; this pins that the constructor resets the index.)
     */
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

        // First dispatch
        $d1 = new MiddlewareDispatcher([$mw], $controller);
        $d1(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        // Second dispatch with a fresh instance
        $d2 = new MiddlewareDispatcher([$mw], $controller);
        $d2(new Request("GET / HTTP/1.1\r\nHost: t\r\n\r\n"));

        self::assertSame(2, $calls, 'Each fresh dispatcher must reach the controller independently');
    }

    /**
     * Build a tracking middleware that records its name in the shared
     * list when entered, then delegates to $next.
     *
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
