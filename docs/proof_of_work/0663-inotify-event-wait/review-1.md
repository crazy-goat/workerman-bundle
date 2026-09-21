# Review round 1 — issue #663: replace fixed inotify settle sleep with a condition wait

Diff under review: `git diff master...HEAD` (single commit `e65fe7c`).

Changed files:

- `tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php`
- `docs/proof_of_work/0663-inotify-event-wait/code-decision-1.md` (new)
- `docs/proof_of_work/0663-inotify-event-wait/findings-coder.md` (new)

## 1. Helpers consulted (TAG INDEX)

`docs/helpers/faq.md` index was read; the tags matching this diff are
`inotify` and `tests`:

- **FAQ-006** — "Testing an `inotify_add_watch()` failure without exhausting
  watch limits" (`tags=tests,inotify`). Relevant sentence: *"Inotify events are
  queued synchronously at syscall time, so `mkdir` → `rmdir` → `invokeOnNotify`
  is race-free."* The diff's core premise (descriptor readable as soon as the
  triggering syscall returns) is exactly this invariant. No violation.
- **FAQ-032** (`ci,tests,process`) — scopes review test runs for
  `.github/workflows/*` diffs. This diff touches no workflow file, so it does
  not apply; the full-suite sweep requirement is not triggered.

`docs/helpers/decisions.md` index was read; tags matching this diff are only
`tests` (DEC-014, DEC-015), which cover process-lifetime FIFO caches and
`parseCookiesFromServerBag()` — neither is touched. No decision is violated.

`findings-review.md` did **not** exist before this round — this is round 1, so
there are no earlier findings to re-adjudicate. `findings-review.md` is created
by this review.

## 2. What I could and could not verify on this host

Host: PHP 8.5.10 (Homebrew), macOS, **no `inotify` extension** (`php -m` has no
`inotify`). Therefore:

**Verified locally:**

- `php -l tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` — clean.
- `vendor/bin/phpstan analyse` — on the changed file **and** repo-wide, both
  `[OK] No errors` (level 8).
- `vendor/bin/php-cs-fixer fix --dry-run --diff <file>` — 0 files to fix
  (`@PER-CS2x0` + `:risky`).
- `vendor/bin/rector process --dry-run <file>` — OK.
- `php bin/kb-lint.php` — OK (2 pre-existing size warnings on `docs/helpers`,
  unrelated to this diff; 0 stale).
- `phpunit tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` —
  `25 tests, 7 assertions, 18 skipped` (the `@requires extension inotify`
  tests), `0.024 s`. Matches `findings-coder.md`.
- Static call-site audit: exactly 21 `waitForInotifyEvents($watcher)` call
  sites; each sits in a test method that has defined the local `$watcher`
  *before* `$watcher->start()`. No helper method shadows or reassigns
  `$watcher`; no call site passes a different watcher. The `$watcher` argument
  is used only to read the private `fd`, which is the same fd the surrounding
  test passes to `invokeOnNotify($watcher, $fd)`. Correct at all 21 sites.

**Could NOT verify locally:**

- Execution of the 18 gated tests and therefore the **runtime behaviour of
  `stream_select()` on a real inotify fd**: immediacy in the happy path, the
  1 s timeout, EINTR behaviour, and that `rmdir`/`rename` make the descriptor
  readable. These need a CI leg with `ext-inotify` (Linux).
- Coverage impact: none expected — `phpunit.xml` `<source>` includes only
  `src/`, so test-file lines do not affect the 80% floor (DEC-007).

## 3. Correctness of the helper

`waitForInotifyEvents(InotifyMonitorWatcher $watcher)` at
`tests/.../InotifyMonitorWatcherTest.php:833-853`.

- `getPrivateProperty($watcher, 'fd')` returns `null` when `fd` is
  uninitialised (`private $fd;` is untyped and defaults to `null`), which the
  `is_resource` guard handles. Verified the default with a reflection probe.
- `$read = [$fd]; $write = null; $except = null; @\stream_select($read, $write, $except, 1);`
  is a valid call. Passing `null` for the write/except sets is accepted by
  PHP, and the 4-argument form defaults microseconds to 0. Type-wise PHPStan
  level 8 is happy after the `is_resource` narrowing.
- The condition-wait reasoning is sound **given FAQ-006**: the triggering
  syscall queues the event before returning, so the fd is readable and
  `stream_select` returns immediately instead of blocking the full second. The
  1 s bound is a safety net that converts a wrong assumption into an assertion
  failure rather than a hang. This is strictly more robust than the old fixed
  `usleep(200000)`, which could only ever be too short or wasted.
- No test calls the helper without having performed a filesystem mutation
  first, so no call should sit out the 1 s timeout on a correct kernel.
- I found no place where the removed sleep was load-bearing for anything else
  (the deferred walk is invoked explicitly, `invokeOnNotify` is called
  manually, and the event-loop mock's `delay`/`onReadable` are no-ops).

## 4. Findings

Full ledger in `findings-review.md`. Summary:

| id | severity | where | what |
|----|----------|-------|------|
| F-1 | low | test:852 | `stream_select()` return value discarded under `@`; timeout (0) and EINTR/error (false) are not distinguished from readiness, so negative-path tests can pass vacuously. |
| F-2 | low | test:833-852 (+782/785, 807/810) | The "blocks until the kernel delivers them" comment overstates the guarantee: an undrained earlier event makes the next select return immediately, so it does not wait for the *newly triggered* event. |
| F-3 | low | code-decision-1.md:55-70 | The project's established condition-wait helper `Util\Wait::until()` (from #592, which deliberately kept this very sleep as "no userspace condition to poll") is not listed among the rejected alternatives, so the reversal is not recorded anywhere durable. |
| F-4 | nit | test:845-847 | The non-resource guard returns silently; the only other guards in this file use `self::assertIsResource`/`\assert(\is_resource(...))` (lines 143, 556, 672). |

No **high** or **medium** findings: the diff is small, the semantics of every
assertion are untouched, and the new wait cannot be *less* correct than the
fixed sleep it replaces. F-1/F-2 are robustness/reporting concerns rather than
current failures.

## 5. Checks that could have caught these

- F-1, F-2: no automated check can catch them on this host (need `ext-inotify`).
  A CI-only mutation — replacing the helper body with a no-op and confirming the
  suite still goes green — would expose F-1's vacuous-pass exposure. Worth
  considering as a one-off, not a gate.
- F-3: `bin/kb-lint.php` only warns about file size; it cannot see a missing
  rejected-alternative. Human review only.
- F-4: static analysis will not flag it; PSR-12/php-cs-fixer do not either.

No gate was lowered.

## 6. Candidate helper entries (proposal only — never written by this agent)

**Candidate DEC — "Inotify test waits use a blocking `stream_select()`, not `Util\Wait::until()`"**
`tags=tests,inotify` · trigger: *"writing or reviewing a wait in `InotifyMonitorWatcherTest`"*.

Because inotify events are queued synchronously at syscall time (FAQ-006), the
watch descriptor is readable as soon as the triggering `mkdir`/`rename`/`rmdir`
returns. The test helper therefore blocks in a 1 s `stream_select()` on the
watcher's fd rather than polling with the generic `Util\Wait::until()` helper
used elsewhere: select is kernel-driven and returns immediately in the happy
path, whereas `Wait::until()` would need a zero-timeout select in its predicate
and would add backoff latency. This supersedes the note recorded for #592 that
left the 200 ms `usleep()` in place as "no userspace condition to poll".

**Suggested FAQ-006 amendment** — append one sentence to FAQ-006:
"`waitForInotifyEvents()` in `InotifyMonitorWatcherTest` waits with
`stream_select()` on the watcher fd for the same reason; the fd is readable by
the time the triggering syscall returns."

## 7. Verdict

**Approve with low/nit findings.** The change does what #663 asks, is
PSR-12/PHPStan/rector-clean, keeps test semantics identical, and the 21 call
sites are all correct. Residual risk is not being able to execute the gated
path locally; a CI run with `ext-inotify` must confirm the 18 skipped tests
go green and no test sits out the 1 s timeout.
