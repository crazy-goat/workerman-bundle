## Round 1 — #744

Trimmed `docs/helpers/faq.md` from 409 to 275 budgeted lines and `docs/helpers/decisions.md` from 322 to 286 (the latter had crept over after DEC-022 in #730). `php bin/kb-lint.php` now reports 0 warnings.

Two levers, in this order:

1. **Promote** entries whose rule is already encoded by a test or a config gate, per the KB's own decay rule (`status=promoted`, one-line body + `gate=`):
   - FAQ-004 (file-only rules on the last path component) → `tests/StaticFilesMiddlewareTest.php`.
   - FAQ-025 (Workerman header-count corrections) → `tests/RequestConverterTest.php::testRawHeadMayHaveDuplicates*`.
   - FAQ-027 (`strcspn`/`strpbrk` byte masks) → `tests/RequestConverterTest.php` mask-equality + exhaustive byte test.
   - FAQ-031 (`bin/` is linted) → `phpstan.neon.dist` + `.php-cs-fixer.dist.php` Finder include `bin/`.
2. **Trim prose** on the longest active entries (FAQ-005/007/013/016/018/019/023/030/036/037, DEC-006/019/022), keeping every trigger, rule and command; only narrative and restatement were removed.

Rejected deleting entries outright: `kb-lint` reports none `stale`, and the active entries carry rules not covered elsewhere. Rejected raising `LINE_BUDGET`: lowering a gate to reach green is forbidden (`docs/helpers/decisions.md`). Rejected moving entries between the two files to hide a warning: both budgets are enforced independently, and moving only shuffles the overflow.

The tag index is unchanged (no ids/tags changed) and `kb-lint` confirms 0 stale, 60 entries.
