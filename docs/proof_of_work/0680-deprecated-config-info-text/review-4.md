# Review — issue #680, round 4 (full-suite GC interaction)

- Repo: `crazy-goat/workerman-bundle`
- Branch: `refactor/issue-680-configurationtreebuilder-serve-files-roo`
- Base: `master` (`git diff master...HEAD`)
- Round: 4 (step 7 full-suite review)
- Reviewed commit: `d8c9643`; code-bearing range since round 3: `159f824..HEAD`

Rounds 1–3 ran targeted tests. Round 4 is the first round with the full
`composer test`; it surfaced the GC interaction recorded by the coder in
`code-decision-4.md` / `findings-review.md` (round-4 entry), mitigated by a new
`tearDown()` in `ConfigurationTreeBuilderTest`.

## 1. What changed since round 3 (code)

`git diff 159f824..HEAD -- src/ tests/ CHANGELOG.md` is a single hunk,
`tests/DependencyInjection/ConfigurationTreeBuilderTest.php +10`:

```php
/**
 * The config trees built here create cyclic node references. Left in the
 * GC root buffer they perturb order-dependent GC assumptions elsewhere in
 * the suite (RebootStrategyTest's gc_collect_cycles count), so drain them.
 */
protected function tearDown(): void
{
    gc_collect_cycles();
}
```

Everything else since round 3 is proof-of-work docs (`code-decision-4.md`,
`review-3.md`, the round-4 appendix the coder appended to
`findings-review.md`). `src/` and `CHANGELOG.md` are byte-identical to round 3
(`git diff 159f824..HEAD -- src/ CHANGELOG.md` empty). No `.github/workflows/*`
file is touched, so the FAQ-032 multi-pin sweep does not apply.

## 2. Gates (run this round, full suite)

| Gate | Result |
| --- | --- |
| `composer test` (full suite: app restart + phpunit) | **OK — 2763 tests, 18502 assertions** (exit 0; 4 Deprecations, 42 Skipped) |
| `phpstan analyse` (level 8, `phpstan.neon.dist`) | `[OK] No errors` |
| `php-cs-fixer fix --dry-run` | 0 of 259 files fixable |
| `rector process --dry-run` | `[OK] Rector is done!` |
| `php bin/kb-lint.php` | OK — 59 entries, 0 stale (2 pre-existing line-budget warnings) |
| `php bin/check-changelog.php` | OK — structurally valid |
| `php bin/check-exception-usage.php` | OK |
| `phpunit tests/DependencyInjection/ConfigurationTreeBuilderTest.php` | OK — 15 tests, 72 assertions, 0 deprecations |

No gate was lowered. The `composer test` "OK, but there were issues!" banner is
the 4 suite-wide **deprecations**, not failures; the new/changed test file emits
none (targeted run above: 0 deprecations), and the diff adds no deprecated API
call, so the 4 are pre-existing and not attributable to #680.

## 3. Adjudication of every earlier finding (required first)

Record: `findings-review.md` (rounds 1–3 plus the coder's round-4 entry).

### Round 1

- **F1 (low) — CHANGELOG `[Unreleased]` missing → `fixed`, still fixed.**
  `CHANGELOG.md:12-18` under `## [Unreleased]` → `### Changed` names #680, the
  distinct/deprecation-aware texts and the regression test;
  `php bin/check-changelog.php` OK; DEC-021 respected.
- **F2 (nit) — `root_dir` info ambiguity → `fixed`, still fixed.**
  `ConfigurationTreeBuilder.php:140` "…served when the legacy serve_files switch
  is enabled…"; `:135` "boolean switch". No ambiguity.
- **F3 (nit) — style mismatch with `static_files` info → `still present —
  accepted non-fix`, not a real finding.** `:135`/`:140` lead with "Deprecated"
  while `:150` buries it mid-sentence; `code-decision-2.md`/`-3.md` give a sound
  rationale for a `config:dump-reference` string, no behavioural/test impact.
  Not re-opened.
- **F4 (nit) — no link between `info()` and `setDeprecated()` → `fixed`, still
  fixed across the whole class.** `testDeprecatedStaticFileNodeInfoNames
  DeprecationAndReplacement` (`:321-335`) asserts both keywords on all three
  legacy nodes.
- **F5 (nit) — no test fails on identical texts → `fixed`, still fixed.**
  `assertNotSame` at `:308-312`.

### Round 2

- **F6 (nit) — guard narrower than the defect class → `fixed`, still fixed.**
  `legacyStaticFileNodeInfos()` (`:340-365`) resolves `serve_files`, `root_dir`
  and `static_files` with fail-loud `assertInstanceOf`; the names test asserts
  `deprecat` (case-insensitive) **and** `StaticFilesMiddleware` on each.
  Unchanged this round (byte-identical to `159f824`).

### Coder findings (`findings-coder.md`)

- **#1 (`info()`/`setDeprecated()` drift)** — `fixed` / closed for all three
  legacy nodes (maps to F4/F6).
- **#2 (`serve_files`/`root_dir` independently settable)** — `not a real
  finding` against this diff: pre-existing, out of scope for a string/test-only
  change (unchanged rounds 1–4).
- **#3 (long `info()` lines)** — `not a real finding`: consistent with the file,
  no linter rule violated (unchanged).

### Round 3

- No new findings were recorded; nothing to re-adjudicate.

## 4. The round-4 item: is the `tearDown()` GC drain acceptable?

Coder entry: `findings-review.md:64-69`
(`tests/DependencyInjection/ConfigurationTreeBuilderTest.php` new guard tests +
`tests/RebootStrategyTest.php:292-312`).

**Adjudication: `still mitigated — accepted in scope`, severity `low`.**

- **It is a real interaction, not a phantom.** Before the mitigation, step 7's
  full `composer test` failed deterministically 2/2 at
  `RebootStrategyTest::testMemoryRebootStrategyGcCollectsCyclesWhenTriggered`
  (`assertGreaterThan(0, $collected)` got `0`); reverting
  `ConfigurationTreeBuilderTest.php` to `master` made the full suite pass, and
  replacing the two guard tests with trivial assertions also passed. The coder's
  diagnosis (leftover cyclic config-tree roots shift when PHP's automatic GC
  fires during RebootStrategyTest's 10 000-cycle construction, so the *explicit*
  `gc_collect_cycles()` finds nothing to collect) is consistent with the
  evidence, though it is inherently order-sensitive: I could **not** reproduce
  it with an isolated two-file run (`ConfigurationTreeBuilderTest` then
  `RebootStrategyTest`) either with or without `tearDown()` — it needs the
  surrounding suite state, which is exactly why rounds 1–3's targeted runs
  missed it.
- **The mitigation is sufficient for this PR.** `gc_collect_cycles()` performs a
  full collection and empties the root buffer, so after this class the suite is
  in the same GC state as if the class had not run; that holds across PHP
  versions (CI legs), unlike the timing-dependent original. Two consecutive full
  suite runs pass (coder) and I independently ran the full `composer test` once
  this round: **2763 tests OK**.
- **It is correctly scoped.** The durable fix belongs in `RebootStrategyTest`
  (disable GC while building the garbage, or assert on strategy invocation
  rather than a positive collected count), but that test is unrelated to #680
  and changing it here would broaden this docs/test-string PR into another
  test's logic. `code-decision-4.md` rejects that alternative explicitly and
  records the latent order-dependence as a step-14 candidate issue. I agree with
  keeping it out of this PR — **on condition that the follow-up issue is
  actually filed**, because the latent fragility (a future test that leaves GC
  cycles before `RebootStrategyTest`) can re-surface the red suite.
- **Recommendation:** ship the `tearDown()` as the in-scope mitigation; keep the
  candidate issue and make it a `low`-priority follow-up. If the follow-up is
  ever dropped, the correct home for the fix is `RebootStrategyTest`
  (e.g. `gc_disable()` before the 10 000-cycle loop, `gc_enable()` before the
  explicit collection), not this classes' `tearDown()`.
- **Does it hide a real bug in the code under test? No.** `ConfigurationTreeBuilder`
  is `final readonly` and holds no static/global state (`grep` for
  `static` finds none); the cycles are Symfony's own tree node parent/prototype
  references created per `configure()` call, not any retention by the bundle.
  The tests do `unset` nothing because the tree goes out of scope; refcounting
  cannot break the cycles, so the collector is the correct tool. There is no
  product memory leak being masked.

## 5. `tearDown()` correctness (parent call, PSR/PHPStan)

- **`parent::tearDown()` is not required.** `PHPUnit\Framework\TestCase::tearDown()`
  is empty, so omitting it is a behavioural no-op (the coder's `code-decision-4`
  uncertainty is correct). Repo convention agrees: 36 of 37 `tearDown()` methods
  in `tests/` omit it; only `tests/RequestConverterTest.php` calls it. Not a
  finding.
- **No PSR / PHPStan / cs-fixer issue.** Signature matches the parent
  (`protected function tearDown(): void`), return type present, PHPStan level 8
  and php-cs-fixer clean, no `#[\Override]` requirement (not used elsewhere in
  this file/repo).
- **Placement is fine.** It sits after the private helper it cleans up after and
  runs for all 15 tests in the class; a full `gc_collect_cycles()` per test is
  negligible cost.

## 6. New findings (round 4)

**F7 — low — `tests/DependencyInjection/ConfigurationTreeBuilderTest.php:372-375`
(`tearDown()` GC drain) masks a latent order-dependence in
`tests/RebootStrategyTest.php:292-312`.**
This is the coder's round-4 item, adjudicated in §4: acceptable in-scope
mitigation, but it removes only the *known* trigger. The underlying
`RebootStrategyTest` assumption (`gc_collect_cycles()` must return `> 0` after
building cycles, ignoring whether automatic GC already drained them) remains and
can be re-broken by any later GC-heavy test inserted before it in suite order.
The durable fix is a three-line `gc_disable()`/`gc_enable()` bracket in
`RebootStrategyTest`, filed as a follow-up rather than changed here.
Which automated check could have caught it: a full-suite run with random test
ordering, or a `gc_disable()` bracket in the test itself; the targeted runs used
by rounds 1–3 structurally could not. No gate is lowered.

No other new finding: the diff since round 3 is one `tearDown()` method plus
docs; imports, node navigation and assertions are unchanged and clean.

## 7. `docs/helpers/` check

TAG INDEX read first; tags for this diff: `config` (FAQ-024, FAQ-035),
`deprecation` (FAQ-024, FAQ-029), `static-files` (FAQ-004), `gc`/`memory`/`tests`
(FAQ-023), `symfony-config` (FAQ-035), `changelog`/`docs` (DEC-021),
`knowledge-base` (DEC-009).

- FAQ-024 (deprecation fires only when the key is present) — `setDeprecated()`
  and the defaults are untouched; no violation.
- FAQ-023 (GC cycles from closures) — adjacent but not violated; it is about
  closure cycles in `src/`, not the config-tree test. This round exposes the
  missing lesson (test-level GC order-dependence) — candidate below.
- FAQ-035 — not applicable: no `->min()`/`->max()` bound changed.
- FAQ-004 — not applicable: no `StaticFilesMiddleware` path/extension logic
  changed.
- DEC-021 — respected (`[Unreleased]` entry only).
- DEC-009 — respected: proposals stay in this report; `docs/helpers/` untouched.

Violations of documented decisions: **none**.

## 8. Proposed `docs/helpers/` candidates (for the retro step)

Carried over from rounds 1–3, refined, plus one new:

1. **FAQ candidate** — *"`info()` prose on a Symfony config node is not pinned
   by any test — a keyword guard must cover every node whose deprecation signal
   lives only in `info()`"*. Tags: `config`, `deprecation`, `tests`,
   `symfony-config`. Trigger: "editing an `info()` string on a deprecated
   Symfony config node, or adding a test that guards deprecation wording". One
   paragraph: `info()` is rendered by `config:dump-reference` and asserted by
   nothing else in this repo; #680's fix gave `serve_files`/`root_dir`
   type-accurate texts distinct from `static_files`, and the final guard walks
   all three legacy nodes asserting both `deprecat` and `StaticFilesMiddleware`.
   Lesson: mutate each sentinel in each covered node to confirm the test goes
   red — a green test over a too-narrow selector is the trap.
2. **NEW FAQ candidate** — *"A test asserting `gc_collect_cycles() > 0` after
   building cyclic garbage is order-dependent; a preceding test's leftover GC
   roots can make PHP's automatic collector drain the cycles first"*. Tags:
   `tests`, `gc`, `memory`. Trigger: "writing or reviewing a test that asserts
   `gc_collect_cycles()` returns a positive count". One paragraph: PHP's
   automatic GC fires when the root buffer fills, so whether an explicit
   `gc_collect_cycles()` returns `> 0` depends on how full the buffer already is
   when the test starts — state left by *earlier tests in the suite*, invisible
   to targeted runs. #680 round 4 hit this: new config-tree tests left cyclic
   Symfony node graphs that tipped `RebootStrategyTest`'s assumption, turning the
   full suite red while the two files passed in isolation. Fixes: bracket the
   garbage construction with `gc_disable()`/`gc_enable()`, or drain cycles in
   the offending test class's `tearDown()`; always run the full suite
   (step 7) before claiming green.

No new `decisions.md` candidate: this diff makes no architectural decision.

## 9. Confirm no earlier finding regressed

Verified against the current tree at `d8c9643`:

- `git diff 159f824..HEAD -- src/ CHANGELOG.md` is empty → F1/F2/F3/F4/F5/F6
  inputs are unchanged since they were judged fixed/accepted.
- DI test passes 15/15 (72 assertions), including the distinctness and
  deprecation/replacement guards; full suite 2763 OK.
- `check-changelog`, `phpstan`, `php-cs-fixer`, `rector`, `kb-lint`,
  `check-exception-usage` all OK; no gate lowered.
- `findings-coder.md` #2/#3 remain not-a-finding; #1 remains closed.

## 10. Verdict

**Clean (PR-ready).** The only code change since round 3 is the `tearDown()`
GC drain, and it is an acceptable, correctly-scoped mitigation for the
full-suite interaction; `composer test` is green (2763 tests). The one new
item, **F7 (low)**, is the latent order-dependence this mitigation covers — it
is not a defect of #680 and should ship as a tracked follow-up issue (durable
fix in `RebootStrategyTest`). `parent::tearDown()` is unnecessary, no
PSR/PHPStan issue exists, and no earlier finding regressed.
