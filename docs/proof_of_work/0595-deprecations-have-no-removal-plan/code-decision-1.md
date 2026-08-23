# Code Decision — Round 1 (issue #595)

## Decision: removal target is 1.0 for all three deprecations

The three deprecated APIs (`serve_files`/`root_dir`/`static_files` config keys,
`Utils::reboot()`, `Request::withHeader()`) are now documented to be removed in
**1.0**. This is deliberate:

- The issue's own analysis flags that "the next major release" is ambiguous at
  `0.x` (SemVer grants no compatibility guarantee below 1.0 — "major" could
  mean 1.0 or the next 0.x minor). Naming a concrete version removes that.
- 1.0 is the natural SemVer major boundary and the issue's own suggested answer
  ("A 1.0 with unremoved 0.9-era deprecations is awkward"). It is the only
  target that is simultaneously honest about the age of the deprecations (the
  oldest is 0.9.3, fifteen minors old) and unambiguous.

## What was rejected

- **Removing the deprecated code in this PR.** The issue is filed as
  documentation ("deprecations have no removal plan"), not as a removal. The
  `serve_files` removal in particular is the largest of the three and is
  described in the issue as "its own piece of work" that should not be bundled
  into an unrelated release. Removing it now would also remove the
  `static_files.allowed_extensions` config-key trap, which the issue ties to a
  separately-tracked concern. So this PR only documents the removal plan; the
  actual removal stays as future work at 1.0.
- **A per-deprecation divergent removal target.** The issue asks that the
  config keys and `Request::withHeader()` carry "the same removal target as
  each other, or a documented reason for differing". A single 1.0 target for
  all three is the simplest consistent answer.
- **The `Utils::reboot()` deprecation test.** Its notice fires through
  `trigger_deprecation()`, a function from `symfony/deprecation-contracts`
  which the bundle does not declare in `composer.json`. In a tree without that
  package the test would fail with a fatal "Call to undefined function"
  rather than a missing-notice assertion. Declaring the dependency is a
  separate chore (#588, milestone 0.28.0); the test must wait for it.

## What was uncertain

- Whether the config deprecations fire off the `set_error_handler` path. They
  do — Symfony Config's `setDeprecated` emits `E_USER_DEPRECATED`, captured in
  the new `testConfiguredTreeDeprecatesLegacyStaticFileNodes` via
  `set_error_handler`, and the message carries "Will be removed in 1.0".
- Message wording: the deprecation messages now point at
  `docs/security.md#static-files-protection` and state that the
  `static_files.allowed_extensions` allowlist stops applying once you move to
  the middleware. Kept the `since` version (0.9.3) unchanged.
