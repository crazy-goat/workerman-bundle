# Findings — Review rounds, issue #563

## Round 1

| id | file:line | what is wrong | severity | status |
|----|-----------|---------------|----------|--------|
| F-1 | tests/HttpRequestHandlerTest.php:1576-1579 | `testHandlerIsSafeForReuseAcrossTwoDifferentRequests` builds `$kernel404`/`$controller404`/`$handler404` and its comments claim a 404 path is exercised, but the second request is dispatched through `$this->handler` and asserts 200; the 404 handler is dead code and the assertion messages misdescribe the test. | low | open |
| F-2 | src/Http/MiddlewareDispatcher.php:58-71 + tests/MiddlewarePipelineTest.php:122-133 | Middleware execution order (outermost = first registered) is not pinned against the shipped dispatcher: `MiddlewarePipelineTest` re-implements the old nested-closure composition in its helper, and the only handler-level "order" test (`testInvokeMiddlewaresAppliedInReverseOrder`, tests/HttpRequestHandlerTest.php:428) asserts header presence, not sequence. Flipping the index walk would fail no test. Needs a dedicated `MiddlewareDispatcherTest` or the pipeline helper re-based on `MiddlewareDispatcher`. | medium | open |
| F-3 | src/Http/MiddlewareDispatcher.php:64-70 | The try/finally index restore (re-entrant double-`$next` parity with the old composition, claimed in the class docblock and code-decision-1) is unpinned: deleting it passes the whole suite. | low | open |
| F-4 | tests/HttpRequestHandlerTest.php:428; src/Middleware/MiddlewareInterface.php:22-24 | Doc/test-name drift in the blast radius (carried from findings-coder.md): "ReverseOrder" test asserts no order ("reverse" referred to the removed `array_reverse`); `MiddlewareInterface`/`HttpRequestHandler` docblocks present `responseSentDirectly` as a middleware feature though only `StreamedResponseStrategy` sets it. Pre-existing, not introduced by this diff. | nit | open |
