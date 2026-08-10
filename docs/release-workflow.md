# Workflow: Release (Changelog → Branch + Tag → Release Notes → Close Milestone)

This document describes the complete release workflow for the
[crazy-goat/workerman-bundle](https://github.com/crazy-goat/workerman-bundle)
repository: cutting a release when a milestone is empty, following the
release gate defined in [workflow.md](workflow.md#release-gate).

All development work flows into `master`. Each release therefore gets its
own **release branch `vX.Y.Z`** (e.g. `v0.25.0`), created at the release
commit, so that follow-up fixes and patch releases for older versions are
made from that branch instead of mixing into the master flow.

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

- [ ] 1. Changelog PR: `[Unreleased]` → `[0.X.Y] - YYYY-MM-DD`, completeness check, merge
- [ ] 2. Create release branch `v0.X.Y` + annotated tag `v0.X.Y`, push both
- [ ] 3. Fix up the release notes (draft release) and publish
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

Rename the `## [Unreleased]` section to the new version with today's date
**and restore a fresh `[Unreleased]` section on top** for the next cycle:

```diff
-## [Unreleased]
+## [0.25.0] - 2026-08-10
+
+## [Unreleased]
```

Release notes are written bottom-up while work is merged, so at this point
the section should already contain every merged change. If a note is
missing (e.g. a fix merged without its changelog entry), add it here — the
section body becomes the release notes verbatim (see step 3).

### Verify changelog completeness

Before opening the PR, check that **every issue in the milestone is
referenced in the release section** — a merged PR without a changelog entry
would otherwise go unnoticed until after the release is cut and
notifications are sent:

```bash
MILESTONE_NUM=$(gh api "repos/crazy-goat/workerman-bundle/milestones?state=open" \
  --jq '.[] | select(.title=="0.25.0") | .number')

sed -n '/^## \[0.25.0\]/,/^## \[0.24.1\]/p' CHANGELOG.md > /tmp/release-section.md

gh api "repos/crazy-goat/workerman-bundle/issues?milestone=${MILESTONE_NUM}&state=all&per_page=100" \
  --jq '.[].number' | while read -r n; do
  grep -q "#${n}" /tmp/release-section.md || echo "MISSING from changelog: #${n}"
done
# expected output: nothing
```

If an issue is reported missing, either add its entry to the section or
confirm it is a release-process meta-issue ("fix the release notes",
"close the milestone") that does not belong in the product changelog.
Cross-references to older issues (`#141`, `#217`, …) inside an entry do not
count as entries — each entry must carry its own issue link.

### Open the PR

Create the PR body file first, then open the PR:

```bash
cat > /tmp/release-pr-body.md <<'EOF'
## What

Cut the **0.25.0** release: promote the `[Unreleased]` section of
`CHANGELOG.md` to a dated release entry and restore a fresh
`[Unreleased]` section.

## What's in 0.25.0

- (Group the changelog categories — BC breaks, Security,
  Memory/performance, Tests/CI/tooling, Docs — with issue links.)

## Checklist

- [x] Changelog entry dated
- [x] Every milestone issue referenced (completeness check ran clean)
- [ ] Release branch `v0.25.0` + tag `v0.25.0` after merge
- [ ] Close milestone 0.25.0 after release
EOF

git add CHANGELOG.md
git commit -m "chore: release 0.25.0" \
  -m "Cut the 0.25.0 release: promote the Unreleased section to a dated release entry and restore a fresh Unreleased section."
git push -u origin chore/release-0.25.0
gh pr create --base master --head chore/release-0.25.0 \
  --title "chore: release 0.25.0" --body-file /tmp/release-pr-body.md
```

### Merge

Wait for CI (Lint, CI, Benchmark and the Tests matrix). Merge once green:

```bash
gh pr checks <NUMBER> --watch   # wait until every check passes
gh pr merge <NUMBER> --merge --delete-branch
```

---

## 2. Release branch + tag

Run **after** the changelog PR is merged, so both the branch and the tag
point at a tree that contains the release notes:

```bash
git checkout master && git pull origin master

# Release branch: the base for follow-up fixes / patch releases of this line
git branch v0.25.0
git push -u origin v0.25.0

# Annotated tag at the same commit
git tag -a v0.25.0 -m "Release 0.25.0"
```

Before pushing the tag, verify it points exactly at `origin/master` (a
stale local master is the classic failure here):

```bash
test "$(git rev-parse v0.25.0^{commit})" = "$(git rev-parse origin/master)" \
  && echo "OK: tag matches origin/master" \
  || { echo "ERROR: tag does not match origin/master — pull and re-tag"; exit 1; }

git push origin v0.25.0
```

> **Note:** tag pushes to `v*` trigger
> [`.github/workflows/release.yaml`](../.github/workflows/release.yaml),
> which creates the GitHub Release automatically (`generate_release_notes:
> true`, `draft: true`). The release is published as a **draft** — it does
> not notify subscribers yet.
>
> **Warning:** if the workflow is ever changed to publish non-draft
> releases on tag push, release notifications (watchers, RSS, dependents)
> fire immediately with the auto-generated "What's Changed" body — **not**
> the curated changelog — and the later `gh release edit` does not re-send
> them. For a release with BC breaks, that would ship the upgrade warning
> to nobody. Keep the release draft until step 3 has fixed the notes.

---

## 3. Release notes: draft → fix → publish

The draft release body is the auto-generated "What's Changed" PR list, but
every previous release in this repository carries the corresponding
**CHANGELOG section as its body** (verified against `v0.24.1`/`v0.23.0`:
the release body equals the changelog section for that version, excluding
the `## [X.Y.Z] - date` heading). Follow the same style.

Extract the section from `CHANGELOG.md` (adjust the two version anchors to
the release and the previous release):

```bash
sed -n '/^## \[0.25.0\]/,/^## \[0.24.1\]/p' CHANGELOG.md \
  | sed '1d;$d' \
  | sed -e '/./,$!d' -e '${/^$/d;}' \
  > /tmp/rel-0.25.0.md

# The extraction silently produces nothing if an anchor does not match —
# guard against uploading an empty body:
test -s /tmp/rel-0.25.0.md \
  || { echo "ERROR: extraction produced empty output — check version anchors"; exit 1; }
```

Overwrite the auto-generated draft notes and publish:

```bash
gh release edit v0.25.0 --notes-file /tmp/rel-0.25.0.md
gh release edit v0.25.0 --draft=false
gh release view v0.25.0 --json body -q '.body' | head    # verify
```

If a changelog entry references relative links (`docs/…`, `README.md`),
they keep working because the release page renders on the same repository.

---

## 4. Close the milestone

The milestone is empty by definition — close it; `closed_at` is recorded
automatically, `due_on` is not needed:

```bash
MILESTONE_NUM=$(gh api "repos/crazy-goat/workerman-bundle/milestones?state=open" \
  --jq '.[] | select(.title=="0.25.0") | .number')
gh api -X PATCH repos/crazy-goat/workerman-bundle/milestones/${MILESTONE_NUM} \
  -f state=closed
```

---

## 5. Verify: the gate opens again

```bash
php bin/pick-issue.php
```

With the empty milestone closed, the next open milestone (e.g. `0.26.0`)
becomes the target and candidates are printed again — the release cycle is
complete and issue picking can resume.

---

## Follow-up fixes on the release branch

Patch releases (e.g. `0.25.1`) and any hotfix for an older line are **not**
done on `master` — they are made on the release branch `v0.25.0`:

```bash
git checkout v0.25.0 && git pull origin v0.25.0
git checkout -b fix/<ticket>-<description>   # branch off the release branch
# ... fix + changelog entry under a new "## [0.25.1] - YYYY-MM-DD" heading ...
git push -u origin fix/<ticket>-<description>
gh pr create --base v0.25.0 --head fix/<ticket>-<description> --title "fix: ..."
```

Merge the PR into `v0.25.0` (CI runs on the PR as usual), then tag the
patch from the release branch:

```bash
git checkout v0.25.0 && git pull origin v0.25.0
git tag -a v0.25.1 -m "Release 0.25.1"
git push origin v0.25.1     # release.yaml fires: draft release → fix notes → publish
```

The fix also needs to reach `master` (and newer release branches) — via
`git cherry-pick` in a follow-up PR, or directly if the same fix targets
the next release line.

---

## Quick reference

| Step | Command |
|------|---------|
| Detect release | `php bin/pick-issue.php` → exit code `3` |
| Changelog branch | `git checkout -b chore/release-<version>` + edit `CHANGELOG.md` + completeness check + PR |
| Release PR title | `chore: release <version>` |
| Release branch | `git branch v0.25.0 && git push -u origin v0.25.0` |
| Tag | `git tag -a v0.25.0 -m "Release 0.25.0"` + `test` tag↔`origin/master` + `git push origin v0.25.0` |
| Release auto-created | by `release.yaml` on tag push (draft) |
| Notes fix-up | `sed`-extract changelog section (with `test -s` guard) → `gh release edit <tag> --notes-file <file>` → `--draft=false` |
| Close milestone | `gh api -X PATCH .../milestones/<N> -f state=closed` |
| Resume work | `php bin/pick-issue.php` → exit code `0` |
| Patch release | fix PR → `v0.25.0` branch → merge → tag `v0.25.1` from it → notes → publish |
