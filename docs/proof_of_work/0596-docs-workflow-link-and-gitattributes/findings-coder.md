# Coder findings — #596, round 1

## Biggest problem

The archive/link requirements interact: `docs/README.md:7-11` originally linked
contributor content that was either newly excluded or itself linked already
excluded files (`docs/release-workflow.md:166` links `.github/`). Simply adding
export-ignore entries creates broken dist documentation. The issue's snapshot
is also older than the current tree, which has additional process docs and
scripts. Solution: categorize all immediate docs entries, exclude contributor
material consistently, and use GitHub links from shipped docs to source-only
material. Keep `bin/README.md` through an explicit attribute exception.

Archive verification needed to include uncommitted edits: `git archive HEAD`
alone would inspect the old tree. Used a temporary index to build a candidate
ZIP with changed content and installed that ZIP into a scratch copy of the E2E
fixture through an explicit Composer dist repository. This exercises actual
archive extraction rather than the fixture's usual path repository. No source
index changes were needed for verification. See code-decision-1.md for commands,
results and the scratch evidence location.

## Discovered bugs / improvements

### Fixed within scope

- `docs/README.md:7-13` (original): missing workflow/process docs, no audience
  labels, and misleading closing sentence. Replaced with a complete grouped
  index, including directories, and accurate navigation guidance.
- `README.md:4`: Actions badge used `../../actions/workflows/tests.yaml`, which
  is GitHub-relative rather than a valid file link in dist. Changed to the
  absolute repository Actions URL; found by automated local-link inspection.
- `.gitattributes:1-9` (original): newer contributor scripts/docs and Docker
  test tooling were not excluded. Added category-consistent exclusions while
  retaining runtime files and user-facing docs; automated archive assertions
  and successful installed-fixture HTTP request validate the result.

### Outside scope (not changed)

- `CONTRIBUTING.md:7-12`: states master has no branch protection; this conflicts
  with `docs/process-notices.md:16-19`, which records the `restric-main` ruleset
  arriving in #738. Suggested fix: verify the live ruleset and update the merge
  gate description. Newly linking the workflow should not silently rewrite
  policy without that check.
- `bin/install-git-hook.php:21-26`: assumes `.git` is a directory. Linked Git
  worktrees use a `.git` file, so the root lifecycle script fails there.
  Suggested fix: resolve hooks through Git (respecting core.hooksPath and
  worktree/common-directory behavior) and add a linked-worktree installer test.
  This checkout is a normal clone, so its lifecycle verification passes.
- `bin/install-git-hook.php:34-35`: unchecked `chmod()` can print success after
  failure to make the hook executable. Suggested fix: handle a false return
  and add a failure-path test. Not changed for this packaging issue.
- `.dockerignore:16-18`: comment says Composer locks are platform-specific and
  excludes root `composer.lock`, despite the recent reproducible-install
  decision documented at `CHANGELOG.md:12-15` (before this diff). Suggested fix:
  revisit the Docker test image lock exclusion so its dependencies match the
  intended reproducible contributor setup; test matrix resolution can remain
  separate.
- `docs/helpers/faq.md:1` and `docs/helpers/decisions.md:1`: KB lint passes but
  reports existing over-budget files (409 and 310 budgeted lines vs 300).
  Suggested fix: main-session retro should promote/drop entries; do not weaken
  the budget or edit helpers from this coder task.

## Knowledge-base candidate (proposal only)

- **Title:** Verify dist exclusions and links using a candidate-tree archive
- **Tags:** docs, bin, composer, packaging
- **Trigger:** changing export-ignore rules or links from shipped documentation
- **Entry:** `git archive HEAD` uses committed attributes/content, so verify
  uncommitted packaging changes with a temporary index (`read-tree`, add only
  intended files, `write-tree`, then archive that tree). Validate local link
  targets against the extracted archive and install the ZIP via an explicit
  Composer package/dist repository, not a path repository. Contributor docs
  excluded from dist need GitHub URLs in shipped indexes. Composer runs only
  root-package lifecycle scripts, so excluded development hook scripts do not
  affect consumers; still check the source checkout's post-install event.
