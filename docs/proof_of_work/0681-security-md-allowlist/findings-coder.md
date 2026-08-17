# findings-coder.md — issue #681 (merge duplicate allowlist bullets)

Obstacles, surprises and weak spots noticed while implementing the
docs-only change. File/line references are to the post-edit working tree
unless stated otherwise.

## Obstacles

1. The issue brief's "Suggested fix" wording, taken literally, would have
   deleted the "default posture / denylist is a last line of defence, not a
   guarantee" reasoning from `docs/security.md`. That reasoning appears
   nowhere else in the file (the "Extension Allowlist" section at
   `docs/security.md:152-192` covers only mechanics: which extensions are
   served and that the denylist takes precedence). Resolution: keep bullet
   one intact and delete only the verbatim-duplicate bullet, so the issue's
   actual complaint (zero-information duplicate) is fixed without losing
   the rationale. Documented in `code-decision-1.md`.

## Weak spots noticed, outside this issue's scope

1. `docs/security.md:224` — the "Keep `$rootDirectory` isolated" bullet
   mixes two distinct recommendations (root isolation and never pointing
   the root at project root/VCS metadata) into one sentence with a
   double dash; ok as-is, but it is the longest bullet in the list and
   could be split. Suggested fix: split into two bullets — "keep the root
   dedicated to public assets" and "never include `.env`, source or VCS
   metadata under it". Low priority; cosmetic.

2. `.pi-subagents/artifacts/` accumulates agent outputs containing
   verbatim copies of issue findings (e.g.,
   `18f1b078_reviewer_0_output.md` quotes the same lines this issue is
   about). It is untracked (confirmed via `git ls-files`), so it cannot
   leak into commits, but if it is ever force-added or the ignore rule is
   dropped, findings with file paths could go into the repo history.
   Suggested fix: keep the ignore rule and, if desired, add a cleanup cron
   for artifacts older than N days.

3. `docs/security.md:152-192` — the "Extension Allowlist" section contains
   a deprecation note about the `servers[].static_files.allowed_extensions`
   YAML key (lines ~195-198) that is long and easy to misread as applying
   to the service-registered middleware shown above it. The note itself is
   accurate; consider moving it directly under the YAML block it warns
   about. Cosmetic.

## Validation performed

- `git diff` review of the one-line deletion (see report section 1).
- Grep across the repo for both bullet phrases: only the edited lines.
- `composer lint` (php-cs-fixer dry-run, PHPStan level 8, Rector dry-run,
  `bin/kb-lint.php`) — passed.
- `composer test` not run: docs-only `.md` change, no PHP/bin/YAML touched,
  no test references the edited strings (verified by grep). State this
  explicitly per the task requirements.

## Pre-existing issue observed during validation

4. `bin/kb-lint.php` reports `docs/helpers/faq.md` is over its 300-line
   budget (307 lines, index excluded) — pre-existing, not caused by this
   change. The linter suggests promoting or dropping entries. Out of scope
   here (only the retro step may edit docs/helpers/), but the trend will
   keep growing as FAQ entries accumulate. Suggested fix: promote one or
   two entries to `docs/helpers/decisions.md` or trim FAQ-0xx prose in a
   future retro round.
