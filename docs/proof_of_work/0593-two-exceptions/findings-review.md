# Findings — review — issue #593

| # | File:line | What is wrong | Severity | Status |
|---|-----------|---------------|----------|--------|
| F-01 | `bin/check-exception-usage.php:155` | Type-discovery regex matches "interface"/"class" inside docblock comments, capturing the wrong word. Both marker interfaces (`ClientInputExceptionInterface`, `WorkermanExceptionInterface`) are silently unchecked — the regex captures `for` from their docblocks ("Marker interface for …"), and `for` matches everywhere as a PHP keyword. The gate passes vacuously for these two types. | high | open |
| F-02 | `bin/check-exception-usage.php:155` | `preg_match` captures only the first declaration per file. If a file declares multiple types, subsequent ones are silently skipped. No current file has multiple declarations, so latent only. | medium | open |
| F-03 | `bin/check-exception-usage.php:155` | Regex does not explicitly handle `readonly class` / `final readonly class` / `abstract readonly class`. Works by accident via the bare `class` alternative. Latent gap. | low | open |
| F-04 | `UPGRADE.md:498-517` | Hierarchy tree under "Upgrading to 0.12" is missing `MalformedRequestException`, `SfxExtractionException`, `UnsupportedListenSchemeException`, `ClientInputExceptionInterface`. Pre-existing, not introduced by this PR. | low | open |
| F-05 | `CHANGELOG.md:1507` | Released `## [0.12.0]` entry still names deleted `ConfigurationValidationException`. Coder chose to keep as immutable historical record; `[Unreleased]` removal entry added. Judgment call — practical impact low. | low | open |
| F-06 | `tests/ExceptionUsageLintTest.php:199-210` | `removeRecursively` does not suppress or check `rmdir()`/`unlink()` return values; could emit warnings on teardown if permissions are restrictive. | nit | open |
| F-07 | `tests/ExceptionUsageLintTest.php:173` | `runScript` passes only `PATH` to the subprocess; fragile if the script later reads an env var. | nit | open |
