# Findings — Review

| ID | File:Line | Severity | What is wrong | Status |
|---|---|---|---|---|
| R1-1 | `src/Reboot/Strategy/RebootStrategyInterface.php:44-45` | nit | Extra blank line between `shouldReboot()` and `}`. Not fixed by cs-fixer but harmless. | fixed in round 2 — blank line removed, `composer lint` still 0 fixable |
| R1-2 | `UPGRADE.md:33` | low | `## Upgrading to 0.28` heading assumes 0.28.0 is next release (currently correct per `bin/pick-issue.php`). If milestone changes before merge, rename. | acknowledged — maintainer to adjust if milestone rolls; not a code defect |
| R1-3 | `CHANGELOG.md:12` | nit | Long single-bullet `Removed` entry wraps differently than typical `Fixed` bullets but passes `bin/check-changelog.php`. | acknowledged — structural check OK; style matches Keep a Changelog `Removed` example |

