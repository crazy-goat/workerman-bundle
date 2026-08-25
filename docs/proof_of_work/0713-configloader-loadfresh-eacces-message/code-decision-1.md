# Code Decision — Issue #713: ConfigLoader `loadFresh()` EACCES misdiagnosis message

## The problem
`ConfigLoader::loadFresh()` throws a `LogicException` whose tail says
"…no cached config file exists." This misdiagnoses the directory-EACCES case
(where the cache file DOES exist, but the containing directory is not
searchable) as "no cache file exists". The documented behaviour in #614 is that
this exception is also the fail-open signal for an unreachable cache, so the
tail wording should not assert non-existence.

## Approach taken
Reworded only the tail of the message in `src/ConfigLoader.php` (~L331):

- before: `…no cached config file exists. Ensure the cache has been warmed up…`
- after:  `…no cached config file could be loaded. Ensure the cache has been warmed up…`

This is a single-line string reword. No guard logic, control flow, or
exception type was touched.

## What was rejected and why
- **Changing guard logic** — explicitly forbidden by the issue, and unnecessary:
  the exception still fires for every "no config available" condition; only the
  diagnostic wording improves.
- **Reworking the message into distinct cases (separate message for EACCES vs
  missing-file)** — out of scope for this issue; #614 deliberately keeps a single
  fail-open `LogicException`. The reword ("could be loaded") covers both cases
  without splitting the path.

## Uncertainty
None material. The only risk was breaking the 3 test assertions that check this
exception. Confirmed they only match the substring `'Configuration not available'`
(`tests/ConfigLoaderTest.php:609`, `:1006`, `:1016`), so the tail reword is safe
and the assertions need not change.
