# Workflow: Issue → Feature Branch → Implementation → Code Review → PR → CI → Merge

This document describes the complete workflow for handling issues in the
[crazy-goat/workerman-bundle](https://github.com/crazy-goat/workerman-bundle)
repository using `gh` and `git`.

---

## 1. Browse Open Issues

```bash
# List open issues (title, number, labels)
gh issue list --state open --limit 30

# View a specific issue (description, labels, state)
gh issue view <NUMBER> --json title,body,labels,state
```

**Criteria for selecting the most impactful issue:**
- Issues labeled `enhancement`, `code-quality`, `good-first-issue`
- Issues about stability, data correctness, performance
- Issues blocking other tasks
- Issues most relevant to users (README, API documentation)

---

## 2. Create a Fresh Feature Branch

```bash
# Make sure you're on master with the latest changes
git checkout master
git pull origin master

# Create a feature branch
git checkout -b feat/issue-<NUMBER>-<short-description>
```

**Branch naming convention:** `feat/issue-<NUMBER>-<kebab-case>`
or `fix/issue-<NUMBER>-<kebab-case>` (e.g. `feat/issue-491-update-readme`)

Existing examples in this repository:
- `feature/295-servermanager-magic-timeout-constants`
- `fix/270-runtime-directory-restrictive-mode`
- `docs/282-bin-directory-unexplained`

---

## 3. Implement the Change

```bash
# Edit files, then commit and push
git add -A
git commit -m "feat(core): implement <short description> (closes #<NUMBER>)"
git push origin feat/issue-<NUMBER>-<description>
```

**Commit message convention:**
- Type: `feat`, `fix`, `docs`, `refactor`, `ci`, `test`, `chore`
- Scope: `(core)`, `(runtime)`, `(command)`, `(config)`, `(ci)`, `(dto)` etc.
- Reference to issue: `(closes #<NUMBER>)`

---

## 4. Code Review via Subagent

After implementation, run a code review using a subagent (separate agent with
its own context). The subagent checks:

- Alignment with project structure (PSR-4, Symfony Bundle conventions)
- Type correctness and signatures (PHPStan level 6)
- Error handling and edge cases
- Coding style (PSR-12, php-cs-fixer)
- Test coverage
- Security (Workerman child processes, HTTP input, process supervision)

```bash
# The subagent receives a task like:
# "Code review the changes in files: <list of files>.
#  Check: type correctness, error handling, PSR-12 compliance,
#  missing tests, outdated documentation.
#  List all issues to fix."
```

---

## 5. Fix Issues Found in Code Review

```bash
# For each problem found:
# 1. Apply the fix
# 2. Commit with a descriptive message
git add -A
git commit -m "fix: <description of fix>"
git push origin feat/issue-<NUMBER>-<description>
```

**All issues must be fixed – even the least significant ones.**

---

## 6. Repeat Code Review

After fixing, invoke the subagent for another code review.

Repeat steps 5→6 until the subagent reports no issues.

> **Acceptance criteria:** The subagent responds: "Code looks good, no issues
> to fix."

---

## 7. Run Linters and Tests Locally

Before opening a PR, verify that all linters and tests pass on your machine:

```bash
# Run all linters (php-cs-fixer dry-run, phpstan, rector dry-run)
composer lint

# Auto-fix fixable issues (php-cs-fixer, rector)
composer lint-fix

# Run tests (boots a real Workerman daemon on ports 8888 and 9999)
composer test
```

> **Note:** `composer test` boots a real Workerman daemon binding ports 8888
> and 9999 for E2E tests. If you see "Address already in use" errors, ensure
> those ports are free. To stop the server manually if tests were interrupted:
> `php tests/App/index.php stop`

After `composer lint-fix`, commit any fixes:

```bash
git add -A
git commit -m "style: auto-fix lint issues"
```

**Only create the PR when all lints and tests pass locally.**

---

## 8. Update CHANGELOG.md

```bash
# Edit CHANGELOG.md:
# - Add entry under [Unreleased] section
# - Follow Keep a Changelog format (https://keepachangelog.com/en/1.1.0/)
# - Use appropriate section: Added, Changed, Fixed, Removed, Deprecated
# - Include issue number, e.g. (#491)
```

---

## 9. Create a Pull Request

```bash
# Create a PR from the feature branch to master
gh pr create \
  --title "feat: <short description> (closes #<NUMBER>)" \
  --body "## Description

Closes #<NUMBER>

## Changes

- <list of changes>

## Changelog

<!-- Describe the changelog entry for this PR -->

## Code Review

- [ ] Passed subagent code review
- [ ] All review comments addressed" \
  --base master \
  --assignee @me
```

> **Note:** If you don't use `gh`, create the PR manually via GitHub UI.
> Branch protection requires **at least 1 approving review** before merge.

---

## 10. Wait for CI

```bash
# Check PR status
gh pr view --json statusCheckRollup

# Wait for all checks to finish
gh pr checks --watch
```

CI workflow (`.github/workflows/tests.yaml`) runs:
1. **lint** – composer validate, composer audit, php-cs-fixer, phpstan, rector
2. **tests matrix** (PHP 8.2–8.5 × Symfony 6.4–8.0) – unit + E2E tests
3. **ci** – aggregator checking that lint and tests passed

---

## 11. Handle CI Failures

If CI fails:

```bash
# 1. See which checks failed
gh pr checks

# 2. View logs
gh run view --log --job <job-name>

# 3. Fix the issues locally
# 4. Run code review via subagent again (repeat steps 4-6)
# 5. Commit the fixes
git add -A
git commit -m "fix: <description of CI fix>"
git push origin feat/issue-<NUMBER>-<description>

# 6. Wait for CI to re-run
gh pr checks --watch
```

> **Note:** The pre-push hook runs `composer lint` before every push.
> To skip the hook in emergencies: `git push --no-verify`

**Repeat until all CI checks pass.**

---

## 12. Merge PR and Close Issue

```bash
# Merge PR (squash merge recommended for clean history)
gh pr merge --squash --delete-branch

# Close the issue (automatic if commit contains "closes #<NUMBER>")
# Alternatively:
gh issue close <NUMBER>
```

> **Note:** If branch protection requires a review, `gh pr merge` may be
> blocked. In that case, use the GitHub UI to squash-merge after obtaining
> approval.

---

## 13. Switch Back to master

```bash
git checkout master
git pull origin master
```

Done. Ready to start the next cycle from step 1.

---

## Quick Reference – Full Cycle

```bash
# 1. Pick an issue
gh issue list --state open --limit 30
gh issue view <NUMBER>

# 2. Feature branch
git checkout master && git pull origin master
git checkout -b feat/issue-<NUMBER>-<description>

# 3. Implementation
# ... coding ...
git add -A && git commit -m "feat: implement <desc> (closes #<NUMBER>)"
git push origin feat/issue-<NUMBER>-<description>

# 4. Code Review (subagent)
# ... fix issues ... (repeat until clean)

# 5. Run linters and tests locally
composer lint
composer test

# 6. Update CHANGELOG.md

# 7. PR
gh pr create --title "feat: <desc> (closes #<NUMBER>)" --body "..." --base master

# 8. CI
gh pr checks --watch
# ... if failures → fix, code review, push → wait for CI (repeat)

# 9. Merge
gh pr merge --squash --delete-branch
gh issue close <NUMBER>

# 10. Switch back to master
git checkout master && git pull origin master
```

---

## Notes

- **gh** must be configured and authenticated (`gh auth status`).
- Branch protection on `master` requires:
  - **at least 1 approving review** before merge
  - All status checks passing (lint, tests)
  - Branch up-to-date with master (recommended)
- Pre-push hook automatically runs `composer lint` before each push.
  To skip: `git push --no-verify`
- Keep feature branches short-lived. If a rebase is needed:
  ```bash
  git fetch origin master
  git rebase origin/master
  git push --force-with-lease origin feat/issue-<NUMBER>-<description>
  ```
- Code review via subagent runs locally – the subagent has access to
  read/write/edit/bash tools. Give it clear instructions on what to check.
