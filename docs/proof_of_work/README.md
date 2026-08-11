# Proof of Work

Every issue cycle described in [workflow.md](../workflow.md) leaves four kinds
of Markdown file behind, in one directory per issue:

```
docs/proof_of_work/
  README.md                  this file
  <NNNN>-<slug>/             NNNN = zero-padded issue number
    findings-coder.md        what the coder found
    findings-review.md       what the review found
    code-decision-1.md       round 1 — what the coder did and why
    review-1.md              round 1 — the review of it
    code-decision-2.md       round 2, if there was one
    review-2.md
```

That is the whole format. The files are written by the subagents that do the
work, committed on the branch like any other change, and read by a human
during review. Nothing validates them, because there is nothing here a script
could usefully check that a reader cannot.

## The files

### `findings-coder.md` and `findings-review.md`

One file per role, appended to across rounds. The coder records what it found
while implementing — obstacles, surprises, bugs noticed in passing, including
ones outside the scope of the issue. The review records what it found in the
diff.

Two files rather than one because the two roles disagree, and a shared file
turns that disagreement into an edit war. Keeping them apart means the review
can say "still present" about something the coder called fixed, and both
statements survive in the record.

A useful entry names a file and a line, says what is wrong, and says what
happened to it. A table is a reasonable way to write that, but it is not a
schema — prose is fine when prose is clearer.

### `code-decision-<x>.md` and `review-<x>.md`

`<x>` is the round number of the inner loop: the coder implements
(`code-decision-1.md`), the review reads the diff (`review-1.md`), the coder
fixes what came back (`code-decision-2.md`), the review looks again
(`review-2.md`), and so on until the review comes back clean.

`code-decision-<x>.md` is where the coder explains the choice it made — the
approach it took, what it rejected, what it was unsure about. `review-<x>.md`
is the review's output for that round.

Numbered files rather than one growing file so that the shape of the cycle is
visible at a glance: six files means three rounds, and three rounds means
something was hard.

## Why there is no tool

There used to be one — `bin/pow.php` wrote these files, `bin/check-pow.php`
verified them in CI, and between them they ran to roughly 4,000 lines with
another 3,300 lines of tests. They enforced a manifest schema, an append-only
ledger, a sha256 chain across PR comments, round caps per branch profile, and
a dozen numbered gate rules.

It worked, and it was not worth it. The machinery cost more to maintain and
reason about than the evidence it protected was worth, and it failed green
pull requests over unfilled fields. See entry #3 in
[process-changelog.md](../process-changelog.md).

Write the files by hand. If they are wrong, that is what review is for.
