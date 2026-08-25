# #718 — Findings (review, round 1)

Format: `file:line | what is wrong | severity (high/medium/low/nit) | status (open / fixed by coder)`.

Round 1 — no prior findings.

## In scope

- `src/DTO/RequestConverter.php:227` (`if ($name !== \trim($name))`) | A header name with an INTERNAL space (e.g. `X-Fold Bar`) is unchanged by `trim()` and passes the guard, so Workerman's key `"x-fold bar"` is forwarded as `HTTP_X_FOLD BAR` — a `$_SERVER` key containing a literal space (the same smuggling-adjacent class #718 targets). Empirically confirmed via the real converter. The fix's mechanism only catches leading/trailing OWS, not internal whitespace. | medium | open — recommend a token/whitespace-presence check (`\strpbrk($name, " \t")`) or, if deferred, a follow-up issue + doc note. No automated check catches this; add a fixture like `testInternalSpaceHeaderNameIsDropped`.

- `docs/proof_of_work/0718-obs-fold-whitespace-prefixed-header-name/findings-coder.md` (out-of-scope bullet on `parseRawHeaderLines`) | Factually incorrect: claims internal-space names are "already rejected by Workerman's tokenisation in practice". Workerman keeps `"x-fold bar"` and the bundle leaks `HTTP_X_FOLD BAR`. | low | open — correct the note to state the leak is real (handled by F-1 or explicitly deferred).

- `src/DTO/RequestConverter.php:178-192` (`buildServerHeaders` docblock) | Documents the underscore-name drop but not the new whitespace-padded (OWS/obs-fold) name drop; asymmetry in the public contract description. | nit | open — add a bullet.

- `tests/RequestConverterTest.php:1332` (`testWhitespacePrefixedDuplicateHeaderIsStillJoined`) | Asserts only the joined value (`HTTP_X_FOLD => 'a, b'`), not the absence of the malformed `HTTP_ X_FOLD` key — the latent gap that hid the original bug. Coder's findings note already suggested back-filling but didn't. | low | open — non-blocking (new `testObsFold…` covers the combined case).

## Confirmed clean (no finding)

- Drop placement before `$key` construction (line 227 before line ~232): correct, prevents space-key build.
- Slow-path join preserved: `X-Fold: a` + ` X-Fold: b` -> `HTTP_X_FOLD => 'a, b'`, malformed key dropped — confirmed by `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey`.
- Trailing-space drop + no silently-trimmed key: confirmed by `testTrailingWhitespaceHeaderNameIsDropped`.
- PHPStan level 8 on changed file: OK, no errors.
- `RequestConverterTest` (98 tests): all pass.

---

## Round 2 — status of round-1 findings

All four round-1 findings answered. Three fixed, one deliberate non-fix.

- F-1 (`src/DTO/RequestConverter.php:227`, internal-space leak) — **FIXED**. The guard was widened from `if ($name !== \trim($name))` to `if (\strpbrk($name, " \t") !== false) { continue; }`, dropping any name containing a space or tab (leading, trailing OR internal). No legitimate header field-name is a token-with-whitespace, so this is strictly safer. Added `testInternalSpaceHeaderNameIsDropped` asserting `HTTP_X_FOLD BAR` and `HTTP_X_FOLD_BAR` are both absent. PHPStan level-8 clean; full `RequestConverterTest` green.
- F-2 (false claim in `findings-coder.md`) — **FIXED**. The out-of-scope note now states the internal-space leak is real, that the earlier "rejected by Workerman's tokenisation" claim was wrong (verified through the real converter), and that round 2 closed it.
- F-3 (`buildServerHeaders` docblock) — **FIXED**. Added a bullet documenting the whitespace-name drop (RFC 7230 §3.2.4).
- F-4 (`testWhitespacePrefixedDuplicateHeaderIsStillJoined` only asserts joined value) — **deliberately NOT fixed**. Non-blocking; the new `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey` already covers the combined absence+value case for the obs-fold path. Left as-is to keep the round scoped; could be back-filled later as a pure test-strengthening nit.

No new findings introduced by the round-2 change (verified by re-running PHPStan + the RequestConverter suite).
