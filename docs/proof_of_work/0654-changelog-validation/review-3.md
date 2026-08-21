# review-3.md — #654 round 3 (commit 03d6543)

Reviewer: review-critical, round 3. Diff under review: `27cbe28..03d6543`.
findings-review.md read first; dispositions below (also appended there).

## Verdict

**APPROVE.** All four round-2 findings are resolved — three fixed with
fixtures that pin exactly what they claim, one accepted-as-documented per the
round-2 disposition. Two new nit-level findings surfaced from probing; both
are exotic-input residuals of the same fence/whitespace classes already being
chipped away at, and neither blocks merge. The feature has now been through
three adversarial rounds without a correctness regression.

## Round-2 dispositions (evidence for each)

1. **NF-1 — tilde fences unrecognized (low): FIXED.**
   `outsideFences()` now tracks *which* marker opened the current fence
   (`$fence = substr($trimmed, 0, 3)`) and closes only on the same marker.
   Verified in both directions: fixture
   `testABacktickFenceIsNotClosedByATildeMarker` (``` swallows an inner ~~~),
   and my probe with the roles reversed (~~~ swallows an inner ```,
   including a `### Fixed` that stayed invisible). Fixture
   `testATildeFencedHeadingNeverCountsAsStructure` pins the original NF-1
   scenario (tilde-fenced released heading → "no released version headings").
   A residual refinement of the same feature is recorded below as NF-5.
2. **NF-2 — unterminated fence, misleading generics (nit): FIXED.**
   `outsideFences()` returns `unterminated_at` (the 1-based opener line);
   `validateChangelogLines()` short-circuits to exactly one violation,
   `line N: unterminated code fence opened here — nothing after this line was
   checked`. Fixture asserts both the message and the *absence* of "no
   released version headings". Probes confirmed: the single-violation
   short-circuit is fail-closed (exit 1); bookkeeping is correct across
   sequential *closed* fences (no stale `unterminated_at`); and the documented
   ergonomics tradeoff is real — a genuine violation *before* the fence is
   also suppressed until the fence is fixed (probed). I accept the tradeoff:
   one clear action item beats a partial report from a half-blind parse, and
   the coder documented it rather than leaving it to be discovered.
3. **NF-3 — subheading capture not rtrimmed (nit): FIXED** (trailing side).
   `versionBlocks()` now rtrims captured subheadings;
   `testASubheadingWithTrailingSpaceStillCountsAsADuplicate` injects the
   trailing spaces via `preg_replace` so no editor's strip-trailing-whitespace
   setting can silently defuse the fixture — a thoughtful touch. The leading-
   whitespace variant persists → NF-6 below.
4. **NF-4 — unbalanced-backtick heuristic (nit): ACCEPTED AS DOCUMENTED**, per
   the round-2 disposition; the findings-coder.md note stands as the
   documentation of record. Nothing further to add — my round-2 probes already
   showed it fails closed on the inputs I could construct.

## New findings (round 3)

- **NF-5 (nit)** — bin/check-changelog.php:86–128 — the fence model treats
  any line starting with the same 3 characters as a valid closer, ignoring
  CommonMark's rule that a closing fence must be at least as long as the
  opener. In a 4-backtick fence containing ``` lines (the standard
  nested-fence idiom for showing markdown examples), the inner ``` prematurely
  closes the fence and later content lines leak back into the parse: probed
  ` ````markdown / ``` / ## [0.9.9] - 2020-01-01 / ``` / ```` ` — the heading
  was counted as a real release and the file passed with zero actual released
  headings. Fail-open, but it requires 4+-backtick fences in a CHANGELOG,
  which is vanishingly rare. Catcher: remember the opener's length and require
  `strlen(closer) >= strlen(opener)`; fixture with the nested idiom.
- **NF-6 (nit)** — bin/check-changelog.php:350–355 — the subheading fix
  rtrims only; extra *leading* spaces (`###   Fixed`) render as "Fixed" in
  Markdown but are still treated as an unknown subheading here, so the
  duplicate-detection dodge NF-3 closed against trailing whitespace remains
  open against leading whitespace (probed: `###   Fixed` + `### Fixed` passes).
  Catcher: `preg_match('/^###\s+(.+?)\s*$/')` or trim() the capture; fixture.

Carried over (acknowledged by the coder in round-3 notes, still unpinned):
`--help`/`-h` exit-0 behaviour has no test. Cosmetic; noted so it is not lost.

## Gates executed locally (all green)

- `php bin/check-changelog.php` on the real tree → OK, exit 0. Notably this
  exercises the round-2 prose heuristic against the updated #654 entry, which
  now contains inline ``` and `~~~` (odd total backtick count): the entry line
  starts with `- ` so the fence scanner never toggles on it, and the trailing
  issue-reference link survives span-stripping — no false missing-ref.
- `vendor/bin/phpunit --filter ChangelogStructureTest` → 34/34 green (30 prior
  + 4 new), 187 assertions.
- `vendor/bin/phpstan analyse bin/check-changelog.php tests/ChangelogStructureTest.php`
  → no errors (new array-shape return and `$fence` var annotation are clean).
- `vendor/bin/php-cs-fixer fix --dry-run --config=…` on both files → clean.
- Full `composer lint` end-to-end → green (only the pre-existing faq.md budget
  advisory).

No commits made; docs/helpers untouched.
