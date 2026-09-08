# Findings — Review rounds, issue #563

## Round 1

| id | file:line | what is wrong | severity | status |
|----|-----------|---------------|----------|--------|
| F-1 | tests/HttpRequestHandlerTest.php:1576-1579 | `testHandlerIsSafeForReuseAcrossTwoDifferentRequests` builds `$kernel404`/`$controller404`/`$handler404` and its comments claim a 404 path is exercised, but the second request is dispatched through `$this->handler` and asserts 200; the 404 handler is dead code and the assertion messages misdescribe the test. | low | open |
| F-2 | src/Http/MiddlewareDispatcher.php:58-71 + tests/MiddlewarePipelineTest.php:122-133 | Middleware execution order (outermost = first registered) is not pinned against the shipped dispatcher: `MiddlewarePipelineTest` re-implements the old nested-closure composition in its helper, and the only handler-level "order" test (`testInvokeMiddlewaresAppliedInReverseOrder`, tests/HttpRequestHandlerTest.php:428) asserts header presence, not sequence. Flipping the index walk would fail no test. Needs a dedicated `MiddlewareDispatcherTest` or the pipeline helper re-based on `MiddlewareDispatcher`. | medium | open |
| F-3 | src/Http/MiddlewareDispatcher.php:64-70 | The try/finally index restore (re-entrant double-`$next` parity with the old composition, claimed in the class docblock and code-decision-1) is unpinned: deleting it passes the whole suite. | low | open |
| F-4 | tests/HttpRequestHandlerTest.php:428; src/Middleware/MiddlewareInterface.php:22-24 | Doc/test-name drift in the blast radius (carried from findings-coder.md): "ReverseOrder" test asserts no order ("reverse" referred to the removed `array_reverse`); `MiddlewareInterface`/`HttpRequestHandler` docblocks present `responseSentDirectly` as a middleware feature though only `StreamedResponseStrategy` sets it. Pre-existing, not introduced by this diff. | nit | open |

## Round 2

| id | file:line | what is wrong | severity | status |
|----|-----------|---------------|----------|--------|
| F-1 | tests/HttpRequestHandlerTest.php:1576-1579 | Dead `$handler404` construction and misleading 404 narrative in the reuse test. | low | **fixed** in 1602cbc (dead lines removed, comment corrected; statelessness assertion intact) |
| F-2 | src/Http/MiddlewareDispatcher.php:58-71 + tests/MiddlewarePipelineTest.php:122-133 | Execution order not pinned against the shipped dispatcher. | medium | **fixed** in 1602cbc (`MiddlewareDispatcherTest`: registration-order, reversed-order contrast, controller-innermost). Residual: pipeline-test helper still duplicates the old composition — carried as F-6 nit |
| F-3 | src/Http/MiddlewareDispatcher.php:64-70 | try/finally re-entrancy restore unpinned. | low | **fixed** in 1602cbc (`testDoubleNextCallDispatchesRemainingChainTwice`; mutation hand-traced — without the restore the second `$next` skips `inner`, assertion fails) |
| F-4 | tests/HttpRequestHandlerTest.php:428; src/Middleware/MiddlewareInterface.php:22-24 | Doc/test-name drift (`ReverseOrder` name; `responseSentDirectly` attributed to middleware). | nit | **fixed** in 1602cbc (test renamed, messages corrected, docblocks now credit StreamedResponseStrategy — verified by grep: only `StreamedResponseStrategy.php:92,113` sets the flag) |
| F-5 | tests/MiddlewareDispatcherTest.php:130 | `new readonly class` (anonymous readonly class) is PHP 8.3+ syntax — a parse error on PHP 8.2, while composer.json allows `^8.2` and `.github/workflows/tests.yaml:29,113` run 8.2 legs. Local tooling (PHP 8.5.10) cannot see it; the 8.2 CI leg would fail. Fix: drop `readonly` (the class has no properties). | high | open |
| F-6 | tests/MiddlewarePipelineTest.php:122-133 | Helper still re-implements the old nested-closure composition; harmless now that the shipped dispatcher is pinned directly, but should be re-based or commented as a contract-only test. | nit | open (pre-existing; deferred by coder with a note in findings-coder.md) |
