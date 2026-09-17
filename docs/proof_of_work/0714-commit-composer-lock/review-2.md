# Review round 2 — separate #714 retro follow-up PR

## Scope and verdict

**Clean: no actionable findings and no blockers.**

This is the docs-only retro follow-up on
`process/issue-714-retro-phpunit-daemon` against `master`, not a second
implementation review of the already merged composer.lock PR #822.
At review time HEAD/base was `bcaca12`; the only implementation diff was the
uncommitted `docs/helpers/faq.md` addition of FAQ-039 and its three tag-index
references (8 insertions, 3 deletions). The main session is the single KB
writer; this reviewer did not edit the KB, commit, or push.

## Prior ledger disposition (read before new findings)

- Round 1 recorded **no actionable findings**: **not a real finding** / no
  unresolved defect to carry forward. This round does not reopen the merged
  dependency-lock implementation.
- The verification incident at `tests/App/bootstrap.php:14-15,55-60` is
  **still present** as underlying harness behavior, and **not a real finding**
  against this docs-only PR. Start and shutdown registration are unconditional;
  shutdown stops the daemon and deletes the three shared marker/log files.
  FAQ-039 accurately records the incident and serial-run remedy rather than
  claiming that the harness was fixed.
- Existing KB budget observations are **still present**, explicitly accepted
  by the user and tracked in #744. They are not new blockers. Dependency/network
  and PHP 8.2/container observations belong to round 1's verification scope,
  not changes introduced by this retro.

## Accuracy, conventions, links, and duplication

Read the FAQ and decisions tag indexes and relevant entries: FAQ-007/008/009,
FAQ-010, FAQ-019, FAQ-030/031/032/038/039; DEC-009/011/012/021, plus the KB
README. No documented-decision violation found.

- `phpunit.xml:5` selects the global bootstrap. Its unconditional calls at
  `tests/App/bootstrap.php:14-15` apply even to filtered runs; lines 55-60
  substantiate both stopping a shared daemon and deleting shared state.
- `CONTRIBUTING.md:206-248` substantiates separate container network namespaces,
  separate per-worktree state, and the prohibition on shared `var/` volumes.
  FAQ-039 retains these essential qualifications; it does not suggest that
  separate host worktrees alone isolate ports. The linked Docker heading at
  line 146 exists and contains the parallel-worktree subsection.
- Both relative links resolve correctly from `docs/helpers/faq.md`.
  PR #822's association with #714 is confirmed by the base commit subject;
  the prior ledger records the unchanged serial suite success.
- FAQ-039 has valid metadata, a fresh unique ID, one topic, and all three
  tags (`tests`, `daemon`, `process`) represented in the generated index.
- FAQ-007/008 address different daemon failure modes; FAQ-009 covers port
  conflicts; FAQ-032 covers incomplete workflow-test selection. None records
  filtered-run teardown interference. The new entry complements those lessons
  and links to CONTRIBUTING rather than duplicating its setup instructions.
- DEC-009's single-writer rule is respected. No raw prose angle-bracket
  placeholders violate DEC-012. No PHP/API, PSR-4, type, or runtime changes;
  no workflow YAML changed, so FAQ-032's workflow-consumer sweep is inapplicable.

## Independent verification

Executed serially, without overlapping PHPUnit runs, on PHP 8.5.10:

1. `php bin/kb-lint.php` — exit 0; 59 entries, 0 stale, two accepted budget
   warnings: FAQ 409 budgeted lines and decisions 310 (limit 300 unchanged).
2. `php -d phar.readonly=0 vendor/bin/phpunit --no-coverage tests/KnowledgeBase`
   — exit 0, **37 tests / 1084 assertions**.
3. `php -d phar.readonly=0 vendor/bin/phpunit --no-coverage tests/MarkdownLinkTest.php`
   — exit 0, **609 tests / 1970 assertions**.
4. `git diff --check` — exit 0.

These checks cover KB metadata/index/ID validity and Markdown link correctness.
No gates were lowered. Full runtime/matrix testing was not repeated for this
five-line prose addition; the main session handles the separate PR and CI.

## New findings and proposed KB entries

None. FAQ-039 itself captures the user-approved lesson; no duplicate candidate
entry is proposed. Commit/PR/CI/merge remain with the main session.
