# Bin Directory

This directory contains development and contribution scripts for this bundle.
It is **not** the Symfony console you use to run the Workerman server.
Only this README is included in Composer dist archives; to run these scripts,
use a [source checkout](https://github.com/crazy-goat/workerman-bundle).
Composer runs lifecycle scripts only for the root package, not dependencies,
so installing this bundle into an application does not install its git hook.

For the Workerman server commands, use your **application's** `bin/console`
(e.g., `bin/console workerman:server start`).

## Scripts

### `install-git-hook.php`

Installs a pre-push git hook that runs `LINT_SKIP_AUDIT=1 composer lint` before each push
(`composer audit` needs the network, so only CI and a plain `bin/lint.sh` run it). The
hook is automatically installed by Composer via the `post-install-cmd` and
`post-update-cmd` scripts.

A lint failure blocks the push. That is the whole hook — there is nothing else
in it, and the hard gate is still CI (`ci-ok`).

**Manual reinstall:**
```bash
php bin/install-git-hook.php
```

**Remove:**
```bash
rm .git/hooks/pre-push
```

**Skip on push:**
```bash
git push --no-verify
```

### `check-coverage.php`

Parses a PHPUnit Clover XML file and exits non-zero when total line coverage
is below a threshold. Usage: `php bin/check-coverage.php <clover.xml> <threshold-percent>`.
The threshold is required (a number from 0 to 100); without it the script exits 2 and
never passes. Used by `composer coverage:check`.

### `check-changelog.php`

Structurally validates `CHANGELOG.md`: exactly one `[Unreleased]` heading and
it comes first, released headings match `## [x.y.z] - YYYY-MM-DD` with a real
calendar date, in strictly descending order, Keep a Changelog subheadings
(`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`) appear at
most once per version block, and every top-level entry carries an issue
reference — except the entries frozen in the script's
`LEGACY_ENTRIES_WITHOUT_A_REFERENCE` list. Lines inside fenced code blocks
(``` or `~~~`) are ignored — a fenced example heading is documentation, not
structure — and an unterminated fence is reported at its opening line instead
of producing misleading downstream messages. References are matched against
prose only: inline-code spans are stripped and
an anchor-style reference (`[x]` followed by `(#123)`) does not count. Wired into `bin/lint.sh`, so
the pre-push hook and the CI lint job run it too;
`tests/ChangelogStructureTest.php` drives the same script as a subprocess
against synthetic fixtures.

**Usage:**
```bash
php bin/check-changelog.php                # what composer lint runs
composer changelog:check                   # the same, as a composer script
php bin/check-changelog.php --root=/path/to/checkout
```

Exit codes: 0 = valid, 1 = violations found, 2 = usage error. `--root=` (or
the `CHANGELOG_CHECK_ROOT` environment variable, itself reported as a
warning) points the check at another checkout; the resolved root is always
printed so it is never ambiguous which tree was checked.

### `check-exception-usage.php`

Verifies that every type declared in `src/Exception/` (interface, abstract
base or `final class`) is referenced by at least one PHP file outside its
own definition across `src/`, `tests/`, `e2e/` and `benchmarks/`. The
exception hierarchy is advertised in the README as a feature with a counted
size, so an unused member is worse than ordinary dead code: it inflates that
number and signals a missing throw (a real error path escaping the
hierarchy). Issue #593 found two such classes that had shipped green for
many releases because nothing checked. Wired into `composer lint`, so the
pre-push hook and the CI Lint job run it too;
`tests/ExceptionUsageLintTest.php` drives the same script as a subprocess
against synthetic fixtures.

**Usage:**
```bash
php bin/check-exception-usage.php               # what composer lint runs
php bin/check-exception-usage.php --root=/path/to/checkout
```

Exit codes: 0 = every type is referenced, 1 = one or more unused types,
2 = usage error (unknown option, missing root, missing/unreadable
`src/Exception/`). The check is word-boundary based so `KernelException`
does not match `InvalidCacheDirectoryException`; a `use` import counts as a
reference, and a self-reference inside the class's own file does not.

### `lint.sh`

Runs every static analysis, linter and formatter check: `composer validate --strict`,
`composer audit`, PHP-CS-Fixer (dry run), PHPStan, Rector (dry run), `kb-lint.php`,
`check-changelog.php`, `check-exception-usage.php`, `shellcheck` on every shell script
(including extensionless ones such as `docker-test`) and `hadolint` on the Dockerfile.
It runs every step, even after one failed, and exits non-zero if any failed. A missing
tool (`shellcheck`, `hadolint`) is a failure. `composer lint` calls this script.

```bash
bin/lint.sh            # check only
bin/lint.sh --fix      # apply Rector, PHP-CS-Fixer and the kb-lint index fix, then check
```

The contract is in
[crazy-goat/.github `standard/lint.md`](https://github.com/crazy-goat/.github/blob/main/standard/lint.md).

### `pick-issue.sh`, `worktree.sh`, `worktree-done.sh`

Byte-identical copies of the shared scripts from
[crazy-goat/.github](https://github.com/crazy-goat/.github/tree/main/standard). Do not edit
them; change the original and copy it again. They pick the next issue, create a worktree
for it and clean it up. See [docs/workflow.md](../docs/workflow.md).

### `worktree-setup.sh`

Project hook called by `worktree.sh`: runs `composer install` in the new worktree.

### `kb-lint.php`

Lints the subagent knowledge base in `docs/helpers/` (`faq.md`, `decisions.md`)
and regenerates its tag index. Wired into `composer lint`; `composer lint-fix`
runs it with `--fix`. See
[docs/helpers/README.md](https://github.com/crazy-goat/workerman-bundle/blob/master/docs/helpers/README.md) for the entry format and the
decay rules it enforces.

**Usage:**
```bash
php bin/kb-lint.php            # what composer lint runs
composer kb-lint               # the same, as a composer script
php bin/kb-lint.php --fix      # regenerate the tag index and normalise the trailing newline
php bin/kb-lint.php --json     # machine-readable output
php bin/kb-lint.php --root=/path/to/checkout
```

**What it checks:**

| Check | Severity |
| --- | --- |
| every `###` entry is followed by a single-line `<!-- kb: … -->` front-matter comment | error |
| required keys present (`id`, `date`, `tags`, `trigger`, `hits`, `status`), no unknown keys | error |
| `id` matches `FAQ-NNN` / `DEC-NNN` for its file and is unique **across both files** | error |
| `date` is an ISO-8601 calendar date, `hits` a non-negative integer, `tags` lowercase `[a-z0-9-]` | error |
| `status` is one of `active` / `promoted` / `stale` | error |
| a `promoted` entry names its `gate=` and collapses to at most two body lines | error |
| a front-matter value contains `-->` (it would terminate the comment early and leak the tail into the rendered page) | error |
| the tag index between `<!-- kb-index:start -->` / `<!-- kb-index:end -->` matches the entries | error (fixed by `--fix`) |
| more than one tag index block in a file | error |
| a file is over the 300-line budget — the generated index, its `## Tag index` heading and the blank lines around it do not count | warning |
| near-duplicate entries | warning |
| `stale` entries (0 hits in 20 cycles) | listed |

Near-duplicate detection is a cheap heuristic, not a proof: entry titles and
bodies are lowercased, split on non-alphanumerics, stripped of stop words and
of tokens shorter than three characters, and two entries are reported when
their **overlap coefficient** — `|A ∩ B| / min(|A|, |B|)`, so a short entry
fully contained in a long one still scores high — reaches **0.75**. The
15-distinct-token minimum applies to the **larger** entry of the pair only:
applying it to both would skip exactly the short-inside-long case the overlap
coefficient was chosen to catch. `promoted` entries are excluded — they are
one-line pointers by design.

Works entirely offline. Exit codes: 0 = clean (warnings may still be printed),
1 = lint failure, 2 = usage error.

The resolved root is always printed (`kb-lint: root <absolute path>`, and
`root` / `root_from_env` in `--json`) so it is never ambiguous which tree was
linted. `KB_LINT_ROOT` points the script at another repository root and is
itself reported as a warning; the `--root=` option wins over it and is not
warned about. Neither is needed in normal use.

### `docker-test`

Runs a composer script (`test` by default) inside the `workerman-bundle-test`
Docker image, bind-mounting the working tree so source edits are picked up
without a rebuild. Named volumes (`wmb-vendor`, `wmb-var`) persist dependencies
and artifacts across runs. Builds the image automatically on first use or when
`--build` is passed. See `CONTRIBUTING.md` ("Running tests in Docker") for the
full workflow and the PHP 8.2 / Symfony 6.4 CI-parity rationale.

**Usage:**
```bash
bin/docker-test                    # composer test (default)
bin/docker-test test:coverage      # composer test:coverage
bin/docker-test coverage:check     # composer coverage:check
bin/docker-test install            # composer install
bin/docker-test --build            # rebuild the image, then run
bin/docker-test --build --build-arg APP_UID=$(id -u) --build-arg APP_GID=$(id -g)
```

On Linux, build with `--build-arg APP_UID=$(id -u) --build-arg APP_GID=$(id -g)`
once so bind-mounted files are owned by your host user; macOS/Windows Docker
Desktop users can ignore that. Requires Docker. Exit codes: the container's
exit code, or 1/2 on a `docker-test` error.
