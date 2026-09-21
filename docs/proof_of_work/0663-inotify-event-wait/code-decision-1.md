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

    if (!\is_resource($fd)) {
        return;
    }

    $read = [$fd];
    $write = null;
    $except = null;
    @\stream_select($read, $write, $except, 1);
}
```

- The helper now takes the in-scope `$watcher` so it can read the private `fd`
  through the existing `getPrivateProperty()` reflection helper (same
  convention as every other private member touched by this test class).
- `if (!\is_resource($fd)) { return; }` mirrors the existing guard style at
  `testEventWithUnknownWatchDescriptorIsSkipped` / `testMovedOutDirectory...`
  (`\assert(\is_resource($fd))`) and keeps the helper safe when the extension
  path did not initialise an fd.
- A 1-second timeout is the safety net: a missing event makes the following
  assertion fail after at most 1 s instead of hanging the suite. In the happy
  path `stream_select()` returns as soon as the fd is readable (immediately,
  because the event is already queued), so the ~200 ms per call disappears.
- The comment now states the real mechanism (FAQ-006 synchronous enqueue →
  descriptor readable → `stream_select` is a condition wait, not an arbitrary
  settle).
- All 21 call sites updated to pass `$watcher`. The extension guard
  (`@requires extension inotify`) and every assertion are unchanged.

## Alternatives rejected

1. **Keep `usleep(200000)`.** The issue's whole point; it is pure overhead once
   the synchronous-queue invariant is understood.
2. **Remove the wait entirely.** Tempting because events are queued
   synchronously, but relying on that implicitly would make the tests brittle:
   `stream_select` documents and enforces the invariant, and the 1 s bound is a
   deliberate failure mode rather than a flaky assertion. Also, a bare read on
   a non-blocking fd that is somehow not yet readable would silently produce an
   empty event list and the test would fail confusingly.
3. **Shorter `usleep` (e.g. 1 ms poll loop).** Still a poll, burns CPU, and
   picks an arbitrary bound; `stream_select` is the kernel's own readiness
   notification.
4. **`inotify_read()` directly in the helper.** Would drain the queue before
   `invokeOnNotify()` gets to it, changing test semantics (the tests call
   `onNotify()` explicitly). Not acceptable.

## Uncertainties

- `stream_select` can return `false` if interrupted by a signal (EINTR); the
  helper ignores the return value. Under the suite's conditions this is not
  observed, and a signal arriving between the filesystem syscall and the select
  is extremely unlikely. A more defensive helper would retry on `false`; left
  out to keep the change minimal (see findings-coder.md).
- The inotify extension is not installed on this host (macOS, PHP 8.5), so the
  18 gated tests were skipped locally. The changed helper only executes on the
  extension-gated path, so it was verified by syntax/phpstan and by running the
  7 non-gated tests; CI legs with ext-inotify must confirm the gated tests.
