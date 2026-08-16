# Findings — coder (#630)

## Obstacles / surprises

- **phpbench 1.x param-provider injection is a single `array` argument.**
  `@ParamProviders` sets are passed to the bench method as one `array $params`
  argument, not as named properties or named method arguments. I first bound
  the value to a typed property (`must not be accessed before initialization`,
  two separate fatal runs) and then declared `string $value` as the argument
  (`Argument #1 ($value) must be of type string, array given`). Both failure
  modes are silent about the correct shape — the fix is `array $params` +
  `$params['value']`. `benchmarks/RequestConverterBench.php:104-121`.
- **The `aggregate` report loses the parameter-set name.** Even a custom
  expression report with `"cols": [..., "params", ...]` renders `null` for
  every row, so parameterized subjects can only be mapped to sets by provider
  order (the progress output shows `# shortAccepted` etc.). The benchmark MD
  documents the order, but a `--dump`-style report with per-set rows would be
  less fragile. Worth remembering for future parameterized benches.
- **PHP 8.5 + PHPBench note:** the `--filter="benchFilter(longAccepted|...)"`
  selector matched zero subjects; only `--filter="benchFilter*"` worked.
  Minor, but it cost a wasted run.
- **The existing boundary test skipped the 0x80–0xFF range.** 
  `testHeaderControlCharacterBoundaryIsRejectedExceptTab` looped only
  0x00–0x1F plus 0x7F, so no test proved that bytes ≥ 0x80 (RFC 7230
  obs-text — legal in field values, commonly seen in UTF-8 headers) survive
  the filter through the full conversion. Extended to a 256-value table test.

## Out-of-scope observations

- **URI and header filters disagree on TAB, deliberately but asymmetrically.**
  `src/DTO/RequestConverter.php:50` (`validateUri`, `/[\x00-\x1F\x7F]/`)
  rejects TAB (0x09) in the request URI, while header values (line ~204)
  accept it. This is per-spec (RFC 3986 forbids TAB in URIs; RFC 7230 allows
  HTAB in field values) and #630's charset analysis depends on the two
  staying separate. If anyone ever merges these into a shared helper, the TAB
  difference must be preserved — do not unify by taking the stricter set.
- **Duplicate header values are pre-joined strings, so the `(string) $value`
  cast is safe.** Verified in
  `vendor/workerman/workerman/src/Protocols/Http/Request.php:517-524`:
  `parseHeaders()` joins repeated keys with a bare `,` before the converter
  ever sees them, so `header()` never returns an array here (consistent with
  FAQ-025). No `Array to string conversion` hazard.
- **PCRE2 JIT is on in the benchmark environment** (pcre.jit=On, PCRE2 10.47,
  ARM-64): the regex numbers include JIT'd matching, so the measured strcspn
  win is the conservative case. In a JIT-less build the gap would be wider.
- **`composer bench` (`vendor/bin/phpbench run --report=aggregate`) now takes
  longer** — the micro-benchmark adds 21 parameterized subjects (7 sets × 3
  methods); the whole `benchmarks/` suite runtime roughly doubles. Still
  seconds, not minutes; no action needed.
