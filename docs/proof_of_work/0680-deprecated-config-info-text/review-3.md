# Review — issue #680, round 3

- Repo: `crazy-goat/workerman-bundle`
- Branch: `refactor/issue-680-configurationtreebuilder-serve-files-roo`
- Base: `master` (`git diff master...HEAD`)
- Round: 3 (final expected)
- Reviewed commit: `159f824` (range `cc57d00`..`159f824`)

## 1. What changed since round 2

`git show HEAD --stat` (commit `159f824`, *"docs: record review round 2 for
#680; widen info-text guard"*):

- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php` ±69 — the single
  round-2 guard is split into two tests and widened:
  - `testDeprecatedStaticFileNodeInfoTextsAreDistinct` (`:304-313`) — keeps
    `assertNotSame` on `serve_files` vs `root_dir`.
  - `testDeprecatedStaticFileNodeInfoNamesDeprecationAndReplacement`
    (`:321-335`) — walks `legacyStaticFileNodeInfos()` over all three legacy
    nodes and asserts each `info()` contains `deprecat` (ignoring case) **and**
    `StaticFilesMiddleware`.
  - `legacyStaticFileNodeInfos()` (`:340-365`) — new private helper owning the
    tree navigation; returns `array<string, string>`.
- `docs/proof_of_work/0680-deprecated-config-info-text/{findings-review.md,
  review-2.md, code-decision-3.md}` — proof-of-work docs only.

Source and `CHANGELOG.md` are byte-identical to round 2: `git diff
64c67d3..HEAD -- src/ CHANGELOG.md` is empty (`ConfigurationTreeBuilder.php:135`
and `:140` still carry the round-2 wording). No `.github/workflows/*` file is
touched, so the FAQ-032 multi-pin sweep does not apply.

## 2. Gates (run this round)

| Gate | Result |
| --- | --- |
| `phpunit tests/DependencyInjection/ConfigurationTreeBuilderTest.php` | OK — 15 tests, 72 assertions |
| `phpunit tests/DependencyInjection` | OK — 60 tests, 202 assertions |
| `phpstan analyse` (level 8, `phpstan.neon.dist`) | `[OK] No errors` |
| `php-cs-fixer fix --dry-run` | 0 of 259 files fixable |
| `rector process --dry-run` | `[OK] Rector is done!` |
| `php bin/kb-lint.php` | OK — 59 entries, 0 stale (2 pre-existing line-budget warnings) |
| `php bin/check-changelog.php` | OK — structurally valid |
| `php bin/check-exception-usage.php` | OK |

No gate was lowered.

## 3. Adjudication of every earlier finding (required first)

### Round 1

- **F1 (low) — `CHANGELOG.md` `[Unreleased]` missing → `fixed`, still fixed.**
  `CHANGELOG.md:12-18` under `## [Unreleased]` → `### Changed` names #680 and
  the distinct/deprecation-aware texts plus the regression test; the new
  sentence "A regression test pins the two texts as distinct and
  deprecation-aware" remains accurate for the split tests. `check-changelog.php`
  OK; DEC-021 respected (Unreleased only).
- **F2 (nit) — `root_dir` phrasing ambiguous → `fixed`, still fixed.**
  `ConfigurationTreeBuilder.php:140` reads "Deprecated path to the public
  directory served when the legacy serve_files switch is enabled…"; `:135`
  calls `serve_files` a "boolean switch". No ambiguity.
- **F3 (nit) — style mismatch with sibling `static_files` info →
  `still present — accepted non-fix`, not a real finding.** `:135`/`:140` lead
  with "Deprecated" while `:150` buries it mid-sentence. `code-decision-2.md`
  and `code-decision-3.md` leave this deliberately; the rationale (the first
  words are what a `config:dump-reference` skimmer reads) is sound and there is
  no behavioural or test impact. Nothing changed this round; I do not re-open
  it.
- **F4 (nit) — nothing links `info()` to the deprecation → `fixed`, still
  fixed (now across the whole class).** `testDeprecatedStaticFileNodeInfoNames
  DeprecationAndReplacement` (`:321-335`) asserts both signals on every legacy
  node, so the residual from round 2 (only two nodes, only "deprecat") is
  closed. Mutation-verified below.
- **F5 (nit) — no test fails on identical texts → `fixed`, still fixed.**
  `assertNotSame` at `:308-312`. Mutation M4 (re-merging the two `info()`
  strings in a scratch copy) fails the suite: *"serve_files and root_dir must
  not share identical info() text"*.

### Round 2

- **F6 (nit) — guard narrower than the defect class → `fixed`.**
  `tests/.../ConfigurationTreeBuilderTest.php:321-365`. The helper resolves
  `serve_files`, `root_dir` **and** `static_files`
  (`ConfigurationTreeBuilder.php:134/139/149`), each narrowed to `BaseNode` via
  `assertInstanceOf` with a `missing config node %s` message for the `?? null`
  branch, and the test asserts `deprecat` (case-insensitive) plus a
  case-sensitive `StaticFilesMiddleware` on each. Verified by mutation in an
  out-of-tree copy (`/private/var/folders/.../opencode/680r3`, rsync of the
  repo sans `.git`/`var`):

  | Mutation | Result |
  | --- | --- |
  | M1 remove `StaticFilesMiddleware` from `serve_files` info (`:135`) | **FAIL** — "serve_files info() must name the StaticFilesMiddleware replacement" |
  | M2 remove `StaticFilesMiddleware` from `static_files` info (`:150`) | **FAIL** — "static_files info() must name the StaticFilesMiddleware replacement" |
  | M3 remove "deprecated" from `static_files` info (`:150`) | **FAIL** — "static_files info() must name the deprecation" |
  | M4 re-merge `serve_files`/`root_dir` info texts | **FAIL** — "serve_files and root_dir must not share identical info() text" |
  | M5 remove `StaticFilesMiddleware` from `root_dir` info (`:140`) | **FAIL** — "root_dir info() must name the StaticFilesMiddleware replacement" |

  The copy was restored to an md5 identical to HEAD after each mutation
  (`8294b06e…`), and the in-repo working tree is clean at `159f824`. The
  requested check — dropping `StaticFilesMiddleware` from `static_files` (and
  from `serve_files`) must go red — holds.

### Coder findings (`findings-coder.md`)

- **#1 (`info()`/`setDeprecated()` drift)** — `fixed` for the whole class: all
  three legacy nodes are now gated on both the deprecation and the replacement
  keyword. No residual.
- **#2 (`serve_files`/`root_dir` independently settable, no validation)** —
  `not a real finding` against this diff: pre-existing, out of scope for a
  string/test-only change (unchanged from rounds 1–2).
- **#3 (long `info()` lines)** — `not a real finding`: consistent with the
  file, no linter rule violated (unchanged).

## 4. Is the split test correct?

- **Both tests reach the right nodes** through the one shared helper; the
  helper's `assertInstanceOf(BaseNode::class, …, 'missing config node …')`
  fails loudly on a rename/removal instead of dereferencing `null`.
- **PHPStan level 8 clean.** `NodeInterface` does not declare `getInfo()`; the
  `ArrayNode`/`PrototypedArrayNode`/`BaseNode` narrowing is the same pattern
  as the sibling test, and `phpstan analyse` is `[OK] No errors` with no
  ignores.
- **Not brittle.** Only substrings (`deprecat` ignoring case,
  `StaticFilesMiddleware`) and inequality are asserted; a legitimate rewording
  of the sentences stays green, while every loss of the deprecation signal or
  the replacement hint for any of the three nodes turns it red (M1–M5).
- **Naming/placement/PSR-4.** Private `legacyStaticFileNodeInfos()` is not a
  test; the class/path/namespace are unchanged and PSR-4 valid;
  php-cs-fixer/`rector` clean.
- **Considered and dismissed:** distinctness is asserted only for the
  `serve_files`/`root_dir` pair, not "all three infos pairwise distinct". That
  is the literal #680 defect (the two nodes shared one text), `static_files` is
  an array node with unrelated semantics, and any rewrite that made it
  byte-identical to one of the pair would still be caught by human review — not
  raised as a finding.

## 5. New findings (round 3)

None. No high/medium/low/nit finding survives review of the current diff.

## 6. `docs/helpers/` check

TAG INDEX read first, then only matching entries. Tags for this diff: `config`
(FAQ-024, FAQ-035), `deprecation` (FAQ-024, FAQ-029), `static-files` (FAQ-004),
`symfony-config` (FAQ-035), `tests`, `changelog`/`docs` (DEC-021),
`knowledge-base` (DEC-009).

- FAQ-024 (deprecation fires only when the key is present) — the diff keeps
  `setDeprecated()` and `defaultFalse()`/`defaultNull()`/`addDefaultsIfNotSet()`
  untouched; no violation.
- FAQ-035 — not applicable: no `->min()`/`->max()` bound changed.
- FAQ-004 — not applicable: no `StaticFilesMiddleware` path/extension logic
  changed.
- DEC-021 — respected (`[Unreleased]` entry only, no released edits).
- DEC-009 — respected: proposals stay in this report, `docs/helpers/` untouched.

Violations of documented decisions: **none**.

## 7. Proposed `docs/helpers/` candidates (for the retro step)

Carried over from round 1/review-2, refined, since nothing new emerged:

- **FAQ candidate** — title: *"`info()` prose on a Symfony config node is not
  pinned by any test — a keyword guard must cover every node whose deprecation
  signal lives only in `info()`"*. Tags: `config`, `deprecation`, `tests`,
  `symfony-config`. Trigger: "editing an `info()` string on a deprecated Symfony
  config node, or adding a test that guards deprecation wording". One
  paragraph: `info()` is rendered by `config:dump-reference` and asserted by
  nothing else in this repo (no snapshot/reference test;
  `ConfigurationTreeBuilderTest` checks only the `setDeprecated()` message), so
  a node can silently lose its deprecation/migration hint. #680's fix gave
  `serve_files`/`root_dir` type-accurate, deprecation-aware texts distinct from
  the sibling `static_files` node; review round 2 found the first guard covered
  only two of the three legacy nodes and only the `deprecat` keyword, so
  round 3 widened it to all three nodes and both keywords
  (`StaticFilesMiddleware`). The lesson to record: when a guard is added for a
  defect class, mutate each sentinel in each covered node to confirm the test
  actually goes red — a green test over a too-narrow selector is the trap.

No new `decisions.md` candidate: this diff makes no architectural decision.

## 8. Process note (disclosure)

While preparing the mutation checks I ran one `sed -i` without `cd`-ing into the
scratch copy first, which briefly modified
`src/DependencyInjection/ConfigurationTreeBuilder.php` in the working tree. I
restored it immediately with `git checkout --`, re-verified byte-identity, and
`git status --short` is empty at `159f824`. All subsequent mutations were
applied strictly inside the scratch copy. No commit, push or permanent edit was
made.

## 9. Verdict

**Clean.** F6 is genuinely fixed and mutation-verified: the guard now covers all
three legacy static-file nodes and asserts both the deprecation keyword and the
`StaticFilesMiddleware` replacement hint, while the distinctness assertion still
locks the literal #680 defect. All prior round-1/round-2 findings are `fixed` or
accepted non-fixes; `findings-coder.md` #1 is closed and #2/#3 remain
not-a-finding. All gates pass (PHPStan level 8, php-cs-fixer, rector, kb-lint,
check-changelog, check-exception-usage, 15/15 and 60/60 PHPUnit); no gate was
lowered and no new issue was found.
