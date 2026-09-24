## #744 implementation findings

- The target had drifted well past the issue's original 307 lines: `faq.md` was at 409 budgeted lines (and `decisions.md` at 322, over after #730). The issue's "one or two promotions" was not enough; a real trim round was required.
- Four entries were promotable because a test or the lint config already enforces the exact rule (FAQ-004, FAQ-025, FAQ-027, FAQ-031). The rest of the reduction came from tightening prose on the longest entries without dropping triggers, rules or commands.
- Biggest obstacle: keeping every trigger phrase intact — `kb-lint` only validates front matter, so a reworded entry still parses, but the `trigger=` text is what future searches and the tag index rely on. Each trimmed entry was re-read to confirm the trigger still describes the same situation.
- No additional out-of-scope bugs identified.
