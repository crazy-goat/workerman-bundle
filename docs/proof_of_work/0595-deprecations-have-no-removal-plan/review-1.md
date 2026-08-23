# Review — Round 1 (issue #595)

Reviewed commit `cf9a2c4` ("docs: document deprecation removal plan for 1.0 (closes #595)").

## Per-file findings

### src/DependencyInjection/ConfigurationTreeBuilder.php (lines 137, 141, 151)
All three `setDeprecated()` messages now state "Will be removed in 1.0", point at
`docs/security.md#static-files-protection`, and explain the `static_files.allowed_extensions`
allowlist no longer applies once you move to the middleware. Consistent, self-sufficient,
correct. The `since` version (0.9.3) is preserved. No finding.

### src/Http/Request.php (line 112 vs 120)
The `@deprecated` docblock now says "This method will be removed in 1.0." (line 112), but the
runtime `trigger_error(..., E_USER_DEPRECATED)` message (line 120) was NOT updated — it still
reads "Since crazy-goat/workerman-bundle 0.23.0: %s::withHeader() is deprecated, use setHeader()
instead." with no removal version. This is the runtime message users actually see; the other two
deprecations' runtime messages both name 1.0. **Finding F-1 (medium, OPEN).**

### src/Utils.php (lines 72, 76)
Both the `@deprecated` docblock and the `trigger_deprecation()` message now say "Will be removed
in 1.0." Consistent. No finding.

### UPGRADE.md (Deprecations section)
Table lists all three deprecations with since-version, feature, replacement, and removal version
(1.0). The `docs/security.md#static-files-protection` anchor is verified to exist (heading
"## Static Files Protection", line 140). The blockquote correctly notes the
`static_files.allowed_extensions` allowlist no longer applies and the middleware reads from the
`$allowedExtensions` constructor argument — this matches docs/security.md's own note. Markdown
table is well-formed. Placement at the top (before "Upgrading to 0.25") is appropriate for a
forward-looking view. No finding.

### CONTRIBUTING.md (Deprecation Policy)
States every new deprecation must name a concrete removal version (never "next major release"),
and a deprecation older than six minors must be removed or re-justified in writing. Consistent
with the issue's ask ("deprecations state a removal version when introduced; a deprecation older
than N minors is either removed or re-justified"). The six-minor threshold is sensible given the
oldest carried deprecation is fifteen minors old. No contradiction. No finding.

### CHANGELOG.md
Entry under [Unreleased] → Added, follows the repo's Keep-a-Changelog convention, includes the
issue link `[#595](...)`. No finding.

### tests/DependencyInjection/ConfigurationTreeBuilderTest.php
`testConfiguredTreeDeprecatesLegacyStaticFileNodes` + `provideDeprecatedNodes`:
- Data provider yields one deprecated node at a time (`serve_files`, `root_dir`, `static_files`),
  each minimal — matches FAQ-035's "one field per process() call" guidance.
- `set_error_handler` captures `E_USER_DEPRECATED`; `restore_error_handler()` is in a `finally`
  block, so the handler is always restored even if `process()` throws. Well-formed.
- Asserts the deprecation fires (`assertNotNull`) and the message contains "Will be removed in
  1.0". Correct.
- The `static_files` case sets `['allowed_extensions' => ['png']]`, which is the key that makes
  the node present so the deprecation fires (per FAQ-024, `setDeprecated()` fires only when the
  key is present). Correct.
- No flakiness: the handler is per-test, restored in finally. No finding.

### docs/proof_of_work/0595-.../code-decision-1.md and findings-coder.md
Both present and consistent with the committed diff. The coder-findings file documents a
mid-edit subagent output-derailment defect (broken `createDefinitionConfigurator()` helper,
13 errors / 0 assertions) that was recovered before commit; the committed test file has the
correct helper (`$treeBuilder = new TreeBuilder('workerman');` present, line 297) and the test
passes locally (13 tests, 51 assertions). No contradiction with the actual diff. No misleading
claims. No finding.

### Out-of-scope check
No composer.json change. No Utils::reboot() test added (correctly blocked on #588). No deprecated
code removed. The diff touches only the nine expected files. No finding.

## Acceptance-criteria check (issue #595 body)

1. **Every `@deprecated`, `setDeprecated()` and `trigger_deprecation()` in `src/` names a concrete removal version, not "the next major release"** — **NOT MET**. The `@deprecated` docblocks and `setDeprecated()`/`trigger_deprecation()` messages all name 1.0, but the runtime `trigger_error` message in `Request::withHeader()` (src/Http/Request.php:120) still names no version. (The `@deprecated` docblock for withHeader does name 1.0.)
2. **`UPGRADE.md` has a Deprecations section listing all three entries with since-version, replacement, and removal version** — **MET**. Table present with all three rows.
3. **The `serve_files`/`root_dir` deprecation message points at documentation that actually covers the replacement, including the extension allowlist** — **MET**. Points at `docs/security.md#static-files-protection` (anchor verified) and explains the allowlist change.
4. **`Request::withHeader()` and the config keys carry the same removal target as each other, or a documented reason for differing** — **MET** (docblock level). All three target 1.0 in their docblocks; the withHeader runtime message is the gap (see criterion 1).
5. **Each deprecation has a test asserting the notice is emitted, so a silent regression fails CI** — **PARTIALLY MET**. Config nodes: new test asserts the notice fires and contains "Will be removed in 1.0". `Request::withHeader()`: existing `testWithHeaderTriggersDeprecation` asserts the notice fires but not the removal version. `Utils::reboot()`: no test — genuinely blocked on #588 (missing `symfony/deprecation-contracts` declaration), documented in code-decision-1.md.
6. **`CONTRIBUTING.md` states the deprecation policy for new deprecations** — **MET**. Deprecation Policy section added.
7. **If a removal is executed in the same PR: `UPGRADE.md` gains a migration section, and the removal is a `Removed` entry under [Unreleased]** — **MET (vacuously)**. No removal executed in this PR; removal is future work at 1.0.
8. **CHANGELOG.md receives an entry under [Unreleased]** — **MET**. Entry added with issue link.

## Candidate docs/helpers entries (propose only — do not append)

### FAQ candidate
- **title**: "A deprecation's runtime message and its `@deprecated` docblock are two separate strings — update both"
- **tags**: `deprecation`, `config`, `http`
- **trigger**: "changing a deprecation message or removal version in src/"
- **paragraph**: When a deprecation's removal version changes, it must be updated in three independent places: the `@deprecated` docblock, the runtime message (`setDeprecated()` for config nodes, `trigger_deprecation()`/`trigger_error()` for methods), and the UPGRADE.md Deprecations table. In #595 the `Request::withHeader()` docblock was updated to "removed in 1.0" but its `trigger_error` runtime message was left naming no version — the runtime message is what users actually see, and the existing deprecation test only asserted the notice fired, not its content, so the gap passed CI. Grep for all three sites and assert the removal version in the test.

### Decision candidate
- **title**: "Deprecation removal target is 1.0 for all carried deprecations; policy caps accumulation at six minors"
- **tags**: `policy`, `deprecation`
- **trigger**: "adding, removing, or re-justifying a deprecation"
- **paragraph**: The bundle's three carried deprecations (`serve_files`/`root_dir`/`static_files` since 0.9.3, `Utils::reboot()` since 0.17.0, `Request::withHeader()` since 0.23.0) are all scheduled for removal in 1.0 — the next SemVer major. "The next major release" is ambiguous at 0.x (SemVer grants no compatibility guarantee below 1.0), so every deprecation must name a concrete version. CONTRIBUTING.md now requires a fixed removal version on introduction and that a deprecation older than six minors be removed or re-justified in writing. The `serve_files` removal is deliberately its own future work at 1.0 (it is the largest and closes the `static_files.allowed_extensions` config-key trap); the `Utils::reboot()` deprecation test is blocked on #588 (missing `symfony/deprecation-contracts` declaration).

## Open findings

- **F-1 (medium)**: `src/Http/Request.php:120` — runtime `trigger_error` message for `withHeader()` does not name a removal version. **Must be fixed before merge** (acceptance criterion 1 not met).
