# Findings — review — #656

## Round 1 — UPGRADE.md backfill 0.18–0.24 — 2026-08-23

| # | File:Line | Severity | Description | Status |
|---|-----------|----------|-------------|--------|
| F1 | `UPGRADE.md:189-194` | medium | Deprecated-YAML example invalid. `servers` is `arrayNode -> prototype(array)` with required `name` (list form `- name: …`), not keyed `my_server:` mapping. Also `static_files` only defines `allowed_extensions`; `follow_symlinks` is not a YAML key — it is `StaticFilesMiddleware::$followSymlinks` constructor argument. Copy-paste fails Symfony config validation (`Unrecognized option`). Fix: remove the YAML block or replace with note that there is no YAML equivalent for `$followSymlinks`. | fixed — removed invalid YAML block, replaced with "There is no YAML equivalent" note (commit ed197da→fix) |
| F2 | `UPGRADE.md:173` | low | "`follow_symlinks: false` by default" reads as a YAML key. The option is the service argument `$followSymlinks` on `StaticFilesMiddleware`, not `workerman.servers[].static_files.follow_symlinks`. Reword to `` `$followSymlinks: false` `` or "service argument `$followSymlinks`". | fixed — reworded to service argument `$followSymlinks: false` |
| F3 | `UPGRADE.md:94` | nit | 0.24.0 + 0.24.1 share single `## Upgrading to 0.24` heading with `### … (0.24.1)` subsection. Intentional per `code-decision-1.md` and prior patch folding. A TOC search for "0.24.1" will not hit a `##` entry. Accept as-is; optionally add explicit "This section covers 0.24.0 and 0.24.1." | accepted — intentional folding, matches prior patch style |

Round 1 not clean: 0 high / 1 medium / 1 low / 1 nit. Detail in `review-1.md`. Fixed in round 2.

## Round 2 — re-review after fix — 2026-08-23

Re-ran `composer lint` (OK) and `vendor/bin/phpunit tests/MarkdownLinkTest.php` (419 tests OK). No open findings — clean.
