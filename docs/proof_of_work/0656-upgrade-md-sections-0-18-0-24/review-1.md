# Review — round 1 — #656

## Scope

- `UPGRADE.md` — 7 new sections (0.24 → 0.18) between 0.25 and 0.17.
- `CHANGELOG.md` — not yet updated at review time (to be added in step 8).

## Checks

- Headings: `## Upgrading to 0.xx` in strictly descending order, no gaps: 0.25, 0.24, 0.23, 0.22, 0.21, 0.20, 0.19, 0.18, 0.17, 0.16 … — OK.
- Issue-mandated BC items covered: 0.22 `follow_symlinks` default, 0.23 cache permission + `umask(0077)`, 0.24.1 `MalformedRequestException` — all present with migration snippets and issue refs.
- 0.20/0.19/0.18 correctly marked "No mandatory migration" with short notable-change bullets — no false BC claims.
- Anchors: `docs/security.md#config-cache-file-protection` and `#master-process-fingerprint-pid-file-hardening` resolve to real headings in `docs/security.md` — OK.
- Markdown: fences balanced, no duplicate subheadings inside a version block, code blocks valid — OK.
- Style: matches existing sections (Before/After where relevant, issue links).

## Findings

No open findings — clean round.

## Verdict

Clean. Proceed to lint/test + CHANGELOG + PR.
