# Code decision 1 — gating the raw header re-parse (#557)

## What was done

`RequestConverter::buildServerHeaders()` used to call
`parseRawHeaderLines($rawRequest->rawHead())` unconditionally. It now calls a
new private static gate, `rawHeadMayHaveDuplicates()`, and only re-parses the
raw head when the gate cannot rule out duplicates. When the gate passes,
`$rawHeaders` is an empty array, so every `$rawHeaders[$nameLower] ?? null`
lookup in the existing loop misses and the `else` branch (Workerman's parsed
value) is taken — the duplicate-handling `match` block is untouched.

The gate has two parts:

1. **Count check** — `substr_count($rawHead, "\r\n") !== count($workermanHeaders) - $addedHeaders`
2. **Name check** — any parsed header name that differs from its `trim()`
   forces a re-parse.

`$addedHeaders` is a new counter on the bundle's `Http\Request`
(`addedHeaderCount()`), incremented by `setHeader()` when it adds a name that
was not in the raw parse, and reset by `resetHeaders()`.

## Exact count arithmetic and why it is sound

The vendored Workerman (`vendor/workerman/workerman/src/Protocols/Http/Request.php`)
computes `rawHead()` as `strstr($buffer, "\r\n\r\n", true)` — everything before
the **first** `\r\n\r\n`. Two consequences:

- `rawHead()` never ends with `\r\n` (the terminator is excluded), and it can
  never contain an empty line (an empty line would itself be `\r\n\r\n`).
- Therefore `substr_count($rawHead, "\r\n")` is **exactly** the number of
  lines after the request line — no `- 1` adjustment. The issue body suggested
  `substr_count(...) - 1`; that offset assumes a `rawHead()` that retains the
  trailing CRLF, which this Workerman version does not. Using `- 1` here would
  make every well-formed request look one line short and send 100% of traffic
  down the slow path (still correct, but the optimization would be dead).

Let N = line count, d = number of distinct keys in Workerman's `header()` map,
c = number of lines containing `:` (both parsers skip colon-less lines), and
s = N - c skipped lines. Workerman's `parseHeaders()` produces exactly one map
entry per distinct lowercased name, so d ≤ c, with d < c iff some name appears
on more than one line (a duplicate). Then:

```
count(header()) - addedHeaders = d   (see "middleware" below)
N === d  ⟺  s = 0 and d = c  ⟺  no skipped lines and no duplicate lines
```

So count equality **proves** there are no duplicates — provided the keys the
two parsers produce are the same, which is where the name check comes in.

### Edge cases

- **Trailing `\r\n\r\n` terminator** — excluded from `rawHead()` by `strstr`;
  accounted for above (no `- 1`).
- **obs-fold / whitespace-prefixed lines** — the one real divergence between
  the parsers. `parseRawHeaderLines()` trims the name
  (`strtolower(trim($name))`); Workerman does **not** trim
  (`strtolower($parts[0])`). A request like
  `"X-Fold: a\r\n X-Fold: b\r\n"` yields Workerman keys `x-fold` and
  ` x-fold` (distinct → d = 2 = N → count check alone passes), but the raw
  parser sees a duplicate `x-fold` and joins `a, b`. Skipping the re-parse
  here would silently change behavior — a false negative on a
  duplicate-detection path. The name check (`$name !== trim($name)`)
  catches this: any whitespace-padded key forces the full parse. Since
  `strtolower` does not affect whitespace bytes, `trim($name) === $name` on
  Workerman's keys exactly characterizes the lines where the two parsers agree.
- **Lines without `:` (garbage)** — counted in N but not in d, so N ≠ d and
  the full parse runs. False positive, safe.
- **CR-only lines (`"\r"`)** — possible inside `rawHead()` (e.g. a `\r`
  immediately before the terminator's first `\r\n`); both parsers skip them,
  N ≠ d, full parse. Safe.
- **Duplicate `Cookie`/security headers** — duplicates make N > d, so the full
  parse always runs for them; the `match` block is byte-for-byte unchanged and
  the #217 regression test passes.
- **Middleware-added headers (`setHeader()`)** — the dangerous one.
  `SymfonyController` converts the request *after* the middleware pipeline, so
  `header()` can contain names that are not in the raw head. Without
  adjustment, an attacker could send one duplicate line while middleware adds
  one new name: N = d_raw + 1 = count(header()), counts match, and the
  duplicate (e.g. a second `Cookie`) would be silently missed — a false
  negative re-opening the #217 class. Hence `addedHeaderCount()` on
  `Http\Request`: `count(header()) - addedHeaderCount` recovers d_raw.
  Overwrites of existing raw names do not change either count and keep the
  fast path. The adjustment can only err toward a mismatch (full parse) if the
  counter were ever wrong.
- **Plain `\Workerman\Protocols\Http\Request`** (not the bundle subclass) —
  cannot be mutated (no public header writer; `__set` goes to `properties`),
  so `$addedHeaders = 0` is correct.

### Assumption I could not close

`Workerman\Protocols\Http\Request::$data` is `protected`, so a userland
subclass of the bundle's `Http\Request` could in theory mutate
`data['headers']` without going through `setHeader()` and desync the counter.
Nothing in the bundle or vendor does this (verified by grep), the converter's
own docblock documents middleware additions via `setHeader()` as the supported
mutation path, and the pre-gate code had the same exposure to any code that
corrupts `header()`. Accepted and documented rather than defended against.

## Alternatives considered and rejected

- **`substr_count($rawHead, "\r\n") - 1` (issue suggestion, verbatim)** —
  wrong offset for this Workerman version; would make the gate always true
  (correct but useless). See arithmetic above.
- **Scan only the special names (`cookie`, `host`, …)** — misses duplicates of
  *other* headers, which are joined with `', '` (with space) by
  `parseRawHeaderLines()` but `','` (no space) by Workerman's `parseHeaders()`;
  behavior would change for a whole class of duplicates. Rejected.
- **Per-name occurrence counting in the raw head** (`substr_count` per header
  name) — names appear inside other names (`accept` ⊂ `accept-encoding`) and
  inside values, producing constant false positives on exactly the header-heavy
  browser requests this issue optimizes; case-insensitivity would need a
  slower scan. Rejected.
- **Boolean "headers mutated" flag instead of a counter** — simpler, but any
  middleware that overwrites a header on every request (e.g. trusted-proxy or
  request-id middleware) would permanently disable the optimization for that
  application. The counter is three extra lines and keeps the fast path for
  overwrite-only mutations.
- **Always re-parse when the request is the bundle subclass** — same
  disable-the-optimization problem; the bundle class is what
  `Http::requestClass()` registers, so this would gate nothing in production.

## What I was unsure about

- Whether `rawHead()` includes the trailing CRLF on other Workerman versions
  the bundle supports. The gate is self-correcting if it does: one extra line
  in N would make counts differ and force the full parse everywhere (false
  positive, not a correctness bug). The vendored version pinned in composer
  was verified empirically (gate returns false for a well-formed
  unique-header request).
- Whether the count check alone (without the name check) would have been
  accepted. It would not have been behavior-identical (obs-fold case above),
  so the name check stayed.
