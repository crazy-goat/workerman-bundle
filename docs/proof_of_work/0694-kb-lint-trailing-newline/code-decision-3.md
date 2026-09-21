# code-decision-3 — #694, review round 2 fixes

Round 2 confirmed F-1..F-5 fixed but found a regression in the F-1 fix plus a
CRLF nit.

## F-7 (medium, regression) — doubled blank before an existing heading

Round 2's collapse decremented `$at`, but `$at` was also used for the tail
slice, so the dropped blanks stayed in the tail and the unconditional `''`
prepend doubled them. Any file whose first `##` heading was preceded by a blank
(the normal case) got two blanks between the created index and that heading.

Fix: separate the head boundary from the tail boundary.

```php
$at = $firstSection !== null ? $firstSection - 1 : \count($lines);

$headEnd = $at;
while ($headEnd > 0 && trim($lines[$headEnd - 1] ?? '') === '') {
    --$headEnd;
}

$section = ['## Tag index', '', INDEX_START, ...$rendered, INDEX_END, ''];
if ($headEnd > 0) {
    $section = ['', ...$section];
}

$lines = [...\array_slice($lines, 0, $headEnd), ...$section, ...\array_slice($lines, $at)];
```

- The head stops at `$headEnd` (after the last non-blank line), dropping the
  blank run entirely; the separator re-adds exactly one.
- The tail still starts at the original `$at`, so the heading (or EOF) follows
  the inserted block unchanged.
- Test: `testFixCreatesTheIndexWithoutDoublingTheSeparatorBeforeAnExistingHeading`
  keeps a blank-preceded `## Section`, asserts one blank before `## Tag index`
  and one before `## Section`. It fails on round-2 `7dc27a0`, passes now.

## F-8 (nit) — mixed line endings on CRLF files

`normalizeTrailingNewline()` rewrote only the tail, turning an in-sync CRLF
file into mixed `\r\n` + bare `\n`. Fixed by scoping it to LF-only files:

```php
if (str_contains($contents, "\r")) {
    return;
}
$normalized = rtrim($contents, "\n") . "\n";
```

The knowledge base is LF-only, so this loses nothing and cannot introduce mixed
endings. The previous `rtrim($contents, "\r\n")` is gone.

## F-6 — still deliberately deferred

Unchanged: a distinct pre-existing defect (marker-less file with an existing
`## Tag index` heading) to be filed as its own issue at step 14.

## Alternatives rejected

1. **For F-7, normalise `$lines` by trimming trailing blanks before the
   branch.** Also affects the regenerate path and the `tail` slice; the
   two-boundary version is local to the create path.
2. **For F-8, convert the whole CRLF file to LF.** A broader behavioural change
   to files the tool did not otherwise touch; skipping CRLF files is the minimal
   contract ("normalise LF files to exactly one LF newline").

## Uncertainties

- None new. `$headEnd` and `$at` are both bounded by `[0, count($lines)]` and
  `$headEnd <= $at`.
