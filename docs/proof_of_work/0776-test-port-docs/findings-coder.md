## #776 implementation findings

- The stray 8888/9999 references lived in `docs/workflow.md` (two spots), `docs/helpers/faq.md` (FAQ-009 and FAQ-010) and `CONTRIBUTING.md` (the macOS note); `CONTRIBUTING.md`'s main ports paragraph was already correct.
- Biggest obstacle: FAQ-009 is a `promoted` entry whose `gate=` asserted troubleshooting.md documented the ports. Since it did not, the entry was simultaneously the stale reference and self-certifying; the fix adds the missing section and rewrites the gate so it is true again.
- No additional out-of-scope bugs identified.
