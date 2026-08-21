# Findings — Coder — Issue #657

## Biggest problem

The issue instructions specified `$fingerprint !== null` in the
`notRunningException()` helper, but Rector's
`FlipTypeControlToUseExclusiveTypeRector` rule (part of `composer lint`)
flagged it and required `$fingerprint instanceof MasterFingerprint`
instead. I applied the Rector suggestion to pass the lint gate. The
semantics are identical, but it's a reminder that the issue spec's code
snippets are guidance, not gospel — the lint gate has the final say.

## Discovered bugs / places to improve

1. **FAQ-016 duplicated content (docs/helpers/faq.md:281-293).**
   The entry's body starts with the same sentence twice:
   "Master identification fails closed since 0.25.0 (issue #584):
   without the `.fingerprint` sidecar next to the pid file, control
   commands refuse to" — lines 281 and 283 are an exact duplicate of
   lines 282 and 284. This is a pre-existing copy-paste artifact. Fix:
   delete the duplicated lines 283-284. (Outside this issue's scope;
   single-writer rule prevents me from fixing it.)

2. **faq.md is over its line budget.** `kb-lint` reports:
   "docs/helpers/faq.md: 373 lines (index excluded) is over the 300-line
   budget — promote or drop entries." This is a pre-existing condition,
   not caused by my changes (I did not edit faq.md). The retro step
   should consider promoting or dropping entries to bring it under
   budget.

3. **`ServerManager::restart()` catches `ServerNotRunningException`
   silently (ServerManager.php:75).** This is by-design (documented in
   the issue), but worth noting: `restart()` calls `isRunning()` first
   (line 70), then `stop()` (line 72), and `stop()` calls
   `getRunningMasterPid()` which can throw `ServerNotRunningException`
   if the process dies between the `isRunning()` check and the
   `stop()` call. The catch on line 75 handles this race correctly. No
   fix needed — just confirming the catch-by-type still works with the
   new named constructors (it does: they all return
   `ServerNotRunningException` instances).

4. **TOCTOU between `isMasterRunning()` and the diagnostic
   `isProcessAlive()` call.** Documented in the issue and in the
   `notRunningException()` docblock: a process could die between the two
   calls, causing `notRunningException` to classify it as "unverifiable"
   instead of "processDead". This is a message-quality inaccuracy only,
   not a behaviour change. Acceptable as-is.

## Changed files

- `src/Exception/ServerNotRunningException.php` — added named static
  constructors, backward-compatible no-arg constructor.
- `src/ServerManager.php` — `getRunningMasterPid()` now delegates to
  `notRunningException()` helper for cause-specific messages.
- `tests/ServerManagerTest.php` — added message assertions to existing
  no-pid-file, dead-process, fingerprint-mismatch, and no-fingerprint
  tests for `stop()` and `reload()`.
- `UPGRADE.md` — updated three references to the old message.
- `docs/security.md` — updated daemon-start window description.
- `README.md` — updated the 0.25.0 upgrade note.
- `CHANGELOG.md` — added `### Changed` entry referencing #657.
- `docs/proof_of_work/0657-server-not-running-exception-message/code-decision-1.md`
- `docs/proof_of_work/0657-server-not-running-exception-message/findings-coder.md`
