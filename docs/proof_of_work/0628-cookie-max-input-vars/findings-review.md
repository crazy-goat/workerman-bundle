# Findings — review round 1 (#628, commit 5fa042a)

tests/RequestConverterTest.php:1119-1121 | Characterisation test asserts only the pair count plus first/last values (`c0000`/`c1000`); middle-pair survival is unpinned, so a hypothetical implementation keeping only those two pairs would pass. Coder self-flagged in findings-coder.md and accepted for a cap-detector; one extra `assertSame('v0500', …)` would close it. No existing automated check catches weak assertions; plausible future check: mutation testing (Infection). | nit | status: fixed — `assertSame('v0500', cookies->get('c0500'))` added between first and last assertion (round 2 diff), targeted test green (4 assertions)

(no other findings)
