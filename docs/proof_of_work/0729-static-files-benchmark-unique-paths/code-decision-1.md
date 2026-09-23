## Round 1 — #729

Added a `benchThrashingUniquePaths()` scenario that cycles through 20,000 pre-built requests for distinct missing CSS paths using the same middleware instance. At 1,000 revolutions, it exceeds the middleware's 1,024-entry cache capacity and exercises negative-cache insertion/eviction rather than repeated cache hits. Requests are created in `init()` so request construction is not included in the measured invocation.

Rejected creating files for every unique URL: missing paths exercise the same high-cardinality cache behavior without thousands of filesystem writes or a large fixture tree. The benchmark is advisory and has no assertions, matching existing benchmark conventions.
