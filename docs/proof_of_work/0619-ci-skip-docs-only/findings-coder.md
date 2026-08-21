# Findings — coder — #619 (skip full lint/test matrix for docs-only)

## Obstacles / surprises

### 1. GitHub Actions skip-propagation would have broken master CI silently
**Where:** `.github/workflows/tests.yaml`, `detect-changes` / `tests` / `benchmark` jobs.

The first draft gated `detect-changes` with `if: github.event_name ==
'pull_request'`. On a push to master that job would be skipped, and because
`tests`/`benchmark` `needs` it and their `if` carries no status-check function
(`always()`/`!failure()`), GitHub's default `success()` would propagate the
skip — the nine-leg matrix would *never* run on master. This is the
well-documented actions/runner#491 / #2205 behaviour. Fix: made
`detect-changes` unconditional and moved the event check inside the step
(short-circuit to `docs-only=false` for non-PR events). Worth a KB note — it
is a trap that bites anyone adding a conditional "decide whether to run the
heavy jobs" job.

**Suggested fix (already applied):** keep `detect-changes` unconditional; the
event guard lives in the shell step, not the job `if`.

### 2. The ci aggregator would have turned a docs-only PR red
**Where:** `.github/workflows/tests.yaml`, `ci` job, "Check test results" step.

The old logic `if [ tests != success ] && [ tests-scheduled != success ]; exit 1`
fires when both test jobs are skipped — exactly the docs-only case (PR event →
`tests-scheduled` skipped by its own `if`; `tests` skipped by docs-only). So a
docs-only PR would have shown a *failing* `ci`, the opposite of the goal.
Fix: read `needs.detect-changes.outputs.docs-only` and `exit 0` early when
true, before the tests-succeeded check.

## Bugs / weak spots noticed (incl. out of scope)

### F-1 (out of scope): kb-lint reports faq.md over its line budget — pre-existing, unrelated
**Where:** `docs/helpers/faq.md` — `kb-lint` warns "376 lines (index excluded)
is over the 300-line budget — promote or drop entries". `composer lint` still
exits 0 (warning, not error), so this does not block anything, but it is a
decaying knowledge-base file. **Suggested fix:** promote or drop faq entries
per the kb decay rules (a main-session task, not this issue).

### F-2 (out of scope, minor): PHP CS Fixer runs on PHP 8.5 while the project floor is 8.2
**Where:** `composer lint` → php-cs-fixer emits "running on PHP 8.5.9, but
the minimum supported is PHP 8.2 … may introduce syntax not available in 8.2".
This is an environment artefact (local PHP is 8.5), not a code defect, and the
CI lint job pins 8.2. No action needed locally; flagged only because the
warning is noisy.

### F-3 (in scope, verified not a problem): FAQ-034 `on:`-as-boolean gotcha
**Where:** `.github/workflows/tests.yaml`. A naive `yaml.safe_load(...)['on']`
would read the trigger block under the boolean `true` key. The repo's own
tests avoid this by using regexes on raw text (`assertMatchesRegularExpression`
on file contents), and I added new pins in the same style. `python3
yaml.safe_load` still parses the file cleanly (parse check is sound even if
the key is misread). No change needed; noted so a future local validator does
not assert on `['on']`.

## Verification performed

- `php vendor/bin/phpunit tests/GithubWorkflowsTest.php tests/CoverageCiGateTest.php`
  → 14 tests, 66 assertions, OK (exit 0).
- `composer test` (full suite, Workerman daemon on 8888/9999) → 2338 tests,
  17053 assertions, 32 skipped, OK (exit 0).
- `composer lint` (php-cs-fixer, phpstan, rector --dry-run, kb-lint,
  check-changelog) → exit 0 (one pre-existing kb-lint warning, see F-1).
- `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/tests.yaml'))"`
  → parses cleanly.
