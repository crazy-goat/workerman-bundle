# Release workflow

One milestone = one release. Versions follow
[Semantic Versioning](https://semver.org/) and the changelog follows
[Keep a Changelog](https://keepachangelog.com/). Tags are `vX.Y.Z`.

Everything is written in **English**, including release notes.

## 1. Release gate

A release is ready when the milestone has **no open issues**:

```bash
gh api repos/{owner}/{repo}/milestones --jq '.[] | select(.title=="vX.Y.Z") | {title, open_issues, closed_issues}'
```

- Open issues that will not make it: move them to the next milestone
  (`gh issue edit <N> --milestone vX.Y.(Z+1)`).
- The default branch must have a green `ci-ok`.

## 2. Choose the version

| Change | Bump |
|---|---|
| Bug fixes only | patch (`1.2.3` → `1.2.4`) |
| New, backward compatible features | minor (`1.2.3` → `1.3.0`) |
| Breaking changes | major (`1.2.3` → `2.0.0`) |

Before `1.0.0`, breaking changes bump the minor version. The milestone title
already holds the planned version. Change the milestone title if the plan changed.

## 3. Prepare the CHANGELOG (pull request)

```bash
git switch -c chore/release-vX.Y.Z
```

In `CHANGELOG.md`:

- Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD`.
- Add a fresh empty `## [Unreleased]` above it.
- Group entries under Added, Changed, Deprecated, Removed, Fixed, Security.
- Update the compare links at the bottom, if the file has them.
- Check that every issue of the milestone is referenced in the new section; a merged pull
  request without a CHANGELOG entry is easy to miss:

  ```bash
  gh issue list --milestone vX.Y.Z --state all --limit 200 --json number --jq '.[].number' \
    | while read -r n; do grep -q "#$n" CHANGELOG.md || echo "MISSING from CHANGELOG: #$n"; done
  ```

  Expected output: nothing (release-process meta issues do not belong in the CHANGELOG).
- No file carries the version: `composer.json` has no `version`. Breaking changes and
  deprecations are described in `UPGRADE.md`; check that every **Breaking** entry links to its
  `UPGRADE.md` section. `bin/lint.sh` validates the CHANGELOG structure.

Open a PR titled `chore: release vX.Y.Z`, wait for `ci-ok`, squash merge.

## 4. Tag

Tag the merge commit on the default branch with an **annotated** tag:

```bash
git switch master && git pull --ff-only
git tag -a vX.Y.Z -m "Release vX.Y.Z"
git push origin vX.Y.Z
```

## 5. GitHub Release

Pushing the tag starts `.github/workflows/release.yaml`. It creates the GitHub Release
with the notes from the matching `CHANGELOG.md` section, and fails when the section is
missing or empty.

This repository does not call the shared workflow: `.github/workflows/release.yaml` does
the same in place (notes from the CHANGELOG section, truncated at 120000 characters, then
`gh release create "$GITHUB_REF_NAME" --verify-tag --notes-file ...`). The release is
published at once, so the CHANGELOG section is what subscribers receive.

```bash
gh run watch
gh release view vX.Y.Z
```

## 6. Close the milestone

```bash
gh api -X PATCH repos/{owner}/{repo}/milestones/<number> -f state=closed
```

Make sure the next milestone `vX.Y.(Z+1)` (or the next minor) exists.

## 7. After the release

- Check that install instructions work with the new version (Packagist, Go proxy, ...).
- If something is wrong, do not move the tag. Fix forward with a patch release.
- A fix for an older line is rare. Branch from the old tag (`git switch -c release/0.X vX.Y.Z`),
  fix, add the CHANGELOG entry, merge, tag the patch from that branch, and port the fix to
  `master` with `git cherry-pick`, keeping the master entry under `[Unreleased]`.

## Checklist

- [ ] Milestone has no open issues, CI is green
- [ ] CHANGELOG section `[X.Y.Z] - date` written, `[Unreleased]` is empty
- [ ] Release PR merged
- [ ] Annotated tag `vX.Y.Z` pushed
- [ ] GitHub Release exists with the CHANGELOG notes
- [ ] Milestone closed, next milestone exists
