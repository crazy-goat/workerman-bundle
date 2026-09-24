## Round 1 — #776

Two doc surfaces still named only ports 8888/9999 while `tests/App/Kernel.php` binds three (9991 is the middleware dispatch-contract server, added in #542). Fixed:

- `docs/workflow.md` step 7 comment and its note now list **8888, 9999 and 9991**.
- `docs/troubleshooting.md` gained a "Ports used by the test suite" section naming all three, with `lsof`/`ss` and `php tests/App/index.php stop`, plus a link to CONTRIBUTING's worktree/Docker port guidance.
- FAQ-009 and FAQ-010 now say 8888/9999/9991, and FAQ-009's `trigger`/`gate` were corrected to point at the new troubleshooting section (previously the gate claimed troubleshooting.md documented ports it did not).
- `CONTRIBUTING.md`'s macOS note lists all three (it already had the full list elsewhere).

Rejected adding a fourth "source of truth" for the ports: `tests/App/Kernel.php` remains the authority, and the docs link to it conceptually. The alternative — generating the docs from the kernel — is out of scope for a docs fix.
