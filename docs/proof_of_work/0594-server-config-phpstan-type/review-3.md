# Review — round 3 (#594)

Read-only verification of commit `211c0e9` (round-2 fixes), branch
`chore/issue-594-server-config-array-shape-is-duplicated`.

## Round-2 finding status

| Finding | Status | Evidence |
| ------- | ------ | -------- |
| R2-N1 | fixed | Empirically verified under the repo's own PHPStan settings: a plain `$serverConfig['probe_key']` is flagged `offsetAccess.notFound` ("might not exist"), while `?? null` is silent — so `?? null`, not `treatPhpDocTypesAsCertain`, is the suppressor. `code-decision-1.md` and the R1-F4 row now say so. |
| R2-N2 | fixed | The R1-F7 row now reads "PHPStan checks the alias's consumers but cannot detect drift between the alias and `ConfigurationTreeBuilder`". Correct: no mechanical link exists for PHPStan to compare. |

## New issues from `211c0e9`

None substantive. All three files touched are documentation. Markdown tables
are well-formed, and the line references in `review-2.md` were re-verified
against source. PHPStan: `[OK] No errors`; the `src/` diff remains
docblock-only.

One inconsequential note: the R2-N1 row cites `code-decision-1.md:47-48`, a
frozen citation to the pre-fix state, so it now lands on the corrected text.
Not a finding.

**Verdict (round 3):** clean — 0 open findings.
