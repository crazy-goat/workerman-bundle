# Review — round 1 (issue #706, branch test/issue-706-processdocstest-testprocessnoticessaysit)

Reviewer: `review` subagent. Scope: diff `origin/master...HEAD`
(tests/Process/ProcessDocsTest.php + two new proof-of-work docs).

## 1. Knowledge base check

Tags matching the diff: `tests`, `docs`, `process`. Read: FAQ-019
(docs/listen-scheme — irrelevant to this diff), DEC-012 (markdown shell
placeholders — no shell placeholders touched), DEC-021 (don't rewrite history
in changelogs when removing things — the diff does not edit
docs/process-notices.md or any changelog; only the test's assertion changed).
**No violations.** No `process`-tagged entry (FAQ-007/030/032/038/039,
DEC-009/011) concerns docs-assertion tests; they cover daemon/CI supervision.

## 2. Earlier findings (findings-review.md)

Did not exist — this is round 1. The coder's own findings-coder.md items are
re-assessed below as review findings.

## 3. Does the fix address the issue?

Yes. The old assertion was `/history|no longer exist|cannot fire|removed/i`
over the header — any prose containing one generic English word passed, and a
legitimate synonym-preserving rewrite could fail it. The new
`assertStringContainsString('**N-01 to N-13 are history.**', $header, ...)`
anchors on the deliberate bold summary sentence at docs/process-notices.md:9
(verified present verbatim, inside the blockquote header, before `## N-01`).
This is the same anchor style the class already uses
(`assertStringContainsString('Success criterion', ...)`), the purpose of the
test (notices must warn the triggers refer to removed tooling) is preserved,
and the inline comment documents why exact-match is intentional. The failure
message still states the intent, so a failure remains diagnosable.

Robustness: the anchor is an exact sentence. Any rewording of that sentence
fails the test — but that is the documented, deliberate trade-off (a wholesale
header rewrite should force a conscious test update). Reasonable rewording of
the *surrounding* prose does not affect it.

## 4. Test run

`vendor/bin/phpunit tests/Process/ProcessDocsTest.php` → OK, 10 tests,
89 assertions. (One pre-existing xdebug-coverage env warning; unrelated.)
Only ProcessDocsTest references process-notices.md; no other test pins this
file, so no broader sweep was required.

## 5. Findings

See findings-review.md. Summary: no high/medium issues; three low/nit
observations, all previously self-reported by the coder and consciously
deferred ("keep the diff minimal"). I concur with the deferral for #3 (nit)
but #1 and #2 are small, safe, one-line improvements that could land in this
PR — the header extraction is *inside* the very test being fixed, so fixing
them would not expand the diff's blast radius.

## 6. Automated-check notes

- Finding F2 (`strpos` may return `false`, `(int) false === 0` silently yields
  an empty header) could plausibly be caught by PHPStan with the
  `phpstan-strict-rules` extension (rule flagging unchecked `strpos` results
  / int-cast of a boolable value). If that defect class recurs, add the
  extension or a targeted PHPStan custom rule rather than re-reporting.
- No other finding has a plausible automated check.

## 7. Candidate knowledge-base entries (proposed, not written)

- **"Anchor doc-content assertions on exact stable sentences, not keyword
  regexes"** — tags: `tests`, `docs`. Trigger: writing a test that asserts
  prose content of a docs/*.md file. Paragraph: a case-insensitive OR-regex of
  generic English keywords over documentation prose is doubly fragile — it
  passes on unrelated wording and fails on synonym-preserving rewrites. Anchor
  on one deliberate, distinctive sentence (ideally bolded summary text the
  docs maintain on purpose) with `assertStringContainsString`, and comment why
  exact match is intentional, so a wholesale rewrite forces a conscious test
  update.

## 8. Verdict

Fix is correct, minimal, purpose-preserving, and the suite passes. Findings
F1/F2 are optional polish that may be addressed in this PR or a follow-up;
nothing blocks.
