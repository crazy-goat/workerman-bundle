# Review round 1 — #736

## Prior findings

No prior unresolved findings; initial review round.

## Diff under review

- `phpbench.json`: `aggregate` report gains `"aggregate": ["benchmark_class", "subject_name", "variant_index"]` and a `set` column; existing columns kept.
- `tests/RequestConverterTest.php`: new regression test pinning the partition and `set` column.
- `docs/helpers/faq.md` (FAQ-028): stale "params renders null / map rows by provider order" guidance replaced with the real cause and fix.

## Review

- **Root cause verified empirically:** the expression generator only resolves `variant_name` when the report partitions by `variant_index`. With the partition added, a full `composer bench` renders `set` = `shortAccepted`, `tabAccepted`, `longAccepted`, … for the parameterised subjects, and a blank `set` for non-parameterised subjects. All previously present columns (`benchmark`, `subject`, `revs`, `its`, `mem_peak`, `mode`, `rstdev`) still render.
- **No behaviour change to benchmarks:** only report configuration changed; subjects, providers, revisions and the control-char mask are untouched. `tests/RequestConverterTest::testBenchmarkControlCharMaskMatchesProduction` still passes.
- **Regression guard:** `testBenchmarkAggregateReportIdentifiesParameterSets` reads `phpbench.json` and asserts both the `variant_index` partition and the `set` column, so reverting the fix fails a unit test rather than only a human's eye.
- **Knowledge base:** FAQ-028 is corrected rather than duplicated; this is the single factual source for the phpbench report behaviour. `php bin/kb-lint.php` passes (the pre-existing over-budget warnings are #744's concern, not worsened materially by this reword).

## Checks

- `composer bench` (full suite) — passed; report shows the `set` column for every parameterised subject.
- `tests/RequestConverterTest.php` — passed (102 tests, 585 assertions).
- `composer lint` — passed (kb-lint OK, changelog OK).
- `git diff --check` — passed.

## Findings

None. No further knowledge-base candidate: FAQ-028 already carries the corrected rule.
