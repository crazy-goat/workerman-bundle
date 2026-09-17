# Findings — review (#810)

## Round 1 — HEAD 0e03035

No earlier findings file existed. No high/medium findings; implementation accepted subject to low-severity proof corrections. See `review-1.md` for full evidence and checks.

- R1-1 | `docs/proof_of_work/0810-reload-http-readiness/code-decision-1.md:39-41,84-88` and `findings-coder.md:27-31` | Proof claims no post-deadline probe and describes only inside-deadline starts; `Wait.php:86-96` can start/accept a final probe after a sleep crosses expiry. Zero-budget coverage proves only first-failure exhaustion. | low | **open** — correct proof wording to documented soft-bound semantics; no production deadline refactor requested. Characterization produced a successful second probe at 1.103160 seconds for a 1-second budget.
- R1-2 | `docs/proof_of_work/0810-reload-http-readiness/findings-coder.md:16-20,45-52,83-84` and `code-decision-1.md:57-60,89-92` | False proof claims: port-down polarity is not inverted; mocked 200 covers actual success assertions; RetryMiddleware is caller-configurable and preserves declined rejection; queue exhaustion throws OutOfBoundsException on a sixth request, not the claimed type on fifth; branch suite count is 2703/18306 rather than recorded 2699/18294. | low | **open** — correct/remove inaccurate evidence before retro/issue filing. Port-down alleged bug explicitly **not a real finding**, evidenced by test helpers at lines 309-335.

## Round 2 — HEAD 5daf0f0 (b349eb8 + 5daf0f0)

Read prior findings first. Full dispositions and verification are in `review-2.md`; original round-1 entries preserved above.

- R1-1 | `code-decision-1.md:16-19,32-34,61-67`; `findings-coder.md:24-30`; `code-decision-2.md:12-17` | Soft deadline, possible late-start/late-success probe, total timeout inclusion, and limited zero-budget evidence now described accurately. | low | **fixed** — proof corrected; implementation intentionally unchanged.
- R1-2 | `findings-coder.md:17-22,41-44,64-75`; `code-decision-1.md:44-49,71-74`; `code-decision-2.md:18-30` | False port-down/mock/middleware/queue claims corrected; unsupported baseline claims withdrawn; suite counts given tracked-file provenance. | low | **fixed** — factual proof corrections accepted. The suite-count allegation is **not a real finding** of omitted tests: reviewer retracts the round-1 inference. MarkdownLinkTest discovers tracked files and adds two cases per file; current 310 files reproduce 621 Markdown tests / 2006 assertions. Port-down alleged runtime bug also remains **not a real finding**.

**No open findings.** No new high/medium/low/nit findings. Proof-only round clean; previous full gates reused for unchanged implementation, with current focused implementation and Markdown successful summaries independently observed.
