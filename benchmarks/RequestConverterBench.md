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
