# Review — issue #680, round 1

- Repo: `crazy-goat/workerman-bundle`
- Branch: `refactor/issue-680-configurationtreebuilder-serve-files-roo`
- Base: `master` (`git diff master...HEAD`)
- Round: 1 (no prior `findings-review.md` existed)

## 1. What the diff contains

Only two functional lines, both `info()` strings, plus two new proof-of-work
docs:

- `src/DependencyInjection/ConfigurationTreeBuilder.php:135` — `serve_files`
  info
- `src/DependencyInjection/ConfigurationTreeBuilder.php:140` — `root_dir`
  info
- `docs/proof_of_work/0680-deprecated-config-info-text/{code-decision-1,findings-coder}.md`

`git show cc57d00 -- src/DependencyInjection/ConfigurationTreeBuilder.php`
confirms the `setDeprecated()`, `defaultFalse()`, `defaultNull()` and node
types are byte-identical to `master` — the diff is `info()` only.

## 2. Gates (all green, run this round)

| Gate | Result |
| --- | --- |
| `php-cs-fixer fix --dry-run` | 0 of 259 files fixable (PSR-12 / project rules OK) |
| `phpstan` (level 8, `phpstan.neon.dist`) | `[OK] No errors` |
| `rector process --dry-run` | `[OK] Rector is done!` |
| `php bin/kb-lint.php` | OK — 59 entries, 0 stale (2 pre-existing line-budget warnings) |
| `php bin/check-changelog.php` | OK (structural) |
| `php bin/check-exception-usage.php` | OK |
| `phpunit tests/DependencyInjection/ConfigurationTreeBuilderTest.php` | OK (13 tests, 51 assertions) |

No `.github/workflows/*` file is touched, so the FAQ-032 multi-pin sweep does
not apply here.

## 3. Earlier findings (adjudication first)

`docs/proof_of_work/0680-deprecated-config-info-text/findings-review.md` did
not exist before this round, so there is nothing from previous review rounds to
adjudicate. The coder's own `findings-coder.md` items are adjudicated in §6.

## 4. The specific questions asked

### 4.1 Type-accurate, distinct, deprecation + replacement mentioned?

| Node | Type | New text | Type-accurate | Distinct | "Deprecated" | Middleware |
| --- | --- | --- | --- | --- | --- | --- |
| `serve_files:135` | `booleanNode` | "Deprecated boolean switch for the legacy static file serving path configured via root_dir. Configure a StaticFilesMiddleware service instead." | yes ("boolean switch") | yes | yes | yes |
| `root_dir:140` | `scalarNode` | "Deprecated path to the public directory served by the legacy serve_files static file serving path. Configure a StaticFilesMiddleware service instead." | yes ("path") | yes | yes | yes |

The old shared string ("Should current worker serve files from public
directory") is gone from both nodes. Both new strings satisfy the issue's
acceptance criteria (type-appropriate description, deprecation, replacement).
They align with the node's `setDeprecated()` message ("Use the
StaticFilesMiddleware instead", removal in 1.0) — the info is an intentionally
shorter summary, the removal mechanics stay on `setDeprecated()`, which is the
Symfony convention. `root_dir` still carries `->example('%kernel.project_dir%/public')`,
which reinforces the "path" reading.

### 4.2 Sibling `static_files` node style

`static_files:150` says: *"Security configuration for **the deprecated**
serve_files/root_dir static file serving path. A StaticFilesMiddleware
**registered as a service** takes …"*. The new strings lead with *"Deprecated
…"* and say *"Configure a StaticFilesMiddleware service instead."* Substance
matches; the sentence construction differs mildly. This is a style nit only —
see F3.

### 4.3 Any unintended `setDeprecated()` / default / type change?

No. Diff is two `info()` lines; `setDeprecated()`, `defaultFalse()`,
`defaultNull()`, `booleanNode`/`scalarNode`, `->example()` are untouched.

### 4.4 Do any tests pin these info strings?

No. Evidence:

- `grep -rn "Should current worker" tests/` → no hits (old text not pinned).
- `grep -rln "dump-reference\|DumpReference\|ReferenceDumper" tests/` → no hits.
- `find tests -iname '*snapshot*' -o -iname '*reference*'` → no snapshot dir.
- `grep -rn "info\(|getInfo\(|assert.*info" tests/` → no config-node info
  assertions.
- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:256`
  (`testConfiguredTreeDeprecatesLegacyStaticFileNodes`) is the only test over
  these nodes and asserts only the `setDeprecated()` message substring
  (`Will be removed in 1.0`), not `info()`.

The only other occurrence of the old text in the working tree is in the
generated, git-ignored `var/cache/{dev,test}/Symfony/Config/Workerman/ServersConfig.php`
(`git ls-files` → untracked; `var/` is in `.gitignore`). No committed stale
copy exists.

Verdict: acceptable. `info()` is documentation rendered by
`config:dump-reference`; there is no existing convention of pinning `info()`
in this repo, and the issue only asked for the text to be fixed. See F4 for the
optional guard.

### 4.5 PHPStan / php-cs-fixer / PSR-12

All pass (§2). The new lines are long (~180-200 chars), but the file already
has many `info()` lines of that length (e.g. lines 130, 137, 141, 150, 155),
php-cs-fixer enforces no line-length rule here, and neither `rector` nor
`phpstan` complains. Not a finding.

## 5. New findings

**F1 — low — `CHANGELOG.md` [Unreleased] not updated**
`docs/workflow.md:362-370` (step 8) requires an `[Unreleased]` changelog entry
with the issue reference. Every recent implementation commit in this repo does
so, including doc-only changes:
`cc57d00` (this change) touches no `CHANGELOG.md`; `c932d77`, `b67ef8f`,
`dde5076`, `669b131`, `bcaca12` each add one. Precedent for a docs-string-only
change: the #594 docblock-only change got an `### Changed` entry
(`CHANGELOG.md:42`). `check-changelog.php` validates structure only, so nothing
automated catches the omission. Fix: add a short `### Changed` entry under
`[Unreleased]` referencing #680.
Which check should have caught it: none today; a presence check is hard to
automate (see §7).

**F2 — nit — `root_dir` info phrasing is ambiguous (`ConfigurationTreeBuilder.php:140`)**
"…served by the legacy `serve_files` static file serving path" reads as though
`serve_files` were the path. `serve_files` is a boolean switch; `root_dir` is
the path. Clearer: "…served by the legacy static file serving path enabled by
`serve_files`." Same meaning, no behavior impact.

**F3 — nit — style mismatch with the sibling `static_files` info (`ConfigurationTreeBuilder.php:150`)**
The sibling uses "the deprecated serve_files/root_dir static file serving path"
(article + adjective, both node names). The new texts lead with "Deprecated …"
and name only one sibling each. Cosmetic; consistency only.

**F4 — nit — nothing guards `info()`/`setDeprecated()` drift (`ConfigurationTreeBuilder.php:135,140`)**
The coder's own `findings-coder.md` #1. Real but acceptable for this issue:
`info()` is prose, and a verbatim string-pin is brittle. If a guard is wanted,
the coder's suggestion is the right shape — a reflective walk over the three
legacy static-file nodes asserting each `info()` is non-empty and contains
"deprecat" (case-insensitive) — but it should be a deliberate follow-up, not
scope-creep for #680. Documented by FAQ-024 for `setDeprecated()` semantics;
the `info()` half is not covered by any entry (see §7).

**F5 — nit — no test fails if the two strings regress to identical text (`tests/DependencyInjection/ConfigurationTreeBuilderTest.php:256`)**
The issue's core defect was the two nodes sharing one wrong string. Nothing now
asserts they differ. A one-line assertion
(`assertNotSame($serveFilesInfo, $rootDirInfo)`) would lock the regression in,
but it is a documentation string; acceptable to leave to the same follow-up as
F4.

## 6. Adjudication of `findings-coder.md`

1. *`info()` and `setDeprecated()` can silently drift* — **still present**, by
   design of the change; not a defect of this diff. Mirrored as F4 (nit).
2. *`serve_files`/`root_dir` independently settable, no validation* —
   **still present, out of scope**. Pre-existing; the diff does not touch
   validation and the issue is string-only. The coder's caveat (verify runtime
   before changing) is correct. Not a finding against this diff.
3. *Long `info()` lines have no enforced width* — **not a real finding**;
   consistent with the file, no linter rule violated.

## 7. Proposed `docs/helpers/` candidates (for the retro step; not written here)

- **FAQ candidate** — title: *"`info()` prose on a config node is not pinned by any test — deprecation wording can drift from `setDeprecated()`"*. Tags: `config`, `deprecation`, `tests`, `symfony-config`. Trigger: "editing an `info()` string on a Symfony config node". One paragraph: `info()` is rendered by `config:dump-reference` and asserted by nothing in this repo (no snapshot/reference test; `ConfigurationTreeBuilderTest` checks only the `setDeprecated()` message). A node can therefore lose its deprecation/migration hint silently. The #680 fix gave `serve_files`/`root_dir` type-accurate, deprecation-aware strings distinct from the sibling `static_files` node, but no guard links them back to `setDeprecated()`; the proposed reflective "info contains 'deprecat'" test is the intended future guard.

No `decisions.md` candidate: this diff makes no architectural decision.

## 8. Verdict

No high or medium findings. The acceptance criteria of #680 are met: both
`info()` texts are type-accurate, distinct, mention the 0.9.3 deprecation and
the `StaticFilesMiddleware` replacement, and no other node property changed.
All lint/type gates and the config test suite pass. Remaining items are one low
process gap (F1, CHANGELOG) and cosmetic nits (F2–F5).
