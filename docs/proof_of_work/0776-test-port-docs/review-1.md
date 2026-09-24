# Review round 1 — #776

## Prior findings

No prior unresolved findings; initial review round.

## Review

- **Completeness:** `grep -rn '8888\|9999\|9991' docs/workflow.md docs/troubleshooting.md docs/helpers/faq.md CONTRIBUTING.md` shows all four port mentions listing 8888/9999/9991; no remaining two-port claim outside `docs/proof_of_work/` (historical records, correctly untouched).
- **Accuracy:** `tests/App/Kernel.php` is the source of truth (ports 8888/9999/9991); the new troubleshooting section says exactly that and states the numbers are hardcoded while `WMB_LISTEN_ADDR` overrides the address only.
- **FAQ-009 gate now true:** the entry previously certified a troubleshooting.md ports section that did not exist; the section now exists and the `gate=`/`trigger` name 9991.
- **Links:** the new section links `CONTRIBUTING.md#parallel-test-runs-across-git-worktrees`, an anchor that exists.

## Checks

- `grep` sweep for the two-port claim — only historical `docs/proof_of_work/` files remain.
- `composer lint` — passed (kb-lint 0 warnings, changelog/changelog-structure clean).
- `git diff --check` — clean.

## Findings

None. No knowledge-base candidate: FAQ-009 is the entry for this class and was corrected.
