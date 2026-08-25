# #718 — Findings (coder)

Issues found while implementing #718. Format: `file:line | description | severity | suggested fix`.

## In scope

- `src/DTO/RequestConverter.php:210-215` (buildServerHeaders) | A whitespace-prefixed/obs-fold header name was forwarded verbatim under a `$_SERVER` key containing a literal space (`HTTP_ X_FOLD`). Fixed by dropping names that differ from their `trim()` before key construction. | high | Fixed — added `if ($name !== \trim($name)) { continue; }` after the underscore-drop block.

## Out of scope (weak spots noticed)

- `src/DTO/RequestConverter.php:284-286` (parseRawHeaderLines) | `parseRawHeaderLines()` only handles a single `:` and trims the name, but a header line containing an internal `:` (e.g. `Date: Wed, 01 Jan ...`) is still fine; however a name with an *embedded* space in the middle (e.g. `"X-Fold Bar: v"`) trims to `"x-fold bar"` and is forwarded as `HTTP_X_FOLD_BAR` — Workerman's `parseHeaders()` also keeps it as `" x-fold bar"` (lower-cased, not trimmed). The new drop only catches leading/trailing whitespace, not internal spaces. Per RFC 7230 only obs-fold (leading space) and OWS around the name are illegal; internal spaces are already rejected by Workerman's tokenisation in practice, so this is low-risk. | low | Acceptable as-is; the leading/trailing trim covers the obs-fold case the issue targets. Could add a stricter token check later if desired.

- `src/DTO/RequestConverter.php:265-271` (special-header remap) | `CONTENT_TYPE`/`CONTENT_LENGTH`/`CONTENT_MD5` are moved out of the `HTTP_` namespace, but a malformed whitespace-padded `Content-Type ` would already be dropped by the new check, so no space-key leakage here. No action needed. | nit | None.

- `vendor/workerman/workerman/src/Protocols/Http/Request.php` (parseHeaders) | Root cause: `strtolower($parts[0])` is applied without trimming (documented as FAQ-025 bullet 2). Not fixable in this repo (vendored). The bundle compensates in `buildServerHeaders()`. | low | Documented; any future Workerman upgrade may change this behaviour — re-verify `rawHeadMayHaveDuplicates()` against the new vendored parser.

## Testing notes
- Existing test `testWhitespacePrefixedDuplicateHeaderIsStillJoined` already asserted `HTTP_X_FOLD => 'a, b'` (the *joined* value) but did **not** assert the absence of the malicious `HTTP_ X_FOLD` key — so the bug was latent and unguarded. Added `testObsFoldWhitespacePrefixedHeaderNameIsNotForwardedUnderSpaceKey` (asserts `HTTP_ X_FOLD` not present) and `testTrailingWhitespaceHeaderNameIsDropped`. The new drop sits *before* the duplicate-join logic, so the existing join test still passes (only the malformed key is removed). | nit | Suggest back-filling a `assertArrayNotHasKey` assertion into the pre-existing join test for belt-and-braces coverage.

## No blocking obstacles
The fix was small and the existing test scaffolding (raw buffer + `Workerman\Protocols\Http\Request`/`CrazyGoat\WorkermanBundle\Http\Request`) made the regression tests trivial to add in the same style as `testWhitespacePrefixedDuplicateHeaderIsStillJoined`. All 94 RequestConverter tests pass; PHPStan level-8 on the changed file reports no errors.
