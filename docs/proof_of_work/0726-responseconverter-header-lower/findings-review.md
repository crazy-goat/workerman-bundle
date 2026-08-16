# Findings — review (issue #726)

## Round 1 (2026-08-16)

No findings. The change is sound.

| ID | file:line | what is wrong | severity | what happened to it |
|----|-----------|---------------|----------|---------------------|
| — | — | No findings in round 1 | — | n/a (round 1, no prior findings to revisit) |

### Checked and clean

- **Key invariant** `strtolower(normalize($name)) === strtolower($name)`: holds by construction (ucfirst only uppercases; CORRECTIONS all lowercase back to keys). Verified programmatically. Guarded by `testNormalizedHeaderNameLowercasesBackToInput`.
- **Hot path allocation**: by-ref out-param is COW — zero additional allocation vs. old code; saves one `strtolower()` per header. No array/tuple/object created.
- **TRANSPORT_HEADERS stripping**: unchanged logic, identical `$lowerName` value. HEAD Content-Length exception preserved (tested). Set-Cookie array handling untouched.
- **BC surface**: `@internal` class, optional parameter with default, no external callers of `normalize()`. Private `normalizeHeaderName()` on `final readonly class`. No BC break.
- **`?string &$lower = null`**: explicitly nullable (no PHP 8.4/8.5 deprecation, verified on 8.5.9). `@param-out string $lower` satisfies PHPStan level 8 (verified clean). Omitted-argument behavior correct.
- **KB compliance**: DEC-006 (transport header stripping) preserved, DEC-014 (bounded cache) unchanged, FAQ-001 (HEAD Content-Length) preserved. No loosening.
- **Proof-of-work accuracy**: code-decision-1.md and findings-coder.md are accurate. One cosmetic typo ("cleanuup") in code-decision-1.md, not in source.
