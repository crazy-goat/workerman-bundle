# Findings — Review rounds, issue #663

Earlier-review findings: none. `findings-review.md` did not exist before round 1.

## Round 1

| id | file:line | what is wrong | severity | what happened / status |
|----|-----------|---------------|----------|------------------------|
| F-1 | tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:852 | `@\stream_select(...)` discards the return value. Timeout (`0`) and EINTR/other error (`false`) are indistinguishable from readiness, so the helper proceeds without the awaited event. For the positive tests the next assertion fails loudly (the coder's stated intent), but the negative tests — `testOnNotifyDoesNotTriggerReloadForNonMatchingFile` (:253-273, asserts `assertNull(reloadCallback)`) and `testEventWithUnknownWatchDescriptorIsSkipped` (:539-580, asserts maps unchanged) — pass **vacuously** if the event never arrived, i.e. they stop proving the path ran. Suggested: `$ready = @\stream_select(...); self::assertGreaterThan(0, $ready);` (or at minimum a comment pinning the deliberate ignore). | low | **fixed** — `$ready = @\stream_select(...); self::assertGreaterThan(0, $ready, 'no inotify event became readable within 1s…')`. Review round 2 to confirm. |
| F-2 | tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:833-852 (call sites :782/:785 and :807/:810) | The comment "otherwise blocks until the kernel delivers them" overstates the guarantee. `stream_select` returns immediately when the fd still holds an undrained event from an earlier operation, so it does not wait for the *newly triggered* event. Two tests wait twice without an intervening `invokeOnNotify` (`testFailedAddWatchWritesNoMapsAndLogsWarningOnce`), making the second wait a no-op. Harmless today because FAQ-006's synchronous-enqueue invariant means the second event is already queued when the syscall returns, but the helper is not a general per-event condition wait and a future test could rely on it wrongly. Fix: narrow the comment / name, or drain-and-count if a real per-event wait is ever needed. | low | **fixed** — comment rewritten: explicitly says it "is not a general per-event wait", explains an already-queued earlier event makes it return at once, and why that is sound under FAQ-006. |
| F-3 | docs/proof_of_work/0663-inotify-event-wait/code-decision-1.md:55-70 | The project's established condition-wait convention `Util\Wait::until()` (introduced for 43 sleep replacements in #592, whose `code-decision-1.md:63-65` deliberately kept this very `waitForInotifyEvents()` sleep with the rationale "no userspace condition to poll") is absent from the "Alternatives rejected" list. The raw `stream_select` choice is defensible (blocking/kernel-driven vs. polling-with-backoff), but the reversal of a recorded earlier rationale is not documented anywhere durable. Add the rejected alternative and a DEC/FAQ note (candidate in review-1.md §6). | low | **fixed** — `Util\Wait::until()` now in "Alternatives rejected" with the #592 reversal rationale; FAQ-006 amendment proposed at step 14. |
| F-4 | tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:845-847 | The `if (!\is_resource($fd)) { return; }` guard silently no-ops. Every other fd guard in this file asserts (`assertIsResource` at :143, `\assert(\is_resource($fd))` at :556 and :672); a silent return makes a broken watcher setup surface as a confusing downstream failure. On the gated tests the path is unreachable (the helper is only called after `start()`), so this is cosmetic. Suggested: `self::assertIsResource($fd, ...)`. | nit | **fixed** — guard replaced with `self::assertIsResource($fd, 'watcher must expose an inotify fd')`, matching the file's other fd guards. |

No high or medium findings. Verified clean locally: `php -l`, PHPStan level 8
(file and repo-wide), php-cs-fixer (`@PER-CS2x0`+`:risky`), rector dry-run,
kb-lint, and `phpunit` (25 tests / 7 assertions / 18 skipped). The 18
extension-gated tests could not be executed on this macOS host (no
`ext-inotify`); CI must confirm them.

## Round 2

Round-1 fixes verified against the current branch (`659efb0`). Full detail in
`review-2.md`.

| id | file:line | what is wrong | severity | what happened / status |
|----|-----------|---------------|----------|------------------------|
| F-1 | tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:854-860 | (round 1) `stream_select()` return value discarded; timeout/EINTR indistinguishable from readiness, so negative tests could pass vacuously. | low | **fixed** — `$ready = @\stream_select($read, $write, $except, 1);` then `self::assertGreaterThan(0, $ready, 'no inotify event became readable within 1s of the triggering syscall');`. Confirmed at :854 and :856-860. |
| F-2 | tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:844-847 | (round 1) comment overstated the wait as a general per-event wait. | low | **fixed** — comment now states "This is not a general per-event wait: an earlier event still queued on the fd makes `stream_select()` return at once…". Underlying helper behaviour intentionally unchanged (still returns on the undrained earlier event at :785/:810). |
| F-3 | docs/proof_of_work/0663-inotify-event-wait/code-decision-1.md:71-78 | (round 1) `Util\Wait::until()` absent from rejected alternatives, reversal of #592 rationale undocumented. | low | **fixed** — alternative "Reuse `Util\Wait::until()` (the #592 convention)" added at :71-78; #592 reversal rationale at :64-70. Origin independently confirmed at `docs/proof_of_work/0592-usleep-wait-replacements/code-decision-1.md:63-65`. Durable FAQ/DEC note remains a step-14 proposal (helpers are retro-owned). |
| F-4 | tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:849 | (round 1) silent `is_resource` early return. | nit | **fixed** — replaced with `self::assertIsResource($fd, 'watcher must expose an inotify fd');`, matching :143/:556/:672. |
| N-1 | docs/proof_of_work/0663-inotify-event-wait/findings-coder.md:39-51 (and :67) | Stale after the F-1 fix: still says `stream_select()`'s `false`/`0` are "both are ignored" and "No assertions or `@requires` guards changed", contradicting the shipped assertion at test:854-860. `code-decision-1.md` was updated, `findings-coder.md` was not. Documentation-only. | nit | **fixed** — `findings-coder.md` item 2 rewritten to describe the new `assertNotFalse`/`assertGreaterThan` guards and the remaining EINTR retry option; the verification bullet no longer claims assertions were unchanged. |
| N-2 | tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:856-860 | Assertion message always says "within 1s", but `stream_select` can return `false` immediately on EINTR (acknowledged in code-decision-1.md:94-99), so the message can misattribute the cause. Failure mode itself is correct. | nit | **fixed** — added `self::assertNotFalse($ready, 'stream_select() on the inotify fd failed (interrupted?)')` before the timeout assertion, so EINTR and timeout are now distinguished by message. |

Round 2 additionally verified by enumerating all 21 `waitForInotifyEvents($watcher)`
call sites: each is preceded by a filesystem mutation that queues a watched mask
(or `IN_IGNORED`); the fd is non-blocking and level-triggered; no automatic drain
runs before the select (`setUpEventLoop()`'s `onReadable` is a no-op). Therefore
the new F-1 assertion cannot fire spuriously. No high or medium findings.

Checks re-run: `php -l`, PHPStan level 8 (file and repo-wide), php-cs-fixer
(`@PER-CS2x0`+`:risky`), rector dry-run, kb-lint, phpunit (25 tests / 7
assertions / 18 skipped). No gate lowered. The 18 `ext-inotify`-gated tests are
still unexecuted on this macOS host; CI must confirm.
