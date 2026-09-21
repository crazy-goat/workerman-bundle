# Review round 3 (final expected) — issue #663: replace fixed inotify settle sleep with a condition wait

Diff under review: `git diff master...HEAD`, branch
`test/issue-663-inotifymonitorwatchertest-fixed-200ms-wa`, HEAD `44d0d5b`
(commits `e65fe7c`, `659efb0`, `44d0d5b`).

Changed files (whole branch):

- `tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php`
- `docs/proof_of_work/0663-inotify-event-wait/code-decision-1.md`
- `docs/proof_of_work/0663-inotify-event-wait/findings-coder.md`
- `docs/proof_of_work/0663-inotify-event-wait/review-1.md`
- `docs/proof_of_work/0663-inotify-event-wait/review-2.md`
- `docs/proof_of_work/0663-inotify-event-wait/findings-review.md`

No `src/` change; no `.github/workflows/*` change (so FAQ-032's
workflow-YAML sweep does not apply).

## 1. Adjudication of earlier findings (read `findings-review.md` first)

`docs/proof_of_work/0663-inotify-event-wait/findings-review.md` was read
before looking for anything new. Every entry from rounds 1–2 is re-checked
against the current lines:

| id | file:line (current) | earlier severity | status round 3 | evidence from current lines |
|----|---------------------|------------------|----------------|-----------------------------|
| F-1 | test:854,856-861 | low | **still fixed** | `$ready = @\stream_select($read, $write, $except, 1);` at :854; `self::assertNotFalse(...)` :856; `self::assertGreaterThan(0, $ready, 'no inotify event became readable within 1s of the triggering syscall')` :857-861. Timeout (`0`) and EINTR/error (`false`) both fail at the wait; negative tests can no longer pass vacuously. |
| F-2 | test:835-847 | low | **still fixed** | Comment explicitly says "This is not a general per-event wait: an earlier event still queued on the fd makes `stream_select()` return at once. That is fine here because the synchronous-enqueue invariant means the newly triggered event is already queued too when the filesystem call returned." (:843-847). The overstated "blocks until the kernel delivers them" wording is gone; helper behaviour unchanged, which is the documented/approved trade-off. |
| F-3 | code-decision-1.md:63-79 | low | **still fixed** | "Alternatives rejected" item 2 is "Reuse `Util\Wait::until()` (the #592 convention)" with the polling-vs-kernel-readiness rationale; item 1 records the reversal of the #592 "no userspace condition to poll" note and points at `0592-usleep-wait-replacements/code-decision-1.md:63-65`. Durable FAQ/DEC note remains a step-14 proposal (helpers are retro-owned — correct). |
| F-4 | test:849 | nit | **still fixed** | `self::assertIsResource($fd, 'watcher must expose an inotify fd');` — matches the file's other fd guard at :143. The silent `return` is gone. |
| N-1 | findings-coder.md:39-51,67-69 | nit | **fixed** | Item 2 now reads "The helper now asserts `assertNotFalse` and `assertGreaterThan(0, ...)` (review round 1, F-1), so neither is silently ignored"; the stale "both are ignored" sentence is gone. The verification bullet now reads "No `@requires` guards changed. The helper gained assertions in review round 1 (F-1/F-4: `assertIsResource`, `assertNotFalse`, `assertGreaterThan(0, ...)`); no test's own assertions changed." — no longer claims assertions were untouched. Both texts now match test:849-861. |
| N-2 | test:856-857 | nit | **fixed** | `self::assertNotFalse($ready, 'stream_select() on the inotify fd failed (interrupted?)');` (:856) now precedes the timeout assertion (:857-861), so an EINTR/`false` result is reported as an interrupted select and a `0` result as a missing event. The two failure modes are distinguishable by message. |

Round-1/2 findings are all resolved on the current branch. No entry is marked
`still present` or `not a real finding`.

## 2. Helpers consulted (TAG INDEX)

`docs/helpers/faq.md` tag index read; matching tags for this diff are `tests`,
`inotify`:

- **FAQ-006** (`tests,inotify`, faq.md:153-161) — the premise the helper now
  encodes: "Inotify events are queued synchronously at syscall time, so
  `mkdir` → `rmdir` → `invokeOnNotify` is race-free." The diff's comment cites
  it by id (:835-836) and is consistent with it. No violation.
- **FAQ-032** (`ci,tests,process`, faq.md:443) — full-suite sweep required only
  for `.github/workflows/*` diffs. None here, so not triggered.
- **FAQ-010/FAQ-011** (`tests,coverage`, promoted) — coverage floor 80%
  (DEC-007) scoped to `src/`; the changed test file cannot move it.
  No gate touched.

`docs/helpers/decisions.md` index read; `tests` maps to DEC-014/DEC-015
(process-lifetime FIFO caches, `parseCookiesFromServerBag()`) — untouched. No
documented decision is violated by this diff. `grep` for
`stream_select|Wait::until` in `docs/helpers/` still has no policy entry, which
is why the two candidate entries from rounds 1–2 remain relevant (proposed in
§5, never written here).

## 3. Checks re-run on this host (PHP 8.5.10, macOS, no `ext-inotify`)

- `php -l tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` — clean.
- `vendor/bin/phpstan analyse <file>` — `[OK] No errors`.
- `vendor/bin/phpstan analyse` (repo-wide, level 8) — `[OK] No errors`.
- `vendor/bin/php-cs-fixer fix --dry-run --diff <file>` — "Found 0 of 1 files
  that can be fixed".
- `vendor/bin/rector process --dry-run <file>` — `[OK]`.
- `php bin/kb-lint.php` — OK, 59 entries, 0 stale (2 pre-existing size warnings
  on `docs/helpers`, unrelated to this branch).
- `phpunit tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` —
  `25 tests, 7 assertions, 18 skipped`, 0.041 s. The 18 `@requires extension
  inotify` tests skip on macOS.
- All 21 call sites updated: `grep -c 'waitForInotifyEvents(\$watcher)'` = 21,
  and no bare `waitForInotifyEvents()` remains in `tests/`.

No gate was lowered. The 18 `ext-inotify`-gated tests still need a Linux CI leg
(unchanged unverified surface, noted since round 1).

## 4. New findings (round 3)

None. The diff adds no new behaviour beyond the round-1/2 fixes already
adjudicated: the helper body is `assertIsResource` → `stream_select` →
`assertNotFalse` → `assertGreaterThan`, all PHPStan-level-8 clean and PSR-12
clean. No type, error-handling, security, or documentation regression was
found in the test-only diff or in the proof-of-work prose. The round-2
regression analysis (all 21 call sites preceded by a watched filesystem
mutation; fd non-blocking and level-triggered; no automatic drain before the
select) still holds — the code at :854-861 is byte-identical to what round 2
enumerated, plus the new `assertNotFalse` line which only adds a branch.

### Nits considered and explicitly not worth another cycle

1. **On EINTR the helper fails the test instead of retrying.** The
   `assertNotFalse` guard (:856) turns an extremely unlikely signal interruption
   into a red test rather than retrying the select. This is the deliberate
   minimal choice documented in `code-decision-1.md:95-100` and
   `findings-coder.md` item 2, and N-2 just made the message explicit. A retry
   loop is only warranted if flakiness is observed. Not a defect; no new cycle.
2. **Second wait of the `mkdir`/`rmdir` pairs (`:785`/`:810`) returns on the
   already-queued earlier event**, so it is not an independent per-event wait.
   This is the limitation F-2 accepted and the comment now documents (:843-847).
   Re-draining/counting would change the helper's contract for no current test
   benefit. Not a defect; no new cycle.

Both are already on the record and answered; neither is reopening a closed
finding.

## 5. Checks that could have caught these / candidate helper entries

- Nothing new to catch this round. The class of defect the earlier rounds
  targeted (discarded return value / vacuous negative test) is now covered by
  the two assertions, which is the machine-checkable form of FAQ-006. It is only
  *executed* on the `ext-inotify` CI leg, so that leg is the real guard.
- The "after a fix, grep sibling proof-of-work prose for stale claims" gap
  (which produced N-1) remains unautomated; `kb-lint` checks structure/size, not
  consistency with the diff. Recording it as a process note is enough — a new
  gate is not warranted on a test-only PR.

**Candidate helper entries (proposal only — this agent never writes them):**

- **Candidate DEC** — "Inotify test waits use a `stream_select()` on the watcher
  fd, not `Util\Wait::until()`" (`tags=tests,inotify`; trigger: *writing or
  reviewing a wait in `InotifyMonitorWatcherTest`*): because inotify events are
  queued synchronously at syscall time (FAQ-006), the fd is kernel-readable as
  soon as the triggering syscall returns, so select is a genuine condition wait
  and returns immediately in the happy path; it supersedes #592's "no userspace
  condition to poll" note. The helper asserts `assertNotFalse` and
  `assertGreaterThan(0, ...)` so a missing event fails instead of hanging.
- **Suggested FAQ-006 amendment** — append: "`waitForInotifyEvents()` in
  `InotifyMonitorWatcherTest` waits with `stream_select()` on the watcher fd for
  the same reason; the fd is readable by the time the triggering syscall
  returns. It is not a general per-event wait — an undrained earlier event makes
  the select return at once."

## 6. Verdict

**Clean.** All six open findings from rounds 1–2 (F-1..F-4, N-1, N-2) are
verified fixed against the current lines; no new medium+ finding exists. The
two residual nits are deliberate, documented, and not worth another cycle. The
only unverified surface is unchanged: the 18 `ext-inotify`-gated tests must run
green in a Linux CI leg — that run is the first real exercise of
`stream_select()` on an inotify fd.
