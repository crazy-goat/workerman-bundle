# Findings — review (#810)

## Round 1 — HEAD 0e03035

No earlier findings file existed. No high/medium findings; implementation accepted subject to low-severity proof corrections. See `review-1.md` for full evidence and checks.

- R1-1 | `docs/proof_of_work/0810-reload-http-readiness/code-decision-1.md:39-41,84-88` and `findings-coder.md:27-31` | Proof claims no post-deadline probe and describes only inside-deadline starts; `Wait.php:86-96` can start/accept a final probe after a sleep crosses expiry. Zero-budget coverage proves only first-failure exhaustion. | low | **open** — correct proof wording to documented soft-bound semantics; no production deadline refactor requested. Characterization produced a successful second probe at 1.103160 seconds for a 1-second budget.
- R1-2 | `docs/proof_of_work/0810-reload-http-readiness/findings-coder.md:16-20,45-52,83-84` and `code-decision-1.md:57-60,89-92` | False proof claims: port-down polarity is not inverted; mocked 200 covers actual success assertions; RetryMiddleware is caller-configurable and preserves declined rejection; queue exhaustion throws OutOfBoundsException on a sixth request, not the claimed type on fifth; branch suite count is 2703/18306 rather than recorded 2699/18294. | low | **open** — correct/remove inaccurate evidence before retro/issue filing. Port-down alleged bug explicitly **not a real finding**, evidenced by test helpers at lines 309-335.
