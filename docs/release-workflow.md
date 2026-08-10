# Workflow: Release (Changelog → Tag → Release Notes → Close Milestone)

This document describes the complete release workflow for the
[crazy-goat/workerman-bundle](https://github.com/crazy-goat/workerman-bundle)
repository: cutting a release when a milestone is empty, following the
release gate defined in [workflow.md](workflow.md#release-gate).

---

## Trigger: the release gate

Work is milestone-driven ([workflow.md](workflow.md#release-gate)). When the
lowest open milestone has no open issues left,
[`bin/pick-issue.php`](../bin/pick-issue.php) — re-run after every
merge — exits with code **3** (`RELEASE NEEDED`) instead of printing
candidates:

```bash
php bin/pick-issue.php
# exit code 3 → target milestone is empty; cut the release
```

The workflow stops here: do **not** pick an issue from a higher milestone
until the release is cut and the milestone closed.

Release checklist overview:

- [ ] 1. Changelog PR: `[Unreleased]` → `[0.X.Y] - YYYY-MM-DD`
- [ ] 2. Tag `v0.X.Y` and push it
- [ ] 3. Fix up the auto-generated release notes
- [ ] 4. Close the milestone
- [ ] 5. Re-run `bin/pick-issue.php`

---

## 1. Changelog PR

### Prerequisites

```bash
git checkout master
git pull origin master
git checkout -b chore/release-0.25.0
```

### Edit `CHANGELOG.md`

Rename the `## [Unreleased]` section to the new version with today's date:

```diff
-## [Unreleased]
+## [0.25.0] - 2026-08-10
```

Release notes are written bottom-up while work is merged, so at this point
the section should already contain every merged change. If a note is
missing (e.g. a fix merged without its changelog entry), add it here — the
section body becomes the release notes verbatim (see step 3).

### Open the PR

```bash
git add CHANGELOG.md
git commit -m "chore: release 0.25.0" -m "Cut the 0.25.0 release: promote the Unreleased section to a dated release entry."
git push -u origin chore/release-0.25.0
gh pr create --base master --head chore/release-0.25.0 \
  --title "chore: release 0.25.0" --body-file release-notes-pr-body.md
```

A recommended PR body summarises what ships in the release, grouped by
category (BC breaks, Security, Memory/performance, Tests/CI/tooling, Docs),
and ends with a release checklist:

```markdown
- [x] Changelog entry dated
- [ ] Tag `v0.25.0` + release notes after merge (see docs/release-workflow.md)
- [ ] Close milestone 0.25.0 after release
```

### Merge

Wait for CI (`.github/workflows/tests.yaml` and `release.yaml` do not run on
PRs, but Lint, CI, Benchmark and the Tests matrix do). Merge once green:

```bash
gh pr checks <NUMBER> --watch   # wait until every check passes
gh pr merge <NUMBER> --merge --delete-branch
```

---

## 2. Tag and push

Release tags are annotated and always cut from `master` **after** the
changelog PR is merged, so the tag's tree contains the release notes:

```bash
git checkout master && git pull origin master
git tag -a v0.25.0 -m "Release 0.25.0"
git push origin v0.25.0
```

> **Note:** tag pushes to `v*` trigger
> [`.github/workflows/release.yaml`](../.github/workflows/release.yaml)
> (a `release` event), which creates the GitHub Release automatically with
> `generate_release_notes: true`. The tag becomes **published** without any
> further manual step.

---

## 3. Release notes: match the changelog

The CI-published release body is the auto-generated "What's Changed" PR
list, but every previous release in this repository carries the
corresponding **CHANGELOG section as its body** (verified against
`v0.24.1`/`v0.23.0`: the release body equals the changelog section for that
version, excluding the `## [X.Y.Z] - date` heading). Follow the same style.

Extract the section from `CHANGELOG.md`:

```bash
sed -n '/^## \[0.25.0\]/,/^## \[0.24.1\]/p' CHANGELOG.md \
  | sed '1d;$d' \
  | sed -e '/./,$!d' -e '${/^$/d;}' \
  > /tmp/rel-0.25.0.md
```

(Adjust the two version anchors to the release and the previous release.)
Then overwrite the auto-generated notes:

```bash
gh release edit v0.25.0 --notes-file /tmp/rel-0.25.0.md
gh release view v0.25.0 --json body -q '.body' | head    # verify
```

If a changelog entry references relative links (`docs/…`, `README.md`),
they keep working because the release page renders on the same repository.

---

## 4. Close the milestone

The milestone is empty by definition — close it with the release date:

```bash
gh api -X PATCH repos/crazy-goat/workerman-bundle/milestones/<NUMBER> \
  -f state=closed -f due_on=2026-08-10
```

Find the number with `gh api "repos/crazy-goat/workerman-bundle/milestones?state=open"`.

---

## 5. Verify: the gate opens again

```bash
php bin/pick-issue.php
```

With the empty milestone closed, the next open milestone (e.g. `0.26.0`)
becomes the target and candidates are printed again — the release cycle is
complete and issue picking can resume.

---

## Quick reference

| Step | Command |
|------|---------|
| Detect release | `php bin/pick-issue.php` → exit code `3` |
| Changelog branch | `git checkout -b chore/release-<version>` + edit `CHANGELOG.md` + PR |
| Release PR title | `chore: release <version>` |
| Tag | `git tag -a v0.25.0 -m "Release 0.25.0" && git push origin v0.25.0` |
| Release was auto-published | by `release.yaml` on tag push |
| Notes fix-up | `sed`-extract changelog section → `gh release edit <tag> --notes-file <file>` |
| Close milestone | `gh api -X PATCH .../milestones/<N> -f state=closed -f due_on=<date>` |
| Resume work | `php bin/pick-issue.php` → exit code `0` |
