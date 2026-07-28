# RequestConverter benchmark

Environment: PHP 8.5.8, PHPBench 1.7.0, xdebug disabled, opcache disabled, 1000 revisions, 5 iterations, 1 warmup.

The `RequestConverterBench` benchmark includes request conversion plus a direct measurement of the added `Request::resetHeaders()` operation. The converter subjects remain request-path proxies; `benchResetHeaders` measures the fix's added operation directly.

## Before

Source commit: `0cef5f2` (parent of the fix commit)

| Subject | Mode |
| --- | ---: |
| `benchSimpleRequest` | 2.921 μs |
| `benchHeaderHeavyRequest` | 5.915 μs |
| `benchMultipartRequest` | 6.310 μs |

## After

Working tree after the request-header cache fix and E2E coverage.

| Subject | Mode |
| --- | ---: |
| `benchSimpleRequest` | 3.167 μs |
| `benchHeaderHeavyRequest` | 5.943 μs |
| `benchMultipartRequest` | 5.361 μs |
| `benchResetHeaders` | 0.108 μs |
