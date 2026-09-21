# Review — issue #680, round 2

- Repo: `crazy-goat/workerman-bundle`
- Branch: `refactor/issue-680-configurationtreebuilder-serve-files-roo`
- Base: `master` (`git diff master...HEAD`)
- Round: 2
- Reviewed commit: `64c67d3` (round-1 commit `cc57d00` included in the range)

## 1. What changed since round 1

`git diff master...HEAD --stat`:

- `CHANGELOG.md` +8 — `### Changed` entry under `[Unreleased]` (#680).
- `src/DependencyInjection/ConfigurationTreeBuilder.php` ±2 — only the two
  `info()` strings at `:135` (`serve_files`) and `:140` (`root_dir`).
- `tests/DependencyInjection/ConfigurationTreeBuilderTest.php` +49 — new
  `testDeprecatedStaticFileNodeInfoTextsAreDistinctAndMentionDeprecation`
  (`:306-341`).
- `docs/proof_of_work/0680-deprecated-config-info-text/*` — proof-of-work docs.

No `.github/workflows/*` file is touched, so the FAQ-032 multi-pin sweep does
not apply.

## 2. Gates (run this round)

| Gate | Result |
| --- | --- |
| `php-cs-fixer fix --dry-run` | 0 of 259 files fixable |
| `phpstan analyse` (level 8, `phpstan.neon.dist`) | `[OK] No errors` |
| `rector process --dry-run` | `[OK] Rector is done!` |
| `php bin/kb-lint.php` | OK — 59 entries, 0 stale, 2 pre-existing line-budget warnings |
| `php bin/check-changelog.php` | OK — structurally valid |
| `php bin/check-exception-usage.php` | OK |
| `phpunit tests/DependencyInjection/ConfigurationTreeBuilderTest.php` | OK — 14 tests, 62 assertions |

No gate was lowered.

## 3. Adjudication of round-1 findings (first, as required)

| # | Finding | Adjudication |
| --- | --- | --- |
| F1 | `CHANGELOG.md` `[Unreleased]` missing | **fixed** |
| F2 | `root_dir` info ambiguous ("served by the legacy serve_files … path") | **fixed** |
| F3 | style mismatch with sibling `static_files` info | **still present — deliberate non-fix accepted** |
| F4 | no guard linking `info()` / deprecation wording | **fixed for the two #680 nodes** (residual → F6) |
| F5 | no test fails on identical texts | **fixed** |

Evidence, line by line:

**F1 — fixed.** `CHANGELOG.md:12-18`, under `## [Unreleased]` → `### Changed`,
names #680 and both dimensions (distinct texts, deprecation-aware, regression
test). `check-changelog.php` passes. DEC-021 (`docs/helpers/decisions.md:344`)
is satisfied: the entry is in `[Unreleased]` and no released entry was edited.

**F2 — fixed.** `ConfigurationTreeBuilder.php:140` now reads *"Deprecated path
to the public directory served when the legacy serve_files switch is enabled.
Configure a StaticFilesMiddleware service instead."* `serve_files` is called a
"switch", not a path; `:135` calls it a "boolean switch … path configured by
root_dir". Both type readings are now correct (boolean vs scalar path).

**F3 — still present; non-fix accepted.** `:150` (`static_files`) keeps its
mid-sentence "the deprecated … path" construction while the two edited texts
lead with "Deprecated". `code-decision-2.md` states the rationale (the first
words are what a `config:dump-reference` skimmer reads). That is a defensible
judgement call for a document string; no behavioural or test impact. A nit does
not require a change when the rationale is sound, so I do not re-open it.

**F4 — fixed for the #680 nodes.** `tests/.../ConfigurationTreeBuilderTest.php:340-341`
asserts `assertStringContainsStringIgnoringCase('deprecat', …)` on each of the
two nodes' `getInfo()`. `getInfo()` returns `?string` and is cast to string, so
a `null` info becomes `''` and still fails the assertion (fail-closed). The
residual gap (third node `static_files`, replacement wording) is recorded as
new F6, not as an unfixed round-1 item.

**F5 — fixed, verified by mutation.** `assertNotSame` at `:335`. I copied the
tree to a scratch directory and ran two mutations:
- re-merge both `info()` strings → test fails: *"Failed asserting that two
  strings are not identical."*
- remove "Deprecated" from `root_dir` info only → test fails: *"…contains
  'deprecat'"*.
Both mutations produce a red suite, so the guard is real.

## 4. Is the new test correct?

- **Reaches the right nodes.** It walks the built tree exactly as the sibling
  test does: `rootNode()` → `ArrayNodeDefinition::getNode(true)` → `ArrayNode`
  → child `servers` (`PrototypedArrayNode`, asserted) → `getPrototype()` →
  `ArrayNode` → `getChildren()['serve_files'|'root_dir']`. `assertArrayHasKey`
  guards both keys, so a renamed/removed node fails loudly rather than
  silently `null`.
- **Type narrowing for PHPStan level 8.** `NodeInterface` does not declare
  `getInfo()`; the test narrows via `ArrayNode`/`PrototypedArrayNode`/`BaseNode`
  `assertInstanceOf` calls, which PHPStan understands. Level 8 passes; there is
  no `@phpstan-ignore`. The `?string` return is handled by the `(string)` cast.
- **No brittle coupling.** It asserts a substring ("deprecat",
  case-insensitive) and inequality, not exact prose, so legitimate rewording
  stays green; only the two regressions of #680 (shared text, lost deprecation
  signal) turn it red (mutation-verified above).
- **Placement/naming/PSR-4.** In `tests/DependencyInjection/`, class
  `ConfigurationTreeBuilderTest` with `Test` namespace/path — unchanged and
  valid; no new use-statement is unused (php-cs-fixer clean).
- **Would fail on the two named regressions:** yes, proven.

## 5. No unintended changes to `setDeprecated()` / defaults / other nodes

`git diff master...HEAD -- src/DependencyInjection/ConfigurationTreeBuilder.php`
filters to exactly two removed and two added `->info(...)` lines. The
`setDeprecated('workerman', '0.9.3', …)` calls (`:137`, `:141`),
`defaultFalse()` (`:136`), `defaultNull()` (`:142`), `->example()` (`:143`),
node types (`booleanNode`/`scalarNode`), and every other node are byte-identical
to `master`. The old shared text survives only in doc comments (the new test's
docblock, review-1/proof docs) — no committed stale copy and no test pins the
old string (`grep "Should current worker"` over tracked files finds it only in
those comments).

## 6. New findings

**F6 — nit — the new guard is narrower than the defect class it locks**
`tests/DependencyInjection/ConfigurationTreeBuilderTest.php:306-341`.
It covers only `serve_files`/`root_dir`. The third deprecated node,
`static_files` (`ConfigurationTreeBuilder.php:150`), whose `info()` also carries
the only in-tree deprecation signal, is not covered; and the assertions check
only "deprecat", not the `StaticFilesMiddleware` replacement hint that is part
of #680's acceptance criteria. A future rewrite could drop either for
`static_files` (or the replacement hint for the two nodes) and this test stays
green. Suggested (optional) shape: `@dataProvider` over the three legacy nodes
asserting non-empty `info()`, contains "deprecat", and (for the #680 pair)
contains "StaticFilesMiddleware". Severity nit: the nodes themselves are
correct today and the issue's core defect is locked. Which check would catch
it: none automated — no `config:dump-reference` snapshot/reference test exists
in the repo, so this is human-review-level only.

No high/medium/low findings; F6 is the only new item and it is a nit.

## 7. `docs/helpers/` check

TAG INDEX read first (`faq.md`, `decisions.md`). Tags matching this diff:
`config` (FAQ-024, FAQ-035), `deprecation` (FAQ-024, FAQ-029),
`static-files` (FAQ-004), `symfony-config` (FAQ-035), `tests` (…),
`changelog`/`docs` (DEC-021), `knowledge-base` (DEC-009).

- FAQ-024 (deprecating a node in the Symfony tree) — the diff keeps the
  `setDeprecated()` calls and their semantics; no violation.
- FAQ-035 (pin both sides of a bound) — not applicable; no bound changed.
- DEC-021 — respected (see F1).
- DEC-009 — respected: proof-of-work docs are under `docs/proof_of_work/`,
  not appended to `docs/helpers/`.

Violations of documented decisions: none.

## 8. Proposed `docs/helpers/` candidates (for the retro step; not written here)

Round 1 already proposed the `info()`-is-not-pinned FAQ candidate. Round 2 adds
no new decision. Optional refinement to the same candidate: the guard that
landed asserts the "deprecat" substring on two nodes but is silent about the
replacement token and about the third legacy node — worth recording that even a
keyword guard can be too narrow, and that "would this test fail?" should be
checked by mutating the string, not just by reading the assertion.

## 9. Verdict

**Clean-with-nits.** F1, F2, F4 and F5 are genuinely fixed; the F3 non-fix
rationale is acceptable. The new test reaches the right nodes, narrows types
for PHPStan level 8, is not brittle, and mutation-verified fails on both named
regressions. No unintended change to `setDeprecated()`, defaults or other
nodes. All gates pass; no gate was lowered. The only new item is F6 (nit,
optional expansion of the guard).
