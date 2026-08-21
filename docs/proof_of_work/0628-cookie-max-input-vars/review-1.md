# Review round 1 — #628, commit 5fa042a (review-critical)

Scope: full diff of 5fa042a (`git show 5fa042a`) against issue #628. No earlier
findings to revisit (round 1). Working tree clean at HEAD = 5fa042a.

## Knowledge base consulted (tag index only)

Index tags matched to touched files (http, cookies, security, tests, docs):

- decisions.md: DEC-005, DEC-006, DEC-009, DEC-010, DEC-012, DEC-013, DEC-014
- faq.md: FAQ-012, FAQ-019, FAQ-025, FAQ-027

No documented decision is violated by this diff:

- DEC-010 (cookie raw-url-decode parity): untouched — no decoding change; the
  new text sits consistently beside it in the same paragraph.
- DEC-006 (hardening from #582–#586 stays intact): no hardening path touched.
- DEC-012 (bare angle-bracket placeholders): none introduced in the new prose.
- DEC-009 (single KB writer): coder correctly did NOT append to
  docs/helpers/*; deviation record lives in docs/security.md, KB candidate is
  proposed for the retro step (see below).
- FAQ-025 (Workerman header semantics): the new test does not gate on raw-head
  line counts, so its four corrections are not implicated.

## Critical checks

### 1. Factual accuracy of every NEW claim

| Claim | Where | Verdict |
|---|---|---|
| PHP's SAPI stops registering cookies once `max_input_vars` (default 1000) is reached and drops remaining pairs | docs/security.md:63-65, CHANGELOG, docblock | **Correct.** `php_default_treat_data()` keeps a per-call counter and stops once it exceeds `max_input_vars`; limit applies to `$_GET`/`$_POST`/`$_COOKIE` separately. Empirically probed on this runtime (PHP 8.5.9): `php -d max_input_vars=3 -r 'parse_str("a=1&b=2&c=3&d=4&e=5",$r);'` → exactly 3 registered + one E_WARNING "Input variables exceeded 3…"; default confirmed 1000 via `ini_get`. Same code path serves PARSE_COOKIE. |
| "(logging an E_WARNING)" | docs/security.md:64, code-decision-1.md | **Correct** — exactly one E_WARNING is emitted when the limit is exceeded (then parsing stops); not one per dropped pair. Coder's uncertainty note resolved: claim stands on all supported versions. |
| "the bundle parses every pair in the header" / "every pair … is registered even past PHP's default limit of 1000" | docs/security.md:65, src/DTO/RequestConverter.php:392-394 | **Correct** of `parseCookiesFromServerBag()` (src/DTO/RequestConverter.php:400-424): `explode(';', …)` + unbounded `foreach`, no cap, no early return. (Pedantically, empty-name pairs are skipped by both PHP and the bundle — not a doc-worthy exception.) |
| "only the first 1000 under PHP-FPM" | docs/security.md:66-67 | Correct for the stated scenario (1001+ well-formed pairs; default ini). |

### 2. Characterisation test

`testCookieParsingIsNotCappedAtMaxInputVars`
(tests/RequestConverterTest.php:1100-1121):

- Pins the right thing: any parity cap ≤ 1000 (the only realistic value —
  PHP's default) makes `assertCount(1001, …)` fail, forcing the
  docs/security.md paragraph to change with the behaviour. First/last value
  assertions additionally catch off-by-one caps (1000 kept) and name mangling.
- 1001 pairs ≈ 12 KB header — trivially fast (measured: 0.035 s, 36 MB) and
  far under Workerman's `max_package_size`, so no flake/perf risk.
- Weakness (nit, open): middle-pair content is unpinned — an implementation
  keeping only `c0000` and `c1000` would pass. Coder self-flagged this in
  findings-coder.md; left as-is deliberately. Agree it is acceptable for a
  cap-detector; a one-line `assertSame('v0500', …)` would close it.

### 3. CHANGELOG

- Section `### Changed` under `[Unreleased]`: correct; docs-only entries have
  direct precedent there (#590, #591, #614).
- Keep-a-Changelog format respected; issue link format
  `([#628](https://github.com/crazy-goat/workerman-bundle/issues/628))` is
  byte-identical to neighbouring entries (#574, #651, #726).
- "Documentation only, no behaviour change" is accurate (docblock + test are
  non-behavioural).

### 4. Fit, tone, cross-references

- New sentences extend the existing "Known intentional deviations from
  `$_COOKIE`" paragraph in the established enumeration style ("Finally, …")
  without restructuring; grep confirms docs/security.md is the only doc
  enumerating `$_COOKIE` deviations, so no other page needed the fourth item.
- Cross-references valid: docblock "see docs/security.md" follows the
  pre-existing convention in the same docblock (line 387); test docblock's
  quoted paragraph title matches the actual text at docs/security.md:57;
  `@see` issue URL correct.
- README.md / UPGRADE.md contain no cookie-parity claims (grep clean) — no
  missed sync point.

### 5. Missing follow-ups

- None blocking. Per DEC-009 the decisions.md gap the issue mentions is
  closed by the retro step, not by this commit; candidate entry proposed in
  the review report (extend DEC-010 or add a short FAQ entry).
- Pre-existing kb-lint warning (faq.md over 300-line budget) reconfirmed
  (`php bin/kb-lint.php` → OK, 1 warning) — unrelated to this diff, already
  reported by the coder for the retro step.

### 6. Automated-check mapping

- Finding 1 (weak assertion strength): no existing check catches this;
  plausible future check = mutation testing (Infection) — a mutant dropping
  middle cookies survives today.
- No changelog-format or markdown-lint check exists in `composer lint`; had
  the entry been malformed, nothing would have caught it (it isn't).

## Verdict

APPROVE. Documentation-only change, every new claim factually verified
(including a live PHP probe), correctly placed, consistent format, and the
characterisation test genuinely pins the uncapped behaviour at the exact
default-limit boundary. One nit-level open finding (middle-pair assertion).
