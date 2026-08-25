# #718 — Findings (coder)

Issues found while implementing #718. Format: `file:line | description | severity | suggested fix`.

## In scope

- `src/DTO/RequestConverter.php:210-215` (buildServerHeaders) | A whitespace-prefixed/obs-fold header name was forwarded verbatim under a `$_SERVER` key containing a literal space (`HTTP_ X_FOLD`). Fixed by dropping names that differ from their `trim()` before key construction. | high | Fixed — added `if ($name !== \trim($name)) { continue; }` after the underscore-drop block.

## Out of scope (weak spots noticed)

- `src/DTO/RequestConverter.php:284-286` (parseRawHeaderLines) | `parseRawHeaderLines()` only handles a single `:` and trims the name, but a name with an *embedded* space in the middle (e.g. `"X-Fold Bar: v"`) trims to `"x-fold bar"` and — with the original `name !== trim(name)` guard — was forwarded under the literal-space key `HTTP_X_FOLD BAR`. Workerman's `parseHeaders()` keeps it as `"x-fold bar"` (lower-cased, not trimmed), so it was NOT rejected. This is the same smuggling-adjacent class #718 targets. **Correction (2026-08-25): the earlier claim that internal spaces are "already rejected by Workerman's tokenisation in practice" was wrong** — verified through `RequestConverter::toSymfonyRequest()`. Fixed in round 2 by widening the guard to drop any name containing a space or tab (`\strpbrk($name, " \t") !== false`), covering leading, trailing AND internal whitespace, and adding `testInternalSpaceHeaderNameIsDropped`. | medium | Fixed in round 2.

- `src/DTO/RequestConverter.php:265-271` (special-header remap) | `CONTENT_TYPE`/`CONTENT_LENGTH`/`CONTENT_MD5` are moved out of the `HTTP_` namespace, but a malformed whitespace-padded `Content-Type ` would already be dropped by the new check, so no space-key leakage here. No action needed. | nit | None.

- `vendor/workerman/workerman/src/Protocols/Http/Request.php` (parseHeaders) | Root cause: `strtolower($parts[0])` is applied without trimming (documented as FAQ-025 bullet 2). Not fixable in this repo (vendored). The bundle compensates in `buildServerHeaders()`. | low | Documented; any future Workerman upgrade may change this behaviour — re-verify `rawHeadMayHaveDuplicates()` against the new vendored parser.

## Testing notes
- Existing test `testWhitespacePrefixedDuplicateHeaderIsStillJoined` already asserted `HTTP_X_FOLD => 'a, b'` (the *joined* value) but did **not** assert the absence of the malicious `HTTP_ X_FOLD` key — so the bug was latent and unguarded. Added `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey` (asserts `HTTP_ X_FOLD` not present) and `testTrailingWhitespaceHeaderNameIsDropped`. The new drop sits *before* the duplicate-join logic, so the existing join test still passes (only the malformed key is removed). | nit | Suggest back-filling a `assertArrayNotHasKey` assertion into the pre-existing join test for belt-and-braces coverage.

## No blocking obstacles
The fix was small and the existing test scaffolding (raw buffer + `Workerman\Protocols\Http\Request`/`CrazyGoat\WorkermanBundle\Http\Request`) made the regression tests trivial to add in the same style as `testWhitespacePrefixedDuplicateHeaderIsStillJoined`. All 94 RequestConverter tests pass; PHPStan level-8 on the changed file reports no errors.
