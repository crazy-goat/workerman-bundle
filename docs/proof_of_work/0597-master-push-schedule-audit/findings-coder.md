# findings-coder.md — issue #597 (workflow runs on pull_request only)

Obstacles, surprises and weak spots noticed while implementing. File:line
references are to the post-edit working tree.

## Obstacles

1. **`tests/GithubWorkflowsTest.php` asserted the exact block I had to
   replace.** Its `testASupersededRunIsCancelledInsteadOfFinishing` pinned
   `cancel-in-progress: true` and
   `group: .*pull_request\.number` (old lines 52-77 of the file). There is
   also a provenance docblock ("Marking a draft ready...") that an earlier
   review round (#704, F-2) deliberately kept. Both regexes would have
   failed after the concurrency change, so the test came into scope. I
   updated it and added two new tests guarding the trigger set and the
   scheduled leg — otherwise the suite would have gone red on the very
   commit that implements the issue. Suggested fix for the future: none —
   this is the correct outcome; a workflow change that doesn't touch this
   test should be double-checked.

2. **The old concurrency group was a latent trap awaiting a second
   trigger.** Before this change, `group: ${{ github.workflow }}-${{
   github.event.pull_request.number }}` was harmless only because the
   workflow ran exclusively on `pull_request`. The moment any second event
   type is added, non-PR runs share the group `Tests-` and
   `cancel-in-progress: true` makes every master push cancel the previous
   one. The issue's prescribed per-ref group fixes exactly this; anything
   in this file must keep a non-empty per-run discriminator.

## Out-of-scope findings

1. **`docs/workflow.md:423` ("CI workflow ... has four jobs") and the
   surrounding step-10 text will become stale.** It lists four jobs
   (lint/tests/benchmark/ci), implies PR-only triggering via the
   concurrency paragraph ("A run superseded by a newer push on the same
   pull request is cancelled"), and says nothing about schedule or
   dispatch. After this change the workflow has five jobs and four
   triggers. Suggested fix: a follow-up docs edit (this cycle's main
   session or a dedicated docs issue) updating the step-10 description and
   adding one sentence about the weekly run and the failure issue.

2. **CHANGELOG.md**: the issue's acceptance list includes "CHANGELOG.md
   receives an entry under [Unreleased]", but the task explicitly
   delegates that to the main session at workflow step 8. Flagging so it
   is not forgotten when the issue is closed.

3. **Step duplication between `tests` and `tests-scheduled`**
   (`.github/workflows/tests.yaml:133-161` vs `:76-114`): the nine-leg job
   and the scheduled job share ~25 structurally identical steps minus the
   artifact upload. Accepted tradeoff
   (an explicit job beats an unverifiable matrix expression — see
   code-decision-1.md), but if a third consumer of these steps appears,
   extract a local composite action.

4. **Local YAML validation gotcha:** PyYAML and Ruby's YAML 1.1 loaders
   both parse the top-level `on:` key as the boolean `true` (it is a YAML
   1.1 bool word), so a naive `yaml.safe_load(...)['on']` check silently
   inspects the wrong key. A parse-error check still works, but any
   structural assertion must keep raw scalar keys (custom loader
   construct_mapping). Not a repo bug; worth knowing for the next CI-file
   change.

5. **The issue opener's GitHub-side floor:** it depends on `gh` being
   preinstalled on `ubuntu-latest` runners (currently true) and on
   `issues: write` for the `GITHUB_TOKEN` (now explicit via the `ci` job's
   `permissions:` block). If `gh` ever stops shipping on runner images,
   the step fails loudly (visible in the run) rather than silently; the
   fallback would be `actions/github-script`.
