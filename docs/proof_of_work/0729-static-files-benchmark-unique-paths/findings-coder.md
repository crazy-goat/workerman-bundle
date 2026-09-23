## #729 implementation findings

- Biggest implementation obstacle: ensure the measured requests are unique while excluding request parsing/construction from each timed operation. The benchmark prebuilds 20,000 `Request` instances during `init()` and cycles through them during the subject.
- Existing benchmark lifecycle constructs a fresh middleware per benchmark method; the new subject therefore intentionally uses one instance while cycling past the 1,024-path cache bound, reaching both misses and eviction.
- No additional out-of-scope bugs identified during this change.
