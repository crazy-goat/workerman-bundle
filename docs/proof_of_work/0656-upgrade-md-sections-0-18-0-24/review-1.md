# Review — round 1 — #656 — UPGRADE.md backfill 0.18–0.24

Branch: `docs/issue-656-upgrade-md-has-no-sections-for-0-18-0-24` (HEAD dirty, 185-line diff vs `origin/master`: 7 sections inserted between 0.25 and 0.17)
Scope: `UPGRADE.md` vs `CHANGELOG.md` + `docs/security.md` + `README.md`; PoW decision `code-decision-1.md`

## 1. Headings — descending order, no gaps

All `## Upgrading to 0.xx` headings extracted:

```
0.25, 0.24, 0.23, 0.22, 0.21, 0.20, 0.19, 0.18, 0.17, 0.16, 0.15, 0.14, 0.13, 0.12
```

* Strictly descending: **PASS** (`0.25 > 0.24 > … > 0.12`).
* No gaps in 0.18–0.25 (required range contiguous: 0.25,0.24,0.23,0.22,0.21,0.20,0.19,0.18 all present): **PASS**.
* 0.24.0 and 0.24.1 folded under single `## Upgrading to 0.24` with `### … (0.24.1)` subsection — matches existing style where patch releases are not separate `##` headings (e.g. no `## 0.24.1` in prior UPGRADE). TOC stays clean. Not a gap; see nit F3.

## 2. 0.22 / 0.23 / 0.24.1 BC coverage vs CHANGELOG.md

### 0.22 — `follow_symlinks` default flip (#292)

* CHANGELOG 0.22 Security: "`StaticFilesMiddleware: add follow_symlinks option (default: false)`" (#292), plus `ServerWorker` cert symlink validation (#286) and `connection_timeout`/`keepalive_timeout`/`body_size_cap` (#279).
* UPGRADE 0.22: top subsection `StaticFilesMiddleware::$followSymlinks now defaults to false`, explains opt-in, shows service-argument migration (`$followSymlinks: true`), notes cert symlink validation and timeouts as no-migration. **Accurate and complete.** Service example matches `src/Middleware/StaticFilesMiddleware.php:79` (`private bool $followSymlinks = false`). **Except** the deprecated-YAML alternative block is invalid — see F1.

### 0.23 — cache permission guard + `withHeader` deprecation

* CHANGELOG 0.23 Security: `ConfigLoader::loadFromCache()` world-writable check + `warmUp()` `umask(0077)` → `0600` (#323). Code Quality: `Request::withHeader()` runtime deprecation (#364).
* UPGRADE 0.23: two subsections — "Config cache now refuses world-writable locations and is created with `0600`" (names `loadFromCache()`/`warmUp()`/`umask(0077)`/`0600` correctly, links to `docs/security.md#config-cache-file-protection`) and "`Request::withHeader()` deprecated" (in favour of `setHeader()`, emits deprecation, mutates in place, removed in 1.0, #364). **Both accurate; issue-mandated items present with correct migration snippet.**

### 0.24 / 0.24.1 — `MalformedRequestException`

* CHANGELOG 0.24.0 Security: fingerprint sidecar (#327), ReDoS guard on `exclude_patterns` (#334), header-re-injection note (#344) — all hardening, no BC. CHANGELOG 0.24.1: `RequestConverter` throws `MalformedRequestException` (extends `\InvalidArgumentException`, implements `ClientInputExceptionInterface`) instead of bare `\InvalidArgumentException` (#577); `HttpRequestHandler` maps `MalformedRequestException`/`FileUploadValidationException` to 400 and wraps lifecycle to 500.
* UPGRADE 0.24: intro "No mandatory configuration change for 0.24.0" with the three hardening items and issue numbers. Subsection "`RequestConverter` now throws `MalformedRequestException` (0.24.1)" gives Before/After narrowing catch (`\InvalidArgumentException` → `MalformedRequestException` + `\InvalidArgumentException`), notes `extends \InvalidArgumentException / implements ClientInputExceptionInterface`, and notes `HttpRequestHandler` 400/500 mapping (#577). **Accurate; slight simplification of lifecycle wrap is not misleading.**

## 3. 0.18–0.21 not overstated

* **0.21**: "Runtime directory now created with `0700`" (#270, #274) plus "No other mandatory migration. PHAR stub validation (#259, #263)" — CHANGELOG 0.21 Security lists exactly those three. No false BC claim; performance/changed items correctly omitted as non-migration.
* **0.20**: "No mandatory migration." + bullets: denylist/allowlist (#235), `If-Modified-Since`/`If-None-Match`+LRU (#254), zip-slip (#252) / cross-scheme (#433), `#267`, `SchedulerWorker` flock (#240). All from CHANGELOG 0.20; correctly framed as notable, not mandatory.
* **0.19**: "No mandatory migration." + URI/method validation (#220) + strict cookie parsing (#217) → 400, `trusted_hosts` opt-in (#213), traversal hardening (#226). Matches CHANGELOG 0.19 Security; "now get 400" is behavior note, not overstated as config break.
* **0.18**: "No mandatory migration. New in this release:" PHAR/BIN packaging (`workerman:build:phar`/`bin`, stub, `build` section, `--kernel-class`, `resources/phar-stub.tpl` — #191) and `Runner` source path configurable (#130). Matches CHANGELOG 0.18 Added/Changed; correctly not claimed as BC.

**Verdict: no false BC claims.**

## 4. Internal markdown links / anchors

Links in UPGRADE.md:

* `docs/security.md#static-files-protection` → `## Static Files Protection` in `docs/security.md` — **OK**.
* `docs/security.md#master-process-fingerprint-pid-file-hardening` → `## Master Process Fingerprint (PID File Hardening)` — **OK** (slug match).
* `docs/security.md#config-cache-file-protection` → `## Config Cache File Protection` — **OK**.
* `README.md#config-cache-and-runtime-user` → `## Config cache and runtime user` — **OK**.

Files exist, anchors resolve; `MarkdownLinkTest` will pass for these. No new anchors introduced that could break existing tests.

## 5. Code blocks — fences and validity

* Fence count 44 (even) → balanced: **PASS**.
* No unterminated fence; `~~~` not used, only ```` ``` ````.
* YAML blocks (4) all parse with `yaml.safe_load`: **PASS** except Block 1 is syntactically valid YAML but semantically invalid config — see F1 (separate from fence validity).
* PHP blocks (15) brace-balanced: **PASS**.
* Language tags present (`php`, `yaml`, `bash`, `text`): **PASS**.

## 6. Style consistency

* New sections use same pattern as existing (0.17–0.12): `### Title`, `**Before:**`/`**After:**` where applicable, ```` ```php ````/```` ```yaml ```` fences, issue refs `(#NNN)`, `**Migration:**` callouts. Horizontal rules `---` between every `##` block matching existing rhythm: **PASS**.
* "No mandatory migration." phrasing for 0.20/0.19/0.18 matches `code-decision-1.md` rationale (avoid omission ambiguity). Acceptable; could be collapsed if maintainer prefers sparser guide but not a style break.

## 7. Duplicate subheadings (MarkdownLinkTest)

Per-version `###` titles deduped within each `##` block: **no duplicates** — **PASS**. `ChangelogStructureTest`-style duplicate-subheading check would pass.

## Findings summary

| # | Location | Severity | Description |
|---|----------|----------|-------------|
| F1 | `UPGRADE.md:189-194` | **medium** | Deprecated-YAML example is invalid. `servers` is `arrayNode -> prototype(array)` with required `name` (list form `- name: my_server`), not keyed `my_server:` mapping. Also `static_files` only defines `allowed_extensions`; `follow_symlinks` is not a YAML key (it is `StaticFilesMiddleware::$followSymlinks` constructor arg). Copy-paste fails Symfony config validation (`Unrecognized option`). Fix: remove the YAML alternative or replace with note "no YAML equivalent — configure via service argument". |
| F2 | `UPGRADE.md:173` | low | "`follow_symlinks: false` by default" phrasing suggests a YAML key. The option is `$followSymlinks` on the middleware service, not a `workerman.servers[].static_files` key. Clarify to `` `$followSymlinks: false` `` or "service argument `$followSymlinks`". |
| F3 | `UPGRADE.md:94` | nit | 0.24.0 + 0.24.1 share one `## Upgrading to 0.24` heading with `### … (0.24.1)` subsection. Intentional per `code-decision-1.md` and matches prior patch folding, but a reader searching for "0.24.1" in the TOC will not find a `##` entry. Accept as-is; optionally add explicit sentence "This section covers both 0.24.0 and 0.24.1." (already implied by intro). |

## Verdict

**Not clean** — 1 medium, 1 low, 1 nit. The medium (F1) should be fixed before merge; it is the only copy-paste-breaking issue. All other checks pass. After F1 fix, re-run `composer lint` / `MarkdownLinkTest` and the manual anchor/fence checks above.
