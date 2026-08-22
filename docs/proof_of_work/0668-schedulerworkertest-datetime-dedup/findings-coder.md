# Findings — Coder

## Biggest problem

No significant obstacles. Both fixes were surgical and directly prescribed by
the issue body and the existing FAQ-022 knowledge-base entry. The
`willReturnCallback` pattern was already documented as the recommended fix,
and unique service keys already had an established convention in the same
file (`cadence_test_service`, `lock_test_service`, `subsecond_test_service`).

## Discovered bugs / places to improve

None beyond the issue's scope. A grep for
`willReturn.*new.*DateTimeImmutable.*'+1` across `tests/` returned no
remaining occurrences — the two sites fixed here were the only instances.
