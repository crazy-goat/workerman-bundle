# Review round 2 — issue #663: replace fixed inotify settle sleep with a condition wait

Diff under review: `git diff master...HEAD` (commits `e65fe7c` + `659efb0`).
Round 2 covers the fixes applied for round-1 F-1..F-4.

Changed files (whole branch):

- `tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php`
- `docs/proof_of_work/0663-inotify-event-wait/code-decision-1.md` (new)
- `docs/proof_of_work/0663-inotify-event-wait/findings-coder.md` (new)
- `docs/proof_of_work/0663-inotify-event-wait/review-1.md` (new)
- `docs/proof_of_work/0663-inotify-event-wait/findings-review.md` (new)

## 1. Adjudication of round-1 findings (read first)

`findings-review.md` was read before looking for anything new.

| id | round-1 severity | status in round 2 | evidence |
|----|------------------|-------------------|----------|
| F-1 | low | **fixed** | `InotifyMonitorWatcherTest.php:854` now `$ready = @\stream_select($read, $write, $except, 1);` and `:856-860` `self::assertGreaterThan(0, $ready, 'no inotify event became readable within 1s of the triggering syscall');`. Timeout (`0`) and EINTR/error (`false`) now fail at the wait instead of passing vacuously. |
| F-2 | low | **fixed** | Comment at `:844-847` now reads "This is not a general per-event wait: an earlier event still queued on the fd makes `stream_select()` return at once. That is fine here because the synchronous-enqueue invariant means the newly triggered event is already queued too when the filesystem call returned." The overstated "blocks until the kernel delivers them" wording is gone. Underlying helper behaviour is intentionally unchanged. |
| F-3 | low | **fixed** | `code-decision-1.md:71-78` now lists "**Reuse `Util\Wait::until()` (the #592 convention).** Rejected: `Wait::until` is a *polling* helper …". `:64-70` records the reversal of the #592 rationale. I independently confirmed the quoted origin at `docs/proof_of_work/0592-usleep-wait-replacements/code-decision-1.md:63-65` ("No userspace condition to poll"). The durable FAQ/DEC amendment remains a step-14 proposal (correct — helpers are written only by the retro step). |
| F-4 | nit | **fixed** | `:849` now `self::assertIsResource($fd, 'watcher must expose an inotify fd');`, matching the file's other fd guards (`assertIsResource` at `:143`, `\assert(\is_resource($fd))` at `:556`/`:672`). The silent `return` is gone. |

Round-1 "review round 2 to confirm" items are confirmed fixed.

## 2. Helpers consulted (TAG INDEX)

`docs/helpers/faq.md` index read; matching tags for this diff are `tests`,
`inotify`:

- **FAQ-006** (`tests,inotify`, `:153-161`) — the premise: *"Inotify events are
  queued synchronously at syscall time, so `mkdir` → `rmdir` → `invokeOnNotify`
  is race-free."* No violation; the new assertion is the machine-checkable form
  of this invariant.
- **FAQ-032** (`ci,tests,process`) — applies only to `.github/workflows/*`
  diffs. This branch touches no workflow file, so the full-suite sweep is not
  required.
- `grep -rn "stream_select\|Wait::until\|usleep" docs/helpers/` → **no hits**.
  There is no recorded helper policy for test waits, which is exactly why F-3's
  candidate DEC/FAQ proposal matters. Nothing in the current diff violates a
  documented decision.

`docs/helpers/decisions.md` index read; `tests` maps to DEC-014/DEC-015
(process-lifetime FIFO caches, `parseCookiesFromServerBag()`) — untouched.
DEC-007 (coverage floor) is respected: `phpunit.xml:26-30` includes only
`src/`, so test-file lines cannot move the 80% floor. No gate touched.

## 3. Checks re-run on this host (PHP 8.5.10, macOS, no `ext-inotify`)

- `php -l tests/.../InotifyMonitorWatcherTest.php` — clean.
- `vendor/bin/phpstan analyse` — changed file **and repo-wide**, both `[OK] No
  errors` (level 8).
- `vendor/bin/php-cs-fixer fix --dry-run --diff <file>` — 0 files to fix.
- `vendor/bin/rector process --dry-run <file>` — `[OK]`.
- `php bin/kb-lint.php` — OK (2 pre-existing size warnings on `docs/helpers`,
  unrelated).
- `phpunit tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` —
  `25 tests, 7 assertions, 18 skipped`, 0.026 s (extension-gated tests skip on
  macOS; only the 7 non-gated tests execute).
- `git status` clean; no uncommitted changes on the branch.

The 18 gated tests still cannot be executed locally; CI with `ext-inotify` must
confirm. This remains the only unverified surface.

## 4. Regression analysis of the F-1 assertion — all 21 call sites

Question asked in round 2: can `assertGreaterThan(0, $ready)` fire spuriously
on a legitimate call site with no queued event? I enumerated every call site
(`grep -n '\$this->waitForInotifyEvents(\$watcher)'` → 21) and the immediately
preceding filesystem mutation:

| # | call | preceding mutation | mask produced (all watched by `watchDir()`) |
|---|------|--------------------|--------------------------------------------|
| 1 | :212 | `file_put_contents newfile.php` :211 | IN_CREATE, IN_MODIFY |
| 2 | :239 | `file_put_contents existing.php` :238 | IN_MODIFY |
| 3 | :264 | `file_put_contents data.csv` :263 | IN_CREATE, IN_MODIFY |
| 4 | :297 | `rmdir subdir` :295 | IN_DELETE\|IN_ISDIR + IN_IGNORED |
| 5 | :311 | `rmdir tmpDir` :309 | IN_IGNORED (root watch removed) |
| 6 | :340 | `mkdir newsub` :339 | IN_CREATE\|IN_ISDIR |
| 7 | :380 | `file_put_contents a.php/b.php` :378-379 | IN_CREATE, IN_MODIFY |
| 8 | :449 | `file_put_contents a.php` :448 | IN_CREATE, IN_MODIFY |
| 9 | :463 | `rmdir subdir` :461 | IN_DELETE\|IN_ISDIR + IN_IGNORED |
| 10 | :503 | `rmdir subdir` :501 | IN_DELETE\|IN_ISDIR + IN_IGNORED |
| 11 | :520 | `mkdir subdir` :519 | IN_CREATE\|IN_ISDIR |
| 12 | :567 | `mkdir child` :566 (watch added at :561) | IN_CREATE\|IN_ISDIR |
| 13 | :602 | `rename staging → moved` :601 | IN_MOVED_TO\|IN_ISDIR |
| 14 | :639 | `rename alpha → beta` :637 | IN_MOVED_FROM + IN_MOVED_TO |
| 15 | :680 | `rename subdir → outside` :678 | IN_MOVED_FROM\|IN_ISDIR |
| 16 | :699 | `rename outside → subdir` :697 | IN_MOVED_TO\|IN_ISDIR |
| 17 | :736 | `rename gone.php → outside` :735 | IN_MOVED_FROM |
| 18 | :782 | `mkdir ghost` :781 | IN_CREATE\|IN_ISDIR |
| 19 | :785 | `rmdir ghost` :783 | IN_DELETE\|IN_ISDIR |
| 20 | :807 | `mkdir ghost` :806 | IN_CREATE\|IN_ISDIR |
| 21 | :810 | `rmdir ghost` :808 | IN_DELETE\|IN_ISDIR |

Every one of the 21 is preceded by a filesystem syscall that queues an event
covered by the watch mask (`IN_MODIFY|IN_CREATE|IN_DELETE|IN_MOVED_TO|IN_MOVED_FROM`)
or by `IN_IGNORED`, which is delivered regardless of mask. There is no call
site waiting on a pure `IN_ACCESS`/`IN_OPEN`/`IN_CLOSE` mutation (none in the
mask), so **no legitimate call site can sit out the 1 s timeout on a
correct kernel**. `stream_select` on an inotify fd is level-triggered and PHP
sets the fd non-blocking in `start()` (`InotifyMonitorWatcher.php:28`), so an
already-queued event always returns `> 0` immediately.

No automatic draining can remove the event before the select: `setUpEventLoop()`
(`:824-831`) installs a mock whose `onReadable` callback is a no-op, so the only
consumer of the queue is the explicit `invokeOnNotify()`, which always runs
*after* the wait. Confirmed by reading the helper/source. **No spurious-failure
regression.**

The only "vacuous pass" left is the deliberate F-2 case: at :785/:810 the
assertion passes on the still-undrained preceding `mkdir` event, so the *second*
wait of each pair is not an independent per-event wait. That is the limitation
round 1 accepted and the comment now documents; it is not a new defect and not
a spurious failure.

Checked that F-4's hard assert cannot regress legitimately: every test that
calls the helper enters through `@requires extension inotify` and sets
`Worker::$globalEvent` to an `EventInterface` (`setUpEventLoop()` or a local
event-loop mock) before `start()`, so `start()` takes
`function_exists('inotify_init') && Worker::$globalEvent instanceof EventInterface`
and `fd` is a stream resource. No call site can legitimately reach
`assertIsResource` with a non-resource fd.

## 5. New findings (round 2)

### N-1 — `findings-coder.md` is stale after the F-1 fix — nit

`docs/proof_of_work/0663-inotify-event-wait/findings-coder.md:39-51` still says:

> "`stream_select()` returns `false` on EINTR (signal-interrupted) and `0` on
> timeout; **both are ignored**."

and `:67` still says "No assertions or `@requires` guards changed." Both
contradict the shipped code at `InotifyMonitorWatcherTest.php:854-860`, where the
return value is now captured and asserted. The coder updated `code-decision-1.md`
for F-1 but not `findings-coder.md`. Documentation-only (the proof-of-work record
misdescribes the code it describes).

### N-2 — assertion message conflates EINTR with timeout — nit

`tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:856-860` reports
every non-positive result as *"no inotify event became readable within 1s of the
triggering syscall"*. `stream_select` also returns `false` **immediately** on
EINTR (the helper's own "Uncertainties" section in `code-decision-1.md:94-99`
says so). The failure mode is correct (it still fails), but the message would
misdirect an investigator toward a missing event when the real cause was a
signal. If wanted, branch the message on `$ready === false` vs `0`, or leave as
is — negligible.

No new **high** or **medium** findings.

## 6. Checks that could have caught these

- N-1: no automated check compares proof-of-work prose with the diff. `kb-lint`
  only checks structure/size. Human review only. Since this is the second time
  a round-1 fix left a sibling artifact stale, the durable check would be a
  pre-review `grep`/script for any line asserting behaviour the diff changed —
  not something to add to this test-only PR as a gate.
- N-2: not machine-checkable; message wording is a human judgment.
- The regression class itself (assertion vs. queued event) is covered by the
  manual 21-site enumeration above; a mutation test that no-ops the helper would
  catch F-1-style vacuity but needs `ext-inotify` in CI.

No gate was lowered.

## 7. Candidate helper entries (proposal only — never written by this agent)

Round 1's two candidates remain the right ones and are still unwritten (correct
— retro step owns `docs/helpers/`):

- **Candidate DEC** — "Inotify test waits use a blocking `stream_select()`, not
  `Util\Wait::until()`" (`tags=tests,inotify`; trigger: *writing or reviewing a
  wait in `InotifyMonitorWatcherTest`*): the fd is kernel-readable by the time
  the triggering syscall returns (FAQ-006), so a 1 s `stream_select` on the
  watcher fd is a condition wait and returns immediately; supersedes #592's
  "no userspace condition to poll" note.
- **Candidate FAQ-006 amendment** — append: "`waitForInotifyEvents()` in
  `InotifyMonitorWatcherTest` waits with `stream_select()` on the watcher fd for
  the same reason; the fd is readable by the time the triggering syscall
  returns."

## 8. Verdict

**Approve.** F-1..F-4 are all genuinely fixed (quoted and independently checked).
The new assertion cannot fire spuriously — all 21 call sites are preceded by a
watched filesystem mutation, the fd is non-blocking and level-triggered, and no
automatic drain runs before the select. Remaining items are two nits, both in
documentation/messages, neither affecting test correctness.

Unverified surface unchanged: the 18 `ext-inotify`-gated tests must run green in
a Linux CI leg, and that run is the first real exercise of `stream_select` on an
inotify fd.
