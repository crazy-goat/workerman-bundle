# Findings — coder (#628, cookie parsing vs max_input_vars)

## Obstacles

None. The previous session's uncommitted work was complete and coherent; it
needed verification only. No corrections were required.

## Surprises

- `composer lint` emits a **pre-existing** kb-lint warning:
  `docs/helpers/faq.md: 350 lines (index excluded) is over the 300-line
  budget — promote or drop entries`. Not caused by this change (this change
  does not touch docs/helpers/) and kb-lint still exits OK. Flagged here so
  the retro step knows the budget debt exists.

## Bugs / weak spots noticed in passing

Nothing new found in this issue's scope — the diff was minimal and correct.
Out-of-scope observations:

1. `docs/helpers/faq.md:5` (line budget) — faq.md is at 350 content lines
   against a 300-line budget; kb-lint warns on every run. Suggested fix:
   promote stale entries to decisions.md or drop low-hit entries during the
   next retro step (`hits=0` on many FAQ entries suggests candidates).
2. `tests/RequestConverterTest.php:1093-1120` (the new test itself) — the
   characterisation test asserts count + first/last values but not that pair
   ordering/content of the middle pairs survives; acceptable for a pin, but a
   future parity-cap implementation could pass by keeping only pairs
   `c0000` and `c1000`. If stronger pinning is ever wanted, assert a random
   middle pair too (e.g. `c0500`). Left as-is: the test's job is to fail when
   a cap is added, which first/last already does for any realistic cap value.

No other issues observed; phpstan (level per phpstan.neon.dist) and Rector
report clean on the touched files.
