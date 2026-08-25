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

---

## Review Round 2 — independent verification (reviewer, 2026-08-25)

Commit under review: `da85f76` (`fix(dto): drop any whitespace-containing header name, not just OWS-padded`).

### Round-1 findings restated with evidence

| ID | Location | Status | Evidence |
|----|----------|--------|----------|
| F-1 | `src/DTO/RequestConverter.php` guard (was `:227`) | FIXED | Guard widened to `if (\strpbrk($name, " \t") !== false) { continue; }`. Workerman keeps `x-fold bar` as a real key for `"X-Fold Bar: v"` (verified), so the guard genuinely drops it. `testInternalSpaceHeaderNameIsDropped` asserts `HTTP_X_FOLD BAR` and `HTTP_X_FOLD_BAR` both absent → passes. |
| F-2 | `findings-coder.md` false claim | FIXED | `findings-coder.md` carries an explicit 2026-08-25 correction: the "rejected by Workerman tokenisation" claim was wrong, leak is real, closed in round 2. |
| F-3 | `buildServerHeaders` docblock | FIXED | Docblock now lists the whitespace-name drop bullet (RFC 7230 §3.2.4), symmetric with the underscore bullet. |
| F-4 | `testWhitespacePrefixedDuplicateHeaderIsStillJoined` only asserts joined value | NOT A DEFECT (non-blocking, deliberate) | Combined absence+value case covered by `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey`. Reviewer agrees non-blocking. |

### NEW findings

**No new findings.**

Round-2 change verified safe:
- `\strpbrk($name, " \t")` mask is literal space+tab (RFC 7230 OWS); `strpbrk` takes a byte set, no `..`-range trap (cf. FAQ-027).
- No legitimate header field-name (a `token` per RFC 7230 §3.2.6) may contain SP/HTAB, so no false-positive drop of valid names.
- `testInternalSpaceHeaderNameIsDropped` exercises the drop (Workerman emits `x-fold bar`, reaches the guard); would fail if the guard were absent.
- PHPStan level 8 on `src/DTO/RequestConverter.php`: `[OK] No errors`.
- PSR-12 / comment accuracy: clean.
- Legitimate duplicate-join slow path preserved: `X-Fold: a` + ` X-Fold: b` → `HTTP_X_FOLD => 'a, b'`, malformed `HTTP_ X_FOLD` dropped (test passes). Full `RequestConverterTest`: 95 tests / 550 assertions, green.

**Verdict: CLEAN** — all fixable round-1 findings fixed, no new defects.
