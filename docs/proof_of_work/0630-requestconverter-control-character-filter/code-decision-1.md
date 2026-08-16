# Code decision 1 — header-value control-character filter: strcspn replaces preg_match (#630)

## What was done

1. **Micro-benchmark added** to `benchmarks/RequestConverterBench.php`:
   three param-provider-driven subjects (`benchFilterRegex`, `benchFilterStrpbrk`,
   `benchFilterStrcspn`) over seven representative values (short accepted, TAB
   accepted, short rejected, long accepted, long rejected with the bad byte
   last, UTF-8 accepted, UTF-8 rejected with trailing NUL).
2. **Filter swapped** in `src/DTO/RequestConverter.php`
   (`buildServerHeaders()`, line ~205): `preg_match('/[\x00-\x08\x0A-\x1F\x7F]/')`
   → `strcspn($stringValue, self::HEADER_VALUE_CONTROL_CHARS) < strlen($stringValue)`,
   with a class constant listing every rejected byte explicitly.
3. **Regression test hardened** (`tests/RequestConverterTest.php`,
   `testHeaderControlCharacterBoundaryIsRejectedExceptTab`): the loop now
   sweeps all 256 byte values (previously 0x00–0x1F + 0x7F only), asserting
   rejection for {0–8, 10–31, 127}, round-trip acceptance for TAB, printable
   ASCII, and 0x80–0xFF (RFC 7230 obs-text, valid in field values).
4. **Numbers recorded** in `benchmarks/RequestConverterBench.md` (the repo's
   committed benchmark report) and below, per the issue's acceptance criteria.

The `MalformedRequestException` message and its `addcslashes($value,
"\x00..\x1F\x7F")` escaping are untouched — byte-identical output.

## Benchmark verdict

Environment: PHP 8.5.9, PHPBench 1.7.0, opcache off, xdebug off, PCRE2 10.47
**with JIT enabled** (pcre.jit=On), 1000 revs × 5 its × 1 warmup. Mode (µs per
call), median of three runs; sub-0.1 µs rows are within noise:

| Value | preg_match | strpbrk | strcspn |
| --- | ---: | ---: | ---: |
| shortAccepted (22 B) | 0.063 | 0.061 | 0.067 |
| tabAccepted (9 B) | 0.061 | 0.057 | 0.063 |
| shortRejected (12 B) | 0.054 | 0.065 | 0.061 |
| longAccepted (~760 B) | 0.576 | 0.448 | **0.455** |
| longRejectedLate (~501 B) | 0.392 | 0.285 | **0.282** |
| utf8Accepted (~940 B) | 0.235 | 0.200 | **0.209** |
| utf8Rejected (~200 B) | 0.136 | 0.120 | **0.122** |

**Verdict: strcspn wins.** 10–28% faster than the regex on every non-trivial
value, never slower outside noise on tiny ones, and its per-call cost is
stable (lowest rstdev of the three on long values). Because the regex was
measured with PCRE2 JIT **on**, the strcspn win is the conservative case —
without JIT the regex would be slower still.

**strpbrk rejected**: it returns the *remainder substring* from the match
point, so its long-value cost swings 30% between runs (0.442–0.634 µs on
`longAccepted`, vs strcspn stable at ~0.455 µs) and it offers no consistent
edge over strcspn. It also has the wrong API shape for a boolean predicate.

**End-to-end**: the filter is ~0.1–0.6 µs of a 4.3–8.8 µs conversion, so the
whole-request numbers move within run-to-run noise:
`benchSimpleRequest` 4.328→4.419 µs (+2.1%, noise), `benchHeaderHeavyRequest`
8.200→8.108 µs (−1.1%), `benchMultipartRequest` 8.827→8.700 µs (−1.4%),
`benchResetHeaders` 0.193→0.191 µs. The swap was kept because the win at the
operation level is real, consistent, and free of behavior risk.

## Behavioural equivalence (why the swap is safe)

- `strcspn($v, $mask) < strlen($v)` is `true` iff the first `strlen($v)`
  characters of `$v` contain no mask byte shorter than the whole value —
  i.e. iff a mask byte occurs in `$v` (empty input: 0 < 0 = false). Exhaustive
  sweep of all 256 byte values in isolation confirmed
  `preg_match === strpbrk !== false === strcspn < strlen === expected` before
  the swap; the in-repo table test now pins the same property through the full
  `toSymfonyRequest()` path.
- The mask is the exact byte complement set: rejects {0–8, 10–31, 127},
  accepts TAB (0x09) and everything else, including 0x80–0xFF.
- `strcspn` is byte-based and locale-independent (`php_charmask` 256-entry
  table, explicit lengths — binary-safe for NUL in both haystack and mask).

## What I was unsure about

- **Whether the micro-win justifies the swap at all.** The operation-level
  win is real and reproducible; the whole-request effect is below the noise
  floor. I kept the swap because it removes the regex engine from a
  security-relevant per-header hot path at zero behavioral cost, and the
  issue's own framing ("measurably cheaper than invoking the regex engine —
  worth benchmarking") reads as wanting the swap whenever the micro-numbers
  support it. If the reviewer prefers minimal diff churn over a noise-level
  e2e win, reverting to the regex is a two-line change; the benchmark
  scaffolding stays useful either way.
- **Benchmark naming**: the aggregate report does not print the parameter-set
  name next to each row (the `params` expression column renders `null`), so
  rows must be correlated by provider order — the progress output does show
  `# setname`. My benchmark report table therefore relies on provider order;
  the MD documents the mapping.
