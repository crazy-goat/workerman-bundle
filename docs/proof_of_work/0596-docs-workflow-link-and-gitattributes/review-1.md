# Critical review — #596, round 1

## Scope and verdict

Reviewed `3ce2a11309eef49b3bb086cf4ab579dc2890af60` against its parent
`a5c03fb` on `docs/issue-596-docs-workflow-md-is-linked-from-nowhere`.
Read issue #596 through `gh issue view 596 --json title,body,labels,state`.

**Critical-review triggers:** security-relevant production packaging policy
(`.gitattributes` determines consumer archive contents), and more than 200
changed lines (200 insertions plus 5 deletions, including proof of work).
No HTTP implementation, process supervision, or public signature changed.

**Verdict: clean. No new findings. Acceptance criteria are met.**

## Knowledge base and prior findings

Loaded the FAQ and decisions tag indexes first, selecting documentation,
Markdown, changelog, bin, git-hooks and contributor-process entries. Consulted
FAQ-019, FAQ-031, FAQ-015, FAQ-030, FAQ-032 and DEC-012, DEC-021, DEC-008,
DEC-011, DEC-009. No applicable documented decision is violated. In particular:

- DEC-012: a fence/backtick-aware scan found no new bare angle-bracket shell
  placeholders in changed Markdown files.
- DEC-021: released changelog history is unchanged; the entry is in Unreleased.
- DEC-009: helpers remain untouched; the proposal below is for the main session.
- FAQ-032: no `.github/workflows/*` diff; a full daemon suite is not required
  by that rule. Nevertheless, searched tests for references to changed docs
  and attributes and ran all four relevant test classes, not only BinDirectoryTest.

`findings-review.md` did not exist before this round. There are no earlier open
findings to classify as still present / fixed / not a real finding. Read the
coder's two records before searching for new issues.

## Packaging trace

Extracted the actual committed tree with `git archive HEAD | tar -x -C ...`
under the approved scratch root, at `rev596/dist`.

- Archive root contains only bin, CHANGELOG.md, composer.json, composer.lock,
  docs, LICENSE, README.md, resources, src and UPGRADE.md.
- Required runtime/docs paths exist. Recursive comparisons show `src/` and
  `resources/` are byte-for-byte identical to the checkout; no config reference
  or runtime resource was pruned.
- All requested dev exclusions are absent, including benchmarks, e2e, analysis
  configs, contributor/process docs, tests, helper records and Docker test tools.
- `bin/` contains only README.md. The exception is correct: `/bin/*` marks its
  children, not the parent directory, and the later `/bin/README.md -export-ignore`
  unsets that attribute for the README. A scratch clone with a future direct
  script and nested script still archived only bin/README.md: the nested parent
  is excluded even though the nested file itself has an unspecified attribute.
- Composer production autoload maps solely to `src/`; there is no Composer `bin`
  entry. Tests/benchmarks are autoload-dev only. Excluded scripts appear solely
  in root-only development/lifecycle commands, not consumer autoloading.
- Coder's extracted candidate archive and this HEAD archive compare identical
  (`diff -qr`, exit 0). The retained Composer log records installation of this
  bundle by `Extracting archive`, and the daemon log confirms the reported
  Workerman/PHP versions. Thus the recorded scratch-consumer installation and
  HTTP smoke test apply to exactly the shipped contents reviewed here. I did
  not independently repeat the network install or start another daemon.
- Source hook lifecycle wiring is unchanged, its script remains in the checkout,
  and BinDirectoryTest passes. The coder records a successful post-install event.

## Documentation and acceptance criteria

- docs/README.md indexes all immediate files and directories in docs/, grouped
  into user-facing and contributor-facing sections. Directory descriptions
  appropriately represent their nested collections.
- CONTRIBUTING links directly to workflow.md and explicitly frames their overlap
  as summary/detail. Workflow links back and distinguishes conventions from the
  optional subagent method. README's existing docs-index navigation now discovers
  the workflow through that index; no additional direct README link is necessary.
- The closing index text accurately describes usage/configuration versus the
  contributor checklist. Contributor targets use absolute GitHub URLs so shipped
  docs do not rely on excluded local files.
- Independent automated file-link check: 55 relative links in the extracted
  archive and 87 in project source Markdown, zero broken. An initial unscoped
  source scan included ignored tooling/vendor docs; those unrelated paths were
  excluded in the final run. The existing MarkdownLinkTest additionally checks
  tracked-source anchors, case-sensitive paths and fence balance.
- README's bin/README.md link resolves in dist; its Actions badge now has an
  absolute URL. No new broken source-relative link was found. External HTTP
  availability was not exhaustively probed.
- CHANGELOG uses Unreleased / Changed, existing bullet style and issue #596 URL;
  no released section changed. No PHP changes require PHPStan/PSR-12 analysis.

All issue criteria are satisfied, with the dist-install/HTTP and source
post-install execution supported by the coder's retained evidence rather than
an independent rerun. The link requirement is interpreted for shipped docs
against the archive and contributor-only docs against the source repository;
excluded contributor documents cannot themselves be read from an archive.

## Automated verification

- `php bin/check-changelog.php`: pass.
- `php bin/kb-lint.php`: pass; two pre-existing line-budget warnings (FAQ 409,
  decisions 310 budgeted lines; limit remains 300). No gate lowered.
- `git diff HEAD~1..HEAD --check`: pass.
- `vendor/bin/phpunit tests/MarkdownLinkTest.php tests/Process/ProcessDocsTest.php
  tests/KnowledgeBase/KnowledgeBaseTest.php tests/BinDirectoryTest.php --no-coverage`:
  **656 tests, 3001 assertions, pass**.
- Committed archive extraction, required/excluded path inspection, source/resource
  parity, candidate/HEAD archive parity and automated local-link checks: pass.

No finding needs an automated-check attribution. MarkdownLinkTest already covers
source link/anchor regressions; an archive-content/link check would protect the
new packaging contract in future. No repeated defect requiring a new check was
found in this round. No implementation files, helpers, commits or pushes changed.

## Existing observations, not PR findings

The coder's branch-protection mismatch is real: CONTRIBUTING still says no branch
protection while process-notices records the active `restric-main` ruleset; the
GitHub rulesets API confirms it is active. This predates the diff and is suitable
for separate follow-up, not a blocker for this packaging/discoverability change.
The KB budget warnings also predate the change. Other coder side observations
were not needed to establish this PR's correctness and were not fully re-tested.

## KB candidate (proposal only)

**Title:** Validate export-ignore against the archive, not just the checkout

**Tags:** docs, bin, composer, packaging

**Trigger:** Changing export-ignore rules or links from shipped documentation.

**Entry:** Verify committed packaging with `git archive HEAD`, compare required
runtime directories against the source, and resolve shipped Markdown links inside
the extraction. To retain one child, ignore `/bin/*` and then unset export-ignore
for that child; ignoring `/bin` itself prevents traversal and cannot be repaired
by a child exception. Link excluded contributor material with absolute repository
URLs. Install the archive through a Composer dist repository rather than a path
repository to exercise extraction and root-only lifecycle behavior. For
uncommitted edits, use a candidate tree and confirm its archive matches HEAD
once committed. This complements the coder's candidate rather than creating a
second overlapping entry.
