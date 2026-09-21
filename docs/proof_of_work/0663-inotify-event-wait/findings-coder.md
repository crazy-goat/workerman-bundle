# Findings — worker (#663)

## Obstacles

- **No inotify extension on this host.** `php -m` shows no `inotify`; PHP is
  8.5.10 on macOS. Running
  `php -d phar.readonly=0 vendor/bin/phpunit --no-coverage tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php`
  gives `25 tests, 7 assertions, 18 skipped` (all `@requires extension inotify`
  tests skipped) in 0.041 s. The helper changed here is only ever exercised on
  the gated path, so it could not be executed end-to-end locally — only
  syntax-checked and phpstan-analysed. Verified: `php -l` clean,
  `vendor/bin/phpstan analyse tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` OK.
- **Call-site count.** The issue says 16 call sites; the file actually has 21
  (`grep -c` before edit = 22 including the definition). All were updated via a
  single `sed` on the exact string `$this->waitForInotifyEvents();`, so none was
  missed.
- **Biggest problem:** the correctness of the new helper hinges on the
  FAQ-006 invariant ("events are queued synchronously at syscall time") being
  true for the less obvious events too, not just `IN_CREATE`/`IN_MODIFY`. The
  gated tests wait for `IN_IGNORED` after `rmdir` and for `IN_MOVED_FROM`
  after `rename`. Because the extension is unavailable locally, I could not
  empirically confirm that the descriptor is readable *immediately* after
  `rmdir`/`rename` for those masks on Linux. Kernel inotify queues the event
  during the vfs operation before the syscall returns, so this should hold, but
  the 1 s timeout is what converts any wrong assumption into a test failure
  rather than a hang. This needs a CI leg with ext-inotify to confirm.

## Outside-scope observations / weak spots

1. `src/Reboot/FileMonitorWatcher/InotifyMonitorWatcher.php:27-28` —
   `start()` calls `stream_set_blocking($this->fd, false)` without checking the
   return of `inotify_init()`. If `inotify_init()` fails (fd exhaustion), `$this->fd`
   is `false`, and `stream_set_blocking(false, false)` throws a `TypeError`
   instead of degrading gracefully. Suggested fix: guard with
   `if ($this->fd === false) { ... return; }` (or let `watchDir()`'s existing
   `@inotify_add_watch` failure handling take over by treating a non-resource fd
   as "watch unavailable"). Out of scope for #663; no fix made.

2. `tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` (new
   helper) — `stream_select()` returns `false` on EINTR (signal-interrupted)
   and `0` on timeout. The helper now asserts `assertNotFalse` and
   `assertGreaterThan(0, ...)` (review round 1, F-1), so neither is silently
   ignored: a missing event fails at the wait with a named message. Retrying on
   `false` would still remove a potential EINTR flake in signal-heavy
   environments. Suggested fix (only if flakiness is ever observed):
   ```php
   while (@\stream_select($read, $write, $except, 1) === false) {
       // retry once on EINTR
   }
   ```
   Kept out to stay minimal.

3. `tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php:824-831` —
   `setUpEventLoop()` replaces `Worker::$globalEvent` with a mock whose
   `onReadable`/`delay` callbacks are no-ops. That is why the tests must call
   `invokeOnNotify()` manually; the new `stream_select` wait does not change
   this, but it is worth noting the helper's fd is not connected to any real
   event loop. No action.

## Verification

- `php -l tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` — clean.
- `php -d phar.readonly=0 vendor/bin/phpunit --no-coverage tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php`
  — OK, 25 tests, 7 assertions, 18 skipped (extension unavailable on macOS),
  0.041 s.
- `vendor/bin/phpstan analyse tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` — OK.
- No `@requires` guards changed. The helper gained assertions in review round
  1 (F-1/F-4: `assertIsResource`, `assertNotFalse`, `assertGreaterThan(0, ...)`);
  no test's own assertions changed.
