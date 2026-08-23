# Contributing to Workerman Bundle

Thank you for your interest in contributing to this project!

## What Gates a Merge

`master` carries **no GitHub branch protection** — this is a solo-maintainer
project with a single collaborator, so there is nobody else to require a
review from, and GitHub does not allow approving your own pull request
anyway. What actually gates a merge is CI (the `ci` aggregator job) plus the
maintainer choosing to merge; see `docs/process-notices.md` (N-13) for what
that does and does not buy.

### Required Status Checks

CI must report green before a pull request is merged:

- **Lint** - Code style validation using PHP-CS-Fixer, PHPStan, and Rector,
  plus the knowledge-base linter (`bin/kb-lint.php`)
- **Tests** - PHPUnit tests across multiple PHP (8.2-8.5) and Symfony (6.4-8.0) versions

### Pull Request Requirements

**Required:**
- No approval count required (solo dev project)

**Recommended:**
- All conversations should be resolved before merging
- Branch should be up to date with `master` before merging

## Development Workflow

### Pre-Push Hook

A pre-push git hook is automatically installed via Composer's post-install scripts. It runs `composer lint` before each push to catch issues early.

See [`bin/README.md`](bin/README.md) for details on the hook script.

**To skip the hook** (for emergency pushes):
```bash
git push --no-verify
```

**To manually reinstall the hook**:
```bash
php bin/install-git-hook.php
```

**To remove the hook**:
```bash
rm .git/hooks/pre-push
```

### Before Submitting a PR

1. Run linting locally:
   ```bash
   composer lint
   ```

2. Run tests locally:
   ```bash
   composer test
   ```

   Note: `composer test` boots a real Workerman daemon binding ports **8888**, **9999**
   and **9991** for end-to-end HTTP tests. The port numbers are hardcoded in
   `tests/App/Kernel.php` and cannot be overridden via environment variables.
   The listen **address** can be overridden with `WMB_LISTEN_ADDR`
   (default `127.0.0.1`) — see ["Parallel test runs across git
   worktrees"](#parallel-test-runs-across-git-worktrees).

   To run the suite with code coverage locally, you need a coverage driver such
   as PCOV or Xdebug installed and enabled:
   ```bash
   composer test:coverage
   composer coverage:check
   ```

   The `test:coverage` script writes a Clover report to `var/coverage.xml` and
   `coverage:check` parses it to enforce the line-coverage threshold (**80%**),
   defined once in `composer.json`. CI runs the same check with PCOV on the
   PHP 8.2 / Symfony 6.4 matrix leg, so the gate is reproducible locally.

   > **Troubleshooting "Address already in use"**
   > - Find the process occupying the port: `lsof -i :8888` or `ss -tlnp | grep 8888`
   > - Stop the conflicting service or kill the process (e.g. `kill <PID>`)
   > - If a previous test run was interrupted, a Workerman daemon may still be
   >   running in the background. Stop it manually:
   >   ```bash
   >   php tests/App/index.php stop
   >   ```
   > - To run tests without starting the daemon (you are responsible for starting
   >   it yourself beforehand), run only phpunit:
   >   ```bash
   >   vendor/bin/phpunit
   >   ```
   > - On macOS, ports below 1024 require root. Ports 8888 and 9999 are above
   >   that threshold and should work without special privileges.

   > **Signal-logic tests and pcntl/posix extensions**
   > Three tests in `UtilsTest` (`testReloadSendsSigusr1`,
   > `testDeprecatedRebootTriggersDeprecation`, `testDeprecatedRebootDelegatesToReload`)
   > require the `pcntl` and `posix` PHP extensions. On macOS these extensions are
   > often disabled by default. To skip them locally, set:
   > ```bash
   > export WORKERMAN_ALLOW_PCNTL_SKIP=1
   > ```
   > CI always loads `pcntl` and `posix` and these tests are executed there.
   > A guard test (`testSignalExtensionsAvailable`) will fail if the extensions
   > are missing and the env var is not set.

3. Run benchmarks locally (optional but recommended for performance-related changes):
   ```bash
   composer bench
   ```

   The benchmark suite uses PHPBench and covers the documented hot paths:
   - `RequestConverter::toSymfonyRequest`
   - `ResponseConverter::convert`
   - `MemoryRebootStrategy::shouldReboot`
   - `PeriodicalTrigger::getNextRunDate`
   - `HttpRequestHandler::__invoke` (composed middleware chain)

   Benchmarks run with 1000 revolutions × 5 iterations after a 1-iteration warmup.
   Results are printed as an aggregate report showing memory peak, mode, and
   relative standard deviation per subject.

   > **Interpreting results**
   > - `mode` — the most common execution time (lower is better)
   > - `mem_peak` — peak memory allocated during the benchmark
   > - `rstdev` — relative standard deviation (lower means more stable results)
   >
   > When making performance changes, compare the before/after mode values for
   > the relevant benchmark subjects. A regression is suspected when mode
   > increases by more than 5 % on the same hardware.

4. Ensure all checks pass before pushing

5. Update CHANGELOG.md:
   - Add entry under `[Unreleased]` section
   - Follow [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format
   - Include issue number (e.g., `(#65)`)
   - Use appropriate section: `Added`, `Changed`, `Fixed`, `Removed`, or `Deprecated`

### Running tests in Docker (no local PHP needed)

If installing PHP 8.2 with `pcntl`, `posix`, `inotify` and a coverage driver
locally is impractical (notably on macOS, where `pcntl`/`posix` are disabled
by default), run the full suite in the bundled Docker image instead. The
image is built on `php:8.2-cli-bookworm` and mirrors the most restrictive CI
leg (PHP 8.2 + Symfony 6.4, PCOV coverage), so a green local Docker run
reproduces the CI gate that blocks a merge.

**Build the image:**

```bash
docker build -t workerman-bundle-test .
```

On **Linux**, pass your UID/GID so bind-mounted `var/` and `.git` stay
writable by your host user:

```bash
docker build --build-arg APP_UID=$(id -u) --build-arg APP_GID=$(id -g) \
  -t workerman-bundle-test .
```

macOS and Windows Docker Desktop users can keep the default `1000/1000` —
Docker Desktop's bind mounts do not honour UIDs, so the caveat does not apply.

**Run the suite** (bind-mount the working tree; persist deps and artifacts in
named volumes so they survive between runs):

```bash
docker run --rm -v "$PWD":/app -v wmb-vendor:/app/vendor -v wmb-var:/app/var \
  workerman-bundle-test composer test
```

Coverage check and lint run the same way:

```bash
docker run --rm -v "$PWD":/app -v wmb-vendor:/app/vendor -v wmb-var:/app/var \
  workerman-bundle-test composer test:coverage
docker run --rm -v "$PWD":/app -v wmb-vendor:/app/vendor -v wmb-var:/app/var \
  workerman-bundle-test composer coverage:check
docker run --rm -v "$PWD":/app -v wmb-vendor:/app/vendor -v wmb-var:/app/var \
  workerman-bundle-test composer lint
```

A `bin/docker-test` helper wraps the bind-mount run, building the image on
first use:

```bash
bin/docker-test                    # composer test
bin/docker-test test:coverage      # composer test:coverage
bin/docker-test coverage:check     # composer coverage:check
bin/docker-test lint               # composer lint
```

The test daemon binds ports **8888**, **9999** and **9991** inside the
container on `127.0.0.1` — no `-p` port forwarding is needed. The `@requires
extension inotify` tests use the container's own `/tmp`, not the bind mount,
so they work as on CI.

#### Parallel test runs across git worktrees

Because the daemon and phpunit run **inside the same container**, each
container gets its own network namespace — its own loopback. `127.0.0.1:8888`
in container A is a different stack from `127.0.0.1:8888` in container B, so
N parallel worktree containers are collision-free by construction: no `-p`
publishing, no code change. Build the image once (it is source-agnostic;
source is bind-mounted) and run one container per worktree:

```bash
bin/docker-test-worktree ../wmb-feature
bin/docker-test-worktree ../wmb-bugfix      # run these concurrently,
bin/docker-test-worktree ../wmb-refactor    # no port clashes possible
```

Two rules keep parallel runs safe:

- **Never share `var/` across containers** — including the `wmb-var` named
  volume from the single-checkout workflow above. `var/run/workerman.pid`,
  `var/dispatch_count` and the process markers would collide or corrupt.
  Each worktree's `var/` lives inside its own bind mount; do not add a shared
  var volume to a parallel run.
- **One `vendor/` volume per worktree.** The helper derives
  `wmb-vendor-<worktree-basename>` per checkout, so a branch that bumps
  Symfony cannot corrupt another worktree's dependencies. Remove stale ones
  with `docker volume rm wmb-vendor-<name>`.

Container names (`--name wmb-<worktree-basename>`) are likewise unique per
worktree, so concurrent runs never fight over a name.

To `curl` a worktree's test server from the host for manual debugging, use
the ephemeral publish flow: `bin/docker-test-worktree <path> --publish`
starts **only the daemon** in a detached container with
`-p 127.0.0.1::8888 -p 127.0.0.1::9999 -p 127.0.0.1::9991`, so Docker assigns
each container a random free host port — guaranteed unique even with several
published containers running at once — prints the `docker port` mappings and
blocks until you press Ctrl-C (which stops the daemon). Point curl at the
printed host ports while it runs; the test suite is not executed in this mode.
This requires the daemon to accept connections on the bridge interface, so the
helper also sets `WMB_LISTEN_ADDR=0.0.0.0`; `tests/App/Kernel.php` reads it
and falls back to `127.0.0.1` when unset, which keeps plain local runs and
non-publish Docker runs byte-identical to before. Port **numbers** stay fixed
— container network isolation removes any need to change them.

Running many containers at once multiplies CPU/memory use; on constrained
hosts cap it per container with e.g. `--cpus`/`--memory` via a plain
`docker run` invocation of your own.

### CI Configuration

The CI workflow (`.github/workflows/tests.yaml`) runs on every pull request, on every push to `master`, on a weekly schedule (Monday 05:23 UTC), and on demand via `workflow_dispatch`:

- **Lint job**: Validates `composer.json`, runs the security audit (`composer audit`), and checks code style. The audit is the main value of the scheduled run: it catches advisories published after a merge and dependency drift in the unpinned Symfony ranges
- **Tests job**: Runs PHPUnit tests across the supported PHP (8.2–8.5) and Symfony (6.4–8.0) version matrix; the PHP 8.2 / Symfony 6.4 leg also enforces the line-coverage threshold (80%, defined in `composer.json` → `coverage:check`). Scheduled runs execute only the PHP 8.2 / Symfony 6.4 leg
- **Benchmark job**: Runs the PHPBench suite in advisory mode (results are logged but do not block merge); skipped on scheduled runs
- **CI job**: Aggregator that fails unless the Lint and Tests jobs succeeded (benchmark stays advisory); on a failing scheduled run it opens a "Scheduled CI run failed" issue (or comments on the existing one) so the failure is visible without manual monitoring. Superseded pull-request runs are cancelled, but a `master` run is never cancelled by a later one; see `docs/workflow.md` for the full CI layout

## Code Standards

- PHP 8.2+ syntax
- Follow PSR-12 coding standards (enforced by PHP-CS-Fixer)
- Static analysis with PHPStan level 8
- Automated refactoring with Rector

## Deprecation Policy

- Every new deprecation must state a concrete removal version — never an
  open-ended "next major release", which is ambiguous while the bundle is at
  0.x (SemVer gives no compatibility guarantee there). The project's removal
  target for the deprecations currently carried is **1.0** (see
  [UPGRADE.md#deprecations](UPGRADE.md#deprecations)).
- A deprecation older than **six minors** must either be removed or be
  re-justified in writing (records the `since` version and why it still
  stands) before the list grows further. This keeps the deprecation table
  from accumulating indefinitely.

## Reporting Issues

Please use GitHub Issues to report bugs or request features. Include:

- Clear description of the problem
- Steps to reproduce
- Expected vs actual behavior
- PHP and Symfony versions
