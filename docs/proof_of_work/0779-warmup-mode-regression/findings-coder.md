## #779 implementation findings

- `ConfigLoader::warmUp()` pins `umask(0077)` around the cache write, which is what keeps `cache/<env>/workerman` at 0700, but nothing asserted it after a real warm-up. The new test forces a permissive umask so the pin is the only thing preventing 0777.
- Biggest obstacle: choosing the assertion. Exact modes are brittle (parent dir created in `setUp` under its own umask); the world-writable bit is the actual policy (DEC-006/DEC-016) and is stable cross-platform.
- The three root-only cases in this class remain environment-dependent (covered by the #760 CI leg); this new test is unprivileged and deterministic.
- No additional out-of-scope bugs identified.
