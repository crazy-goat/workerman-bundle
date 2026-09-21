# Code decision — #663: replace fixed inotify settle sleep with condition wait

## Problem

`tests/Reboot/FileMonitorWatcher/InotifyMonitorWatcherTest.php` used a helper
`waitForInotifyEvents()` that was a fixed `usleep(200000)` (200 ms). It was
called 21 times across the extension-gated inotify tests, so every CI matrix
leg paid ~4.2 s of pure sleeping. Its inline comment claimed inotify events
are pushed asynchronously and unpollable.

That comment is wrong. `docs/helpers/faq.md` FAQ-006 documents that inotify
events are **queued synchronously at syscall time**, and
`InotifyMonitorWatcher::start()` sets the fd non-blocking
(`src/Reboot/FileMonitorWatcher/InotifyMonitorWatcher.php:27-28`). By the time
each test calls the helper, the kernel has already made the watch descriptor
readable.

## Approach taken

Replaced the sleep with a bounded condition wait on the watcher's inotify fd:

```php
private function waitForInotifyEvents(InotifyMonitorWatcher $watcher): void
{
    $fd = $this->getPrivateProperty($watcher, 'fd');
    self::assertIsResource($fd, 'watcher must expose an inotify fd');

    $read = [$fd];
    $write = null;
    $except = null;
    $ready = @\stream_select($read, $write, $except, 1);

    self::assertNotFalse($ready, 'stream_select() on the inotify fd failed (interrupted?)');
    self::assertGreaterThan(
        0,
        $ready,
        'no inotify event became readable within 1s of the triggering syscall',
    );
}
```

- The helper now takes the in-scope `$watcher` so it can read the private `fd`
  through the existing `getPrivateProperty()` reflection helper (same
  convention as every other private member touched by this test class).
- `self::assertIsResource($fd, ...)` matches the file's other fd guards
  (`assertIsResource` at :143, `\assert(\is_resource($fd))` at :556/:672) and
  turns a broken watcher setup into a named failure instead of a silent no-op.
- `self::assertGreaterThan(0, $ready, ...)` pins the condition wait: a timeout
  (`0`) or an interrupted/`false` select fails at the wait with a message that
  names the missing event, rather than letting the *negative* tests pass
  vacuously (an assertion further down would otherwise pass without its event
  ever having been queued).
- A 1-second timeout is the bound: a missing event fails the assertion after at
  most 1 s instead of hanging the suite. In the happy path `stream_select()`
  returns as soon as the fd is readable (immediately, because the event is
  already queued), so the ~200 ms per call disappears.
- The comment now states the real mechanism (FAQ-006 synchronous enqueue →
  descriptor readable → `stream_select` is a condition wait, not an arbitrary
  settle), and notes explicitly that it is not a general per-event wait.
- All 21 call sites updated to pass `$watcher`. The extension guard
  (`@requires extension inotify`) and every assertion are unchanged.

## Alternatives rejected

1. **Keep `usleep(200000)`.** The issue's whole point; it is pure overhead once
   the synchronous-queue invariant is understood. This sleep is the one
   #592's `code-decision-1.md:63-65` *deliberately* kept, with the rationale
   "no userspace condition to poll" — that rationale is the thing this change
   reverses: `InotifyMonitorWatcher::start()` sets the fd non-blocking, so the
   watch descriptor *is* a userspace-pollable condition. The reversal is
   recorded here because #592 left no durable record of the original claim.
2. **Reuse `Util\Wait::until()` (the #592 convention).** Rejected: `Wait::until`
   is a *polling* helper (condition closure + exponential backoff). The fd
   gives a kernel readiness notification, so `stream_select()` is strictly
   better — it blocks until the event is actually delivered instead of
   re-checking a PHP condition on a timer, and it cannot spin. There is also no
   PHP condition to hand `Wait::until` here: readiness lives in the kernel, not
   in a variable the closure could read, so a `Wait::until` port would have to
   poll `stream_select` itself and add a layer for nothing.
3. **Remove the wait entirely.** Tempting because events are queued
   synchronously, but relying on that implicitly would make the tests brittle:
   `stream_select` documents and enforces the invariant, and the 1 s bound is a
   deliberate failure mode rather than a flaky assertion. Also, a bare read on
   a non-blocking fd that is somehow not yet readable would silently produce an
   empty event list and the test would fail confusingly.
4. **Shorter `usleep` (e.g. 1 ms poll loop).** Still a poll, burns CPU, and
   picks an arbitrary bound; `stream_select` is the kernel's own readiness
   notification.
5. **`inotify_read()` directly in the helper.** Would drain the queue before
   `invokeOnNotify()` gets to it, changing test semantics (the tests call
   `onNotify()` explicitly). Not acceptable.

## Uncertainties

- `stream_select` can return `false` if interrupted by a signal (EINTR). The
  helper now asserts `> 0`, so an EINTR surfaces as a named failure instead of
  a silent skip. A signal arriving between the filesystem syscall and the
  select is extremely unlikely under the suite's conditions; a more defensive
  helper would retry on `false`, left out to keep the change minimal (see
  findings-coder.md and review-1.md F-1).
- The inotify extension is not installed on this host (macOS, PHP 8.5), so the
  18 gated tests were skipped locally. The changed helper only executes on the
  extension-gated path, so it was verified by syntax/phpstan and by running the
  7 non-gated tests; CI legs with ext-inotify must confirm the gated tests.
