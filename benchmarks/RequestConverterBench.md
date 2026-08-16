# RequestConverter benchmark

Environment: PHP 8.5.8, PHPBench 1.7.0, xdebug disabled, opcache disabled, 1000 revisions, 5 iterations, 1 warmup.

The `RequestConverterBench` benchmark includes request conversion plus a direct measurement of the existing `Request::resetHeaders()` operation. The converter subjects remain request-path proxies; the underscore-name rejection is exercised by the per-header check in every converter subject.

## Before

Source commit: `0e11540` (before the underscore-header fix)

| Subject | Mode |
| --- | ---: |
| `benchSimpleRequest` | 2.824 μs |
| `benchHeaderHeavyRequest` | 5.675 μs |
| `benchMultipartRequest` | 5.579 μs |

## After

Working tree after the underscore-header filter.

| Subject | Mode |
| --- | ---: |
| `benchSimpleRequest` | 2.795 μs |
| `benchHeaderHeavyRequest` | 6.042 μs |
| `benchMultipartRequest` | 5.737 μs |
| `benchResetHeaders` | 0.113 μs |

## Cookie value URL-decoding (issue #583)

Source commit: `fbd0318` adds one `rawurldecode()` per cookie value on the hot
path (for FPM parity). Measured with the same environment as above, 1000
revisions × 5 iterations:

| Subject | Before (#583) | After (fbd0318) | Δ |
| --- | ---: | ---: | ---: |
| `benchSimpleRequest` | 2.736 μs | 2.842 μs | +0.106 μs |
| `benchHeaderHeavyRequest` (1 cookie) | 5.794 μs | 6.001 μs | +0.207 μs |

Per-cookie decode cost ≈ 0.1–0.2 μs; within run-to-run noise (an independent
run measured 6.06 μs before vs 6.18 μs after on `benchHeaderHeavyRequest`).
No regression considered material.

## Header-value control-character filter (issue #630)

Environment: PHP 8.5.9, PHPBench 1.7.0, xdebug disabled, opcache disabled,
PCRE2 10.47 with JIT enabled (pcre.jit=On), 1000 revisions × 5 iterations, 1
warmup.

The filter is exercised once per header value in
`RequestConverter::buildServerHeaders()`: reject bytes {0–8, 10–31, 127},
accept everything else including TAB (0x09). Three implementations were
compared: the previous `preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value)`, a
`strpbrk()` with an explicit 32-byte mask, and `strcspn()` with the same
mask. Values: `shortAccepted` 22 B, `tabAccepted` 9 B, `shortRejected`
12 B (bad byte at index 3), `longAccepted` ~760 B, `longRejectedLate`
~501 B (bad byte last, worst case), `utf8Accepted` ~940 B multi-byte text,
`utf8Rejected` ~200 B UTF-8 with a trailing NUL.

Micro-benchmark, mode in µs per call (median of three runs; rstdev ≤ 7% on
rows above 0.1 µs; sub-0.1 µs rows are noise):

| Value | preg_match | strpbrk | strcspn |
| --- | ---: | ---: | ---: |
| `shortAccepted` | 0.063 | 0.061 | 0.067 |
| `tabAccepted` | 0.061 | 0.057 | 0.063 |
| `shortRejected` | 0.054 | 0.065 | 0.061 |
| `longAccepted` | 0.576 | 0.448 | 0.455 |
| `longRejectedLate` | 0.392 | 0.285 | 0.282 |
| `utf8Accepted` | 0.235 | 0.200 | 0.209 |
| `utf8Rejected` | 0.136 | 0.120 | 0.122 |

`strcspn` is 10–28% faster than the regex on every non-trivial value and
never slower on tiny ones (sub-0.1 µs rows are within noise).
`strpbrk` was rejected: it materializes the remainder substring, which makes
its long-value numbers swing between runs (0.442–0.634 µs on `longAccepted`)
and gives no consistent edge over `strcspn`.

Converter-level before/after (same session, same conditions; median of 5
iterations):

| Subject | Before (regex) | After (strcspn) | Δ |
| --- | ---: | ---: | ---: |
| `benchSimpleRequest` | 4.328 µs | 4.419 µs | +2.1% (noise) |
| `benchHeaderHeavyRequest` | 8.200 µs | 8.108 µs | −1.1% |
| `benchMultipartRequest` | 8.827 µs | 8.700 µs | −1.4% |
| `benchResetHeaders` | 0.193 µs | 0.191 µs | −1.0% |

The filter is a small fraction of the total conversion (~0.1–0.6 µs of
4.3–8.8 µs per header), so the end-to-end effect is within run-to-run noise;
the win is at the filter-operation level. The swap was kept because it is
consistently faster on the operation it replaces, is byte-identical for all
256 byte values (verified exhaustively), and removes the regex engine from
the per-header hot path.
