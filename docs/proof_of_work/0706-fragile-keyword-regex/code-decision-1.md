# Code decision 1 — issue #706 (fragile keyword regex in ProcessDocsTest)

## Approach

`testProcessNoticesSaysItsTriggersReferToRemovedTooling` asserted the header of
`docs/process-notices.md` (everything before `## N-01`) against the regex
`/history|no longer exist|cannot fire|removed/i` — an OR of four generic
English words. Replaced it with:

```php
self::assertStringContainsString(
    '**N-01 to N-13 are history.**',
    $header,
    'the notices file must warn that N-01..N-13 describe a mechanism that was removed',
);
```

The sentence `**N-01 to N-13 are history.**` (docs/process-notices.md:9) is the
deliberate, bolded summary of the header introduced by #686 and kept through
the #704 rewrite (which added the N-12/N-13 exceptions after it). It states
exactly the intent the test guards: the notices describe removed tooling. A
comment in the test now explains why the assertion is anchored on the exact
sentence and that a synonym-preserving rewrite must not fail it.

## What I rejected

- Keeping a regex but making it stricter (e.g. requiring two keywords): still
  couples the test to word choice; a rewrite in another register would fail.
- Asserting the whole header block verbatim: the header legitimately changes
  (e.g. #704 added the exceptions note; #738 updated N-13). Verbatim locking
  would make every honest edit a test failure — the opposite failure mode.
- Matching the sentence case-insensitively / whitespace-flexibly: the file is
  English prose under our control; exact match is the point of the anchor.

## Uncertainties

None significant. The sentence is itself a "specific known sentence" — the
same kind of anchor the test class already uses elsewhere
(`assertStringContainsString('Success criterion', ...)`). If the header is
ever rewritten wholesale, this test will fail and force a conscious update,
which is the desired behavior.
