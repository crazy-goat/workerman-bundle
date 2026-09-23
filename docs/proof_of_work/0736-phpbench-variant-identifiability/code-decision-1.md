## Round 1 — #736

Root cause: the repo's custom `aggregate` report used the phpbench `expression` generator **without** an `aggregate` (partition) key. With no partition by `variant_index`, `variant_name` resolves to null for every row, which is why `params` and `variant` rendered null and why the built-in `default` report (which partitions by `benchmark_class`/`subject_name`/`variant_index`/`iteration_index`) displayed the set names correctly.

Fix: add `"aggregate": ["benchmark_class", "subject_name", "variant_index"]` and a `set` column to the existing report; keep every existing column. Parameterised subjects now render `shortAccepted`, `tabAccepted`, … in the `set` column, and non-parameterised subjects show a blank `set`.

Rejected the issue's first suggested fix (21 explicit `benchFilterStrcspnLongAccepted`-style methods): it duplicates the filter matrix three ways and makes adding a case an edit in many places. Rejected the second (phpbench 2.x upgrade): unnecessary once the report config bug is understood, and it would churn every benchmark's annotations.

A regression test in `tests/RequestConverterTest.php` pins the report partition and the `set` column, next to the existing benchmark-mask equality test, so the identifiability cannot silently regress.
