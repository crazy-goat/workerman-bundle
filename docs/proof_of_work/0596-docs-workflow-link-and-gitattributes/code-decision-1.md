# Code decision — #596, round 1

## Approach

Keep `CONTRIBUTING.md` as the short setup/pre-PR checklist and frame
`docs/workflow.md` as its detailed companion. Clarify that project conventions
apply without subagents; delegation is an optional method. List all immediate
files and directories in `docs/README.md`, grouped by audience.

Preserve user-facing docs, runtime source/resources, manifests and `bin/README.md`.
Exclude contributor docs, benchmarks, E2E fixtures, analysis configuration,
Docker test tooling and executable `bin/` scripts. `/bin/* export-ignore` plus
`/bin/README.md -export-ignore` excludes future development scripts by default
without breaking README's existing link. Links from shipped docs to excluded
contributor material use GitHub URLs, following README's existing CONTRIBUTING
link convention. Also make README's GitHub Actions badge target absolute: its
old `../../actions/...` target failed the local-file link check.

Leave `.gitignore` and Composer scripts unchanged: export attributes affect
archives, not tracked checkout files. Composer executes only root-package
scripts; the successful consumer install with the hook script absent verifies
that no consumer lifecycle workaround is necessary.

## Alternatives rejected / uncertainty

- Merging the contributor documents would be a large rewrite; explicit
  summary/detail framing meets the acceptance criterion with a small change.
- Excluding all of `bin/` would break the shipped README's link; keeping all
  scripts would continue shipping development-only tooling.
- Keeping only the issue's original exclusion list would leave newer process
  docs and scripts in dist, including links to now-excluded targets.
- Preserve `composer.lock`: no need to broaden this issue to lock-file policy.
- Plain `git archive HEAD` cannot verify uncommitted contents. First checked
  `git archive --worktree-attributes HEAD | tar -t`, then built a complete
  candidate archive using a temporary index and `git archive <tree>` without
  changing the real index. The committed HEAD archive should be checked again
  after committing.

## Verification

- `php bin/check-changelog.php`: pass.
- `php bin/kb-lint.php`: pass, two existing budget warnings (see findings).
- `git diff --check`: pass.
- `vendor/bin/phpunit tests/BinDirectoryTest.php --no-coverage`: 14 tests,
  36 assertions, pass. No full daemon suite needed for a docs/attributes diff.
- Python local-file link checker: 92 relative Markdown file links pass across
  source root/index docs and their shipped counterparts, plus `bin/README.md`.
  Checked target existence, not HTTP reachability or heading anchors. Asserted
  required/excluded archive paths and that `bin/` contains only its README.
- Candidate archive tree: `17c22924686e36752bb4951f74f46463efd9a768`.
  Scratch evidence: `/private/var/folders/8p/4pn2b47136gf1q589b3qp8m40000gn/T/opencode/issue-596.kMs7pc/`.
- Copied `e2e/src` and `e2e/console` to scratch; replaced the fixture's path
  repository with an explicit Composer package repository pointing to the
  candidate ZIP (`dist.type=zip`, `file://` URL). `composer install
  --prefer-dist --no-interaction` succeeds and logs `Extracting archive` for
  the bundle. No `--no-scripts` or platform-requirement bypass used.
- Installed fixture exposes all three `workerman:*` commands. Kernel `/health`
  returns 200/`status: ok`; PHAR resource exists. Started its actual Workerman
  server on pre-checked port 8887, fetched `/health` successfully, then stopped
  it successfully via an EXIT trap. Runtime: PHP 8.5.10, Workerman 5.2.2;
  fixture's existing minimum-stability setting resolved Symfony 8.2.x-dev.
- `composer run-script post-install-cmd --no-interaction` in the source checkout:
  pass; installed pre-push hook is executable. Consumer install succeeds even
  though its vendor package has no hook installer, confirming root-only events.
