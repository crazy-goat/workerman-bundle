# review-4.md — #654 round 4 (commit 3ffb7cc)

Reviewer: review-critical, round 4. Diff under review: `03d6543..3ffb7cc`.
findings-review.md read first; dispositions below (also appended there).

## Verdict

**APPROVE.** All three dispatched items are fixed with fixtures that pin what
they claim, and the closer-length implementation is CommonMark-correct on
every edge I probed (info strings, tabs, longer closers, short content runs).
Two new nit-level findings surfaced — both indentation-handling deviations
from CommonMark in the same family as the issues rounds 3–4 just fixed;
neither blocks merge. This is not a CLEAN round in the strict sense (I record
two nits below rather than declaring zero findings), but it is the cleanest
round so far: four consecutive rounds without a correctness regression, and
every remaining open item is a documented exotic-input nit.

## Round-3 dispositions (evidence for each)

1. **NF-5 — closer-length rule (nit): FIXED.**
   The opener regex now captures the full marker run (`/^(`{3,}|~{3,})/`,
   char + length recorded), and a closer must be a same-character run at
   least as long as the opener with nothing but whitespace after it
   (`^{char}{len,}\s*$`) — which also correctly forbids info strings on
   closing fences per CommonMark. Evidence:
   - Fixture `testAFourBacktickFenceIsNotClosedByAnInnerTripleBacktick` pins
     the exact round-3 false-pass idiom; re-probed: the ````markdown / ``` /
     `## [0.9.9] - 2020-01-01` / ``` / ```` file now fails "no released
     version headings" instead of counting the phantom release.
   - Probed edges, all correct: closer with trailing tab closes (`\s*`
     matches tabs); a ```sh info-string line *inside* a ``` fence does NOT
     close it; a ~~~~ line inside a ~~~ fence DOES close it (longer run);
     2-backtick lines inside a ``` fence are content, not closers.
   - preg_quote hygiene on the interpolated char; `$fence['length']` is
     always ≥ 3 by construction. PHPStan clean.
2. **NF-6 — leading-whitespace subheadings (nit): FIXED.**
   Capture now `trim()`s instead of rtrims; fixture
   `testASubheadingWithLeadingSpacesStillCountsAsADuplicate` injects
   `###   Fixed` via `str_replace` (editor-proof, same technique as round 3)
   and asserts "has 2". Side effect checked: a spaces-only capture trims to
   `""`, which the strict `in_array` skips — harmless.
3. **Carried nit — `--help` unpinned: FIXED.**
   `testHelpExitsZeroAndPrintsUsage` loops over both `--help` and `-h`,
   asserting exit 0 plus usage text, `--root=DIR`, and the env-var name on
   stdout. Nothing left of the original round-1 medium finding.

## New findings (round 4)

- **NF-7 (nit)** — bin/check-changelog.php:108–127 — fence toggling operates
  on `trim($line)`, so a ``` / ~~~ marker indented **4+ spaces** — which
  CommonMark classifies as an indented code block, not a fence — still
  toggles the scanner's fence state. Structure between two such markers is
  blanked although CommonMark renders it outside any fence (probed the benign
  direction: an indented block containing `### Fixed` is blanked, which
  happens to match rendered output; the misbehavior direction — a real
  unindented heading sitting between two indented markers — would be hidden).
  Requires contrived input for a changelog. Catcher: count leading spaces and
  only treat ≤3-indented markers as fences; fixture.
- **NF-8 (nit)** — bin/check-changelog.php:versionHeadings/versionBlocks —
  `## [` heading detection anchors to column 0, so an ATX heading indented up
  to 3 spaces — which Markdown renders as a real heading — is invisible to
  every heading rule. Probed: `   ## [Unreleased]` yields a false "found 0"
  (fail-closed annoyance), and by the same mechanism an indented released
  heading would escape ordering/duplicate-version checks (fail-open dodge).
  This is NF-6's exact class one level up: the round-2 rtrim fix and round-4
  trim fix both stopped at the subheading capture. Catcher: allow ≤3 leading
  spaces in the heading matchers; fixtures for indented Unreleased and
  indented released headings.

## Gates executed locally (all green)

- `vendor/bin/phpunit --filter ChangelogStructureTest` → 37/37 green (34 prior
  + 3 new), 211 assertions.
- `vendor/bin/phpstan analyse bin/check-changelog.php tests/ChangelogStructureTest.php`
  → no errors (new array-shape `$fence` and interpolated quantifier are clean).
- `vendor/bin/php-cs-fixer fix --dry-run --config=…` on both files → clean.
- `php bin/check-changelog.php` on the real tree → OK, exit 0.
- Full `composer lint` end-to-end → green (only the pre-existing faq.md budget
  advisory).

No commits made; docs/helpers untouched.
