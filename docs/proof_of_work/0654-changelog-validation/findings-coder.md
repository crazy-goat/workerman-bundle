# findings-coder.md — #654 (changelog structural validation in composer lint)

Appended by the coder subagent for issue #654. Each finding names a file/line
and a suggested fix; several are outside the issue's scope and were left
untouched on purpose.

## F-1. Global `main()` collision across bin scripts (latent fatal + PHPStan confusion)

`bin/pick-issue.php:447` declares `function main(array $options): void`,
`bin/kb-lint.php:642` `function main(array $options): int`, and now
`bin/check-changelog.php` declares one too. PHP function names are global:
including any two of these scripts in one process is a fatal
"Cannot redeclare main()". It also bit *this* task concretely — PHPStan
resolves `main(...)` calls across all analysed files, so my initial
`exit(main(...))` was type-checked against pick-issue's void signature and
failed with "Result of function main (void) is used" even though my own
signature returned int.

Suggested fix: give each script's bootstrap a unique name (`kbLintMain()`,
`pickIssueMain()`, …) or wrap each script in a namespaced
`namespace { ... }` block. Not done here to keep the diff to the issue's
scope; the new script at least follows the majority convention (void main,
internal exit) so behaviour is uniform.

## F-2. Scoped php-cs-fixer runs need an explicit --config

`vendor/bin/php-cs-fixer fix --dry-run -v <fileA> <fileB>` aborts with
"For multiple paths config parameter is required"
(PhpCsFixer\ConfigurationException\InvalidConfigurationException) because a
multi-path invocation disables auto-discovery of `.php-cs-fixer.dist.php`.
Single-path invocations work. Anyone scoping the linter to changed files (as
the review loop encourages) hits this with no hint in the error about the fix.
Suggested fix: either document `--config=.php-cs-fixer.dist.php` for scoped
runs in docs/workflow.md step 7, or add a `composer lint:files -- <paths>`
script that passes the config explicitly.

## F-3. docs/helpers/faq.md is over its own line budget (pre-existing)

`composer lint` warns: "docs/helpers/faq.md: 350 lines (index excluded) is
over the 300-line budget — promote or drop entries" (kb-lint LINE_BUDGET=300,
bin/kb-lint.php:68). The warning is advisory, but it has been printing on
every lint. Suggested fix: at the next retro, promote or merge FAQ entries
(FAQ-015 is already promoted; candidates exist among the date-time/http
clusters).

## F-4. ChangelogStructureTest duplicated rules that CI enforced only via PHPUnit (fixed by this issue)

Before this change the entire structural gate lived in
tests/ChangelogStructureTest.php, which runs only in the test matrix — the CI
Lint job (`composer validate --strict`, `composer audit`, `composer lint`)
never looked at markdown, exactly as issue #654 described. Now single-sourced
in bin/check-changelog.php. No action left; recorded because the
"test-only gate" pattern is easy to repeat: a PHPUnit test that encodes a repo
policy is not a lint gate until the policy is reachable from `composer lint`.

## F-5. Reference-format acceptance is looser than the visible convention

bin/check-changelog.php (validateChangelogLines, rule 4) accepts `[#123]`
anywhere in an entry's text — including wrapped continuation lines — and bare
`(#123)`. The rendered changelog convention is a trailing markdown link
`([#123](https://…))`. This matches the pre-refactor test deliberately (both
formats accepted, per the #686 rationale), but it means an entry whose only
reference is buried mid-sentence passes. Suggested fix (optional, needs a
decision): require the reference on the first line, or require the link form,
and update the frozen-list comment accordingly.

## F-6. check-changelog reports "no released version headings" for a fresh project

A CHANGELOG.md containing only `[Unreleased]` fails with "CHANGELOG.md has no
released version headings". That behaviour was inherited verbatim from the
pre-refactor test (assertNotEmpty on released headings), so it is not a
regression — but it will confuse anyone bootstrapping a new repo. Suggested
fix: downgrade to a warning once a `--strict` flag exists, or accept it and
document it in bin/README.md (currently documented only implicitly).

## F-7. Shell pipes mask exit codes (session observation, not a repo defect)

While gating this task, piping `vendor/bin/php-cs-fixer ... | head` reported
exit 0 even when cs-fixer exited 16 (see F-2). Any scripted gate that pipes
tool output must use `set -o pipefail` or capture `${PIPESTATUS[0]}`.
Worth remembering for CI step authoring; no repo change needed (CI steps run
tools unpiped).

---

# Round 2 — dispositions for review round 1 (findings-review.md)

## What was fixed

- **:6 (equality boundary)** — new fixture
  `testDuplicateReleasedVersionHeadingsFail`: `## [0.2.0] - 2025-01-01` twice
  must fail with "not strictly older"; relaxing `version_compare(...) >= 0`
  to `> 0` now breaks the suite.
- **:7 (fixture gaps)** — all four added: no `## [...]` headings at all →
  fail; only-`[Unreleased]` file → fail "no released version headings"
  (F-6 behaviour now pinned explicitly); unknown `### Fix` subheading →
  passes (silent skip pinned); reference on a wrapped continuation line →
  passes.
- **:8 (fences)** — `outsideFences()` blanks fenced lines (and fence markers)
  1:1 before any scanning, so line numbers survive; kb-lint's toggle
  semantics (`str_starts_with(trim($line), '```')`) mirrored. Fixtures:
  released heading only inside a fence → still fails "no released version
  headings"; one real + one fenced `### Fixed` → passes; two real + one
  fenced → fails with exactly "has 2".
- **:9 (reference false positives)** — rule 4 now matches prose only:
  inline-code spans stripped first (`entryProse()`), then `](#N)` anchor
  links removed. Accepted forms unchanged: `[#N](url)` and bare `(#N)`.
  Fixtures: `` `(#123)` `` only → fail; `[x](#123)` only → fail.
- **:11 (this script's globals)** — renamed to `checkChangelogMain()`,
  `checkChangelogParseArgs()`, `checkChangelogPrintUsage()`. The remaining
  helpers were already unique. pick-issue.php / kb-lint.php untouched per
  instruction.
- **:10 (PoW misstatement)** — code-decision-1.md rewritten: kb-lint declares
  `main(): int` (internal exits, declared return never observed), pick-issue's
  void main returns normally on success, and the actual PHPStan fix was
  dropping `exit(...)` around the bare call.
- **:12a (semantic dates)** — released-heading regex now captures the date and
  `checkChangelogIsIsoDate()` (shape + `checkdate()`, mirroring kb-lint's
  helper) rejects impossible ones. Fixture: `2026-13-45` → fail.
- **:13 (rtrim)** — `versionHeadings()`/`versionBlocks()` rtrim captured
  headings, so a trailing space on `## [Unreleased] ` is recognised instead of
  producing the misleading "found 0" + "does not match" pair. Fixture:
  trailing-space Unreleased file → passes.

## Deliberate non-fix (answered, not changed)

- **:12b (date monotonicity vs version order)** — out of scope by review
  disposition. Recorded as possible follow-up: today `## [1.0.0] - 2030-01-01`
  above `## [0.9.0] - 2020-01-01` passes because versions descend even though
  dates ascend. A future check could require release dates to be
  non-increasing alongside versions; it would need a decision about backdated
  patch releases first.

## Notes and new observations from round 2

- **Ambiguity resolved in :8's second fixture.** The instruction said
  "duplicate `### Fixed` where one copy is inside a fence → fails", but with
  exactly one unfenced copy the correct post-fix outcome is *pass* (the fence
  copy is documentation). Both readings are now pinned: one-real-plus-one-
  fenced passes, two-real-plus-one-fenced fails with "has 2" (fenced copy
  neither hides nor inflates the duplicate).
- **Subheading capture still does not rtrim** (bin/check-changelog.php,
  versionBlocks): `### Fixed ` with a trailing space is silently skipped as an
  unknown subheading — the same diagnostic class review flagged for
  `## [Unreleased] ` in :13, one level down. Left out of scope; suggested fix:
  trim the `### (.+)$` capture the same way.
- **`entryProse()` is a heuristic**: an unbalanced backtick in an entry strips
  text up to the next backtick (possibly on a later continuation line), which
  could hide a genuine reference there. Keep-a-Changelog entries rarely have
  stray backticks; acceptable for a lint, worth remembering if a false pass is
  ever reported.
- **The unreachable branch is gone**: merging shape-matching and version/date
  extraction into one regex (`^## \[(\d+\.\d+\.\d+)\] - (\d{4}-\d{2}-\d{2})$`)
  eliminated the dead "unable to extract the version" violation the old
  two-step code carried over from the pre-refactor test.

---

# Round 3 — dispositions for review round-2 findings (NF-1…NF-4)

## What was fixed

- **NF-1 (tilde fences)** — `outsideFences()` now tracks which marker opened
  the current fence (``` or `~~~`) and closes it only on the same marker, so
  a ``` line inside a ~~~ fence stays blanked. Fixtures:
  `testATildeFencedHeadingNeverCountsAsStructure` (tilde-fenced released
  heading → still fails "no released version headings") and
  `testABacktickFenceIsNotClosedByATildeMarker` (mixed markers).
- **NF-2 (unterminated fence)** — `outsideFences()` returns the opening
  line of an unclosed fence; `validateChangelogLines()` then reports exactly
  one violation, `line N: unterminated code fence opened here — nothing after
  this line was checked`, and skips all other checks. Rationale: everything
  past the opener is invisible to the parse, so any verdict beyond "close it"
  is unreliable — this is also why the generic downstream messages are gone
  rather than merely accompanied. Fixture asserts both the message and the
  absence of "no released version headings".
- **NF-3 (subheading rtrim)** — `versionBlocks()` rtrimms captured
  subheadings like the `## [` headings already did. Fixture:
  `testASubheadingWithTrailingSpaceStillCountsAsADuplicate` — two `### Fixed `
  (trailing space) still fail with "has 2". The trailing spaces are injected
  via `preg_replace` in the test rather than typed into the heredoc, so no
  editor's strip-trailing-whitespace setting can silently defuse the fixture.

## Answered, not fixed

- **NF-4 (unbalanced-backtick heuristic in `entryProse()`)** — accepted as a
  documented limitation, per disposition. The heuristic fails closed for
  well-formed input and only misjudges malformed Markdown (odd tick counts);
  making it a real inline-code parser is not worth it for a lint gate. The
  round-2 note in this file stands as the documentation of record.

## Notes

- The unterminated-fence early return slightly changes failure ergonomics: a
  file with an unterminated fence AND genuine violations before the fence
  reports only the fence until it is fixed. Accepted — one clear action item
  beats a partial report from a half-blind parse.
- Residual nit acknowledged by review in round 2 but not dispatched:
  `--help`/`-h` exit-0 behaviour is still unpinned by a test. Left as-is
  (cosmetic); noting it here so it is not lost.

---

# Round 4 — dispositions for review round-3 findings (NF-5…NF-6 + carried nit)

## What was fixed

- **NF-5 (closer-length rule)** — `outsideFences()` now records the opening
  fence's character and run length and closes only on a line that is a run of
  the same character at least as long as the opener (regex
  `^{char}{len,}\s*$`), per CommonMark. Fixture:
  `testAFourBacktickFenceIsNotClosedByAnInnerTripleBacktick` — the standard
  nested-fence idiom (```` wrapping ``` wrapping a heading) no longer leaks
  the inner `## [0.9.9] - 2020-01-01` into the parse; the file still fails
  "no released version headings".
- **NF-6 (leading-whitespace subheadings)** — capture now uses `trim()`, so
  `###   Fixed` counts as "Fixed" for duplicate detection exactly like the
  rendered Markdown does. Fixture:
  `testASubheadingWithLeadingSpacesStillCountsAsADuplicate` — two
  `###   Fixed` entries fail with "has 2"; spaces injected via `str_replace`
  to survive whitespace-stripping editors.
- **Carried nit (--help)** — `testHelpExitsZeroAndPrintsUsage` pins both
  `--help` and `-h`: exit 0, usage line, `--root=DIR` and the env-var name in
  stdout.

## Notes

- The closer regex requires nothing but optional whitespace after the marker
  run (`{char}{len,}\s*$`), matching CommonMark's rule that closing fences
  carry no info string; an opening fence's info string (```php) is fine
  because only the leading run is captured.
- No new findings of my own this round; the fence model now matches
  CommonMark for everything a changelog can plausibly contain.
