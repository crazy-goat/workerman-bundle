# Code decision — round 1 — #656

## Approach

- Insert 7 sections between 0.25 and 0.17, descending: 0.24, 0.23, 0.22, 0.21, 0.20, 0.19, 0.18.
- Each section derived from CHANGELOG.md 0.18.0–0.24.1 blocks. Highlighted BC-relevant items from the issue (0.22 follow_symlinks default, 0.23 cache permission guard, 0.24.1 MalformedRequestException) as top-level subsections with migration snippets. Versions with no mandatory migration get an explicit "No mandatory migration" note plus notable changes as bullet list, so upgraders don't wonder if the section was omitted.
- Combined 0.24.0 and 0.24.1 under `## Upgrading to 0.24` with a 0.24.1 subsection — avoids two near-empty headings and matches the "one section per affected version" intent while being concise. 0.24.0 itself had no BC break, so it's covered by the intro note under the same heading.

## Rejected

- Separate `## Upgrading to 0.24.1` heading: would leave 0.24.0 orphaned with a one-line "no migration" section right above it — noisier TOC and inconsistent with existing style where patch releases are folded into their minor.
- Reproducing full CHANGELOG bullet lists for 0.18–0.24: would duplicate ~400 lines and drift from the upgrade-guide scope (migration steps, not release notes). Instead short summaries + issue links.
- Policy-only fix ("guide covers only BC-breaking releases"): considered but issue's suggested fix lists backfilling as preferred; policy note would leave upgraders without guidance for the three BC-relevant changes.

## Uncertainties

- Whether 0.20/0.19/0.18 sections should explicitly say "No mandatory migration" vs being omitted. Chose to include them — omission was the bug (#656). If maintainer prefers sparse guide, those three can be collapsed to a one-liner each.
- Exact wording for 0.23 cache-migration: kept short with link to security docs, matching 0.25 section style.
