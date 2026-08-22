# 0676 — Review round 1 (commit e3a9f82)

Scope: bin/docker-test-worktree (new), tests/App/Kernel.php, CONTRIBUTING.md.
Method: full diff read; static bash analysis (`bash -n` clean); dynamic probes
of env-fallback semantics and argument parsing in a sandbox; container probes
of the image's entrypoint and composer behavior. Acceptance criteria 3 and 6
were not executed end-to-end this session — marked UNVERIFIED below.

## Findings

1. **bin/docker-test-worktree:89 — unjustified `COMPOSER_ROOT_VERSION=1.0.0`** (low)
   The helper injects `-e COMPOSER_ROOT_VERSION=1.0.0` on every run. Nothing
   else in the repo does this: CI (.github/workflows/tests.yaml:36 etc.), the
   Dockerfile build (`composer install --no-scripts`) and bin/docker-test all
   run plain `composer install` against this composer.json, which has no
   `version` field and no branch-alias, without needing it. Composer only
   warns about a missing root version in specific non-installed contexts;
   for `composer install` from a lock file it is unnecessary. Setting it
   unconditionally masks any future real version and silently lies to
   dependency resolution about the root package. Divergence from the
   bin/docker-test style it otherwise copies. Automated check that would have
   caught it: diff review of new env vars against existing helper + CI env.

2. **bin/docker-test-worktree:96-107 / CONTRIBUTING.md:237 — `--publish` flow cannot demonstrate AC4** (medium)
   `--publish` runs the whole phpunit suite in the foreground; `composer test`
   stops the daemon as its last step, so by the time `docker port` prints the
   ephemeral mappings nothing listens behind them. The printed mappings are
   dead ends, and they are queried after `--rm` already removed the container
   (Docker does not guarantee the mapping table stays queryable; `|| true`
   hides that). Issue AC4 requires "curl from the host reaches
   127.0.0.1:<assigned-port>/response_test" — impossible with the shipped
   foreground flow; only the issue's manual detached `docker run -d` recipe
   can show it, and CONTRIBUTING.md presents the helper flag as the way to
   "curl a worktree's test server from the host". Coder self-reported the
   race (findings-coder.md obstacle 1) but kept the flag and the doc claim.
   Fix direction: make `--publish` start the daemon detached (skip phpunit or
   add a separate flag), then print mappings while the container lives.

3. **bin/docker-test-worktree:66-80 — basename collisions** (low)
   Two worktrees with equal basenames under different parents share both
   `wmb-<base>` container name and `wmb-vendor-<base>` volume. Docker fails
   loudly on the container-name clash so there is no silent corruption, but
   the error does not point at the cause. Self-reported by coder
   (findings-coder.md bug 5); acceptable for round 1, worth a clearer error.

4. **CONTRIBUTING.md:237-243 — doc overstates publish usefulness** (medium, same root as #2)
   The prose sells `--publish` as the curl-debugging path without noting the
   daemon is stopped when the suite exits. Either fix the helper (#2) or
   document the detached recipe here too.

## Verified-clean areas

- tests/App/Kernel.php:44/51/60 — with WMB_LISTEN_ADDR unset the listen
  string is byte-identical to the parent commit (diff-checked); empty-string
  value falls back to 127.0.0.1 (probed). Test clients still target
  127.0.0.1 in-container, which 0.0.0.0 covers; wait-for-ports.php and
  tests/App/bootstrap.php probe 127.0.0.1 from inside the same container, so
  no other file needs the env var. No behavior change beyond the three lines.
- Argument parsing: relative paths resolve correctly because `cd` runs before
  `pwd`; `-`-prefixed non-path typos die with usage (exit 2); unknown long
  flags die; order-free flags work (probed in sandbox).
- Exit-code propagation: `set +e` around docker run, STATUS captured,
  `exit "$STATUS"` — correct; die paths exit 1/2 as documented.
- CONTRIBUTING.md anchor `#parallel-test-runs-across-git-worktrees` matches
  the heading slug; link at ~line 69 resolves (static check).
- Docs vs helper: volume naming wmb-vendor-<basename>, container naming
  wmb-<basename>, bind-mounted var/, ports list 8888/9999/9991 — all match.
- KB cross-check: FAQ-005 (config-cache ownership) not violated — entrypoint
  chowns volumes before dropping to app, cache written by app user itself.
  DEC-012 respected (no bare angle brackets in new prose). FAQ-031: bin/ is
  lint-scoped; script style matches bin/docker-test conventions.
  DEC-016 not triggered (no security opt-out added).

## Acceptance criteria

1. CONTRIBUTING documents parallel workflow incl. never-share-var/ and
   per-worktree vendor/ — MET.
2. Helper exists, per-worktree volumes + unique --name — MET.
3. Three concurrent worktree runs green, zero conflicts — UNVERIFIED this
   session; coder admits partial verification (one second checkout).
4. --publish ephemeral mappings + WMB_LISTEN_ADDR=0.0.0.0 + host curl works —
   NOT MET as shipped (finding #2): mappings printed after daemon stop, curl
   cannot succeed through the helper path alone.
5. Default 127.0.0.1 byte-identical, plain composer test unchanged — MET
   (verified statically + probed).
6. Two concurrent --publish containers get distinct ports — UNVERIFIED
   (Docker ephemeral guarantee makes it near-certain, but not demonstrated).
7. Ports-note updated to reflect WMB_LISTEN_ADDR — MET (lines 66-70).

## Automated checks that would have caught findings

- Finding 1: grep new `-e` vars in bin/* against CI/Dockerfile env usage.
- Finding 2: shellcheck (does not catch it), but an integration smoke test
  "run helper --publish, then curl within N seconds" fails deterministically.
