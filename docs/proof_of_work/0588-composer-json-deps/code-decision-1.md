# Code Decision 1 — Issue #588: composer.json dependency hygiene

## Approach taken

**Manifest-only fix, exactly the issue's "What to change" minus the follow-up
tooling.** Every claim in the issue was re-verified with grep before editing;
two counts came back slightly different (see "Unsure" / findings-coder.md F-1,
F-2) but neither changed the fix.

### What changed

**`composer.json` (`require`)**
- Removed `"league/mime-type-detection": "^1.13"` — zero references in
  `src/` (case-insensitive grep for `MimeType|league` is empty), the only
  `tests/` hits are `Symfony\Component\Mime\MimeTypes` (a different,
  test-guarded package), no `src/Phar/*` or `resources/*` references, no
  installed package requires it (`installed.json` scan is empty), and
  `git log -S` shows only the Init commit.
- Added `symfony/http-foundation` and `symfony/event-dispatcher` at
  `^6.4|^7.0|^8.0` — the exact constraint style of every other `symfony/*`
  entry in the file (config, console, dependency-injection, http-kernel,
  runtime). Entries kept alphabetically sorted (`sort-packages: true`):
  config, console, deprecation-contracts, dependency-injection,
  event-dispatcher, http-foundation, http-kernel, runtime.
- Added `symfony/deprecation-contracts` at `^2.5|^3.0` — the constraint the
  issue prescribes. Verified against reality: sibling packages in the
  installed tree (console/event-dispatcher/http-kernel v8.1.0) require
  `^2.5|^3`, which is semantically identical to `^2.5|^3.0`; the three-part
  form matches this file's house style.

**`composer.json` (`suggest`)**
- Appended `"ext-zip": "For workerman:build:bin (unpacking the downloaded
  SFX archive)"`. `SfxDownloader::openArchive()` (`src/Phar/SfxDownloader.php:429-436`)
  uses `\ZipArchive` behind a `class_exists()` guard, so `suggest` (not
  `require`) is correct; CI already installs the extension. Appended at the
  end rather than re-sorting — the existing `suggest` block is not
  alphabetical, so a minimal append keeps the diff to one line.

**`.github/workflows/tests.yaml` (both `Update Symfony constraints` steps)**
- The matrix `sed` rewrote *every* `"symfony/*"` constraint to the leg
  version (`6.4.*`/`7.4.*`/`8.0.*`). `symfony/deprecation-contracts` ships
  only 2.x/3.x (verified via `composer show --all`: no 6.x/7.x/8.x line
  exists), so the old `sed` would have rewritten the new line to a
  non-existent version and broken dependency resolution on **all nine**
  matrix legs. The step now excludes that package via a `/…/!` address and
  carries a two-line comment explaining why (#588 reference included).

**`CHANGELOG.md`**
- One entry under `[Unreleased]` → `### Changed`, with the `[#588]` link in
  prose (outside inline code) per `bin/check-changelog.php` rule 4.

### Verification
- `composer validate --strict` → exit 0 (after `composer update --lock`
  refreshed the local gitignored lock hash; no `composer.lock` is committed).
- New `sed` simulated for all three legs (BSD `sed` locally, so
  `[[:space:]]` stood in for GNU `\s`; the address logic is identical):
  every `symfony/*` line pins to the leg version except
  `symfony/deprecation-contracts`, which keeps `^2.5|^3.0`.
- `composer update --dry-run` (no scripts/plugins): default constraints plus
  all three rewritten legs — all resolve (see commit message trailer / CI).
- Unit subset without the daemon: `ChangelogStructureTest`,
  `GithubWorkflowsTest`, `CoverageCiGateTest` — green (these pin the files
  touched here; the full `composer test` boots the Workerman daemon and is
  left to CI).

## What I rejected and why

1. **Leaving `tests.yaml` untouched.** Tempting ("composer.json-only diff"),
   but provably wrong: the rewritten `6.4.*` constraint would match no
   published `symfony/deprecation-contracts` version, failing `composer
   install` on every matrix leg. The issue's own acceptance criteria flag
   this; the two-line `sed` guard is part of the smallest *correct* change.
2. **Pinning the `sed` to constraints starting with `^6.4` instead of
   excluding one package.** More brittle: a future bump of any component to
   `^7.0|^8.0`-only would silently drop it from the matrix pin. An explicit
   exclusion with a comment fails visibly (a second non-tracking package
   breaks resolution loudly) rather than silently.
3. **Also declaring `symfony/event-dispatcher-contracts` and
   `symfony/service-contracts`.** Strictly, 7 files import
   `Symfony\Contracts\EventDispatcher\*` and 1 imports
   `Symfony\Contracts\Service\ResetInterface`, and those classes live in the
   `-contracts` packages, not `symfony/event-dispatcher`. Rejected for this
   round: the issue prescribes exactly three packages, and both contracts
   packages resolve reliably (`event-dispatcher-contracts` is a hard require
   of `symfony/event-dispatcher`; `service-contracts` comes via
   dependency-injection). Logged as findings-coder.md F-2 for the
   require-checker follow-up, which is the right tool to settle it.
4. **Moving `symfony/console` to `suggest`.** The issue marks this a judgement
   call ("verify before changing"). Three command classes plus
   `SfxSourceResolver` import console classes unconditionally, so `require`
   is correct today; a Runtime-without-console split is a design change, not
   hygiene. No change.
5. **Adding the `composer-require-checker` CI step / `Utils::reboot()`
   deprecation test in this round.** Both are in the issue's acceptance
   criteria but are new tooling / new tests, not the manifest fix; bundled
   here they would bloat review. Proposed as follow-ups in the report.

## Anything I was unsure about

- **http-foundation count: 10 files, not the issue's 9.** The extra file is
  one of the newer response-path classes (`BinaryFileResponseReflector` /
  `StreamedBinaryFileResponse`); the issue's audit predates them. Only the
  count was stale — the conclusion (declare it) is unchanged.
- **`^2.5|^3.0` vs siblings' `^2.5|^3`.** Chose the issue's spelling; the
  two are semantically identical (`^3` ≡ `^3.0`), and the three-part form
  matches this repo's `^6.4|^7.0|^8.0` style.
- **Whether `suggest` ordering matters to `composer validate --strict`.** It
  does not (validate is green); `sort-packages` does not enforce `suggest`
  order (the pre-existing block was already unsorted).
