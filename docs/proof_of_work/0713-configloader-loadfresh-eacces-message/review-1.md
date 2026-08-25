# Review — Issue #713 (round 1)

## Scope reviewed
`git diff origin/master...HEAD` for `fix/issue-713-configloader-loadfresh-logicexception-mi`:

- `src/ConfigLoader.php` — one-line reword of the tail of a `LogicException`
  message in `loadFresh()` (the function body is only the `throw`).
- Two new proof-of-work docs: `code-decision-1.md`, `findings-coder.md`.

## Diff analysis

### The change itself (`src/ConfigLoader.php:331-332`)
```php
throw new \LogicException(
    'Configuration not available: no config has been set via setters and no cached '
  - . 'config file exists. Ensure the cache has been warmed up before accessing config.',
  + . 'config file could be loaded. Ensure the cache has been warmed up before accessing config.',
);
```

- **Exception type**: unchanged — still `\LogicException`. No signature,
  no new type, no thrown-or-returned value change.
- **Control flow / guard logic**: untouched. `loadFresh()` is a single
  `throw`; the caller's behaviour (fail-open `LogicException`) is identical.
- **PHPStan level 8**: the only modification is a string literal. No type
  information, no `@phpstan`/`@psalm` annotations, no new code path. No
  level-8 impact possible.
- **PSR-12 / style**: the reword preserved the exact concatenation style
  (`.` at the start of the continuation line, matching the neighbouring
  `sprintf(...)` continuation in `checkCacheFilePermissions`). Line length is
  within the 120-char soft limit. No style regression.
- **Behaviour neutrality**: confirmed behaviour-neutral. The reword does not
  change what is thrown, when, or to whom — only diagnostic text. Safe to merge
  on that basis.

### Test coverage (`tests/ConfigLoaderTest.php`)
Three assertions pin this exception:
- `:608-609` — `testLoadFreshThrowsWhenCacheDirNotSearchable` (chmod 0000 gate)
- `:1005-1006` — `testLoadFreshThrowsWhenNoConfigAndNoCache`
- `:1015-1016` — `testLoadFreshThrowsForAnyGetterWhenNoConfigAndNoCache`

All three use `expectExceptionMessage('Configuration not available')`, which is
a **substring** match (`assertStringContainsString`) in PHPUnit. The reworded
tail leaves the `'Configuration not available'` prefix intact, so all three
assertions still pass. The coder's claim is verified.

Note: the new wording even better documents the EACCES case these tests target
(the chmod 0000 test at ~609 is exactly the "directory-not-searchable" path the
issue is about), so the reword is semantically correct, not just safe.

### Outdated documentation grep
`grep -rn "no cached config file exists"` across `src/`, `tests/`, `docs/`:
- `src/` — no occurrences (the live code now uses the new wording).
- The only hits are in proof-of-work history:
  - `docs/proof_of_work/0614-configloader-fail-open-warning/...` — these are the
    *prior* review trail that flagged the old tail as a known gap and deferred
    the fix to a follow-up. They describe the state **as it was** at #614 and
    explicitly recommend this follow-up; they are not asserting the current
    code still contains the old string. No live contradiction.
  - the #713 `code-decision-1.md` / `findings-coder.md` — these quote the old
    and new wording intentionally to document the change.

No doc reproduces the old string as the current code state in a way that now
contradicts the source. `docs/security.md` and the #614 PoW files quote only the
`'Configuration not available'` prefix, which remains accurate.

## Verdict
The change is a safe, behaviour-neutral cosmetic reword. Exception type, control
flow, and guard logic are provably untouched; PHPStan level 8 and PSR-12 are
unaffected; the three relevant test assertions match on a preserved substring;
and no live documentation contradicts the new wording.

**No defects found.** Safe to merge.
