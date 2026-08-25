# Code decision — #703: harden `writeStream()` finally-block unlink

## Approach taken

The `finally` block in `SfxDownloader::writeStream()` (now
`src/Phar/SfxDownloader.php:315-326`) previously called a bare
`unlink($destination)` to remove a partial download. If that `unlink()`
failed (read-only mount, foreign file ownership, SELinux denial), a
**truncated** artifact stayed on disk and the next `fetch()` treated
`is_file($destination)` as a complete download — silent data corruption
that never self-heals.

I hardened it to mirror exactly the pattern that #670 already applied to
the zip-extraction catch in the same class:

```php
if ($failed && is_file($destination)) {
    $removed = @unlink($destination);
    if (!$removed) {
        error_log(sprintf(
            'Unable to remove partial SFX download "%s"; the truncated file stays on disk and the next fetch() will trust it as a complete download. Remove it manually.',
            $destination,
        ));
    }
}
```

Key points, all copied from the existing zip catch so the two paths read
identically:

- `@unlink()` — suppress the PHP warning; the `@` operator is intentional
  and matches the existing hardened paths (no point emitting a second
  warning when we are about to emit our own).
- Capture the boolean return value and branch on `!$removed`.
- `error_log()` the failure with a message telling the operator the
  truncated file is still on disk and must be removed by hand.
- The warning is guaranteed non-throwing: `error_log()` returns `bool`
  and is never wrapped in anything that throws, so the already-propagating
  exception from the `catch` keeps its original type/message untouched. No
  rethrow changes were needed.

I deliberately did **not** touch the checksum catch
(`src/Phar/SfxDownloader.php:101-116`) or the zip-extraction catch
(`src/Phar/SfxDownloader.php:135-149`) — they already implement the same
`@unlink()` + return-check + `error_log()` pattern and the issue scoped
this change to the `writeStream()` path only.

## What I rejected, and why

**Read-only directory trick (the one #670 uses for the zip test).**
Rejected. `writeStream()` opens the destination with
`fopen($destination, 'wb')` *before* the `try` block
(`src/Phar/SfxDownloader.php:281`). A read-only directory makes that
`fopen()` throw "Unable to open ... for writing" *before* the download
loop runs, so the `finally`-block cleanup — where the unlink lives — is
never reached. The trick only works for the zip catch because there the
file already exists (it was downloaded by `writeStream()` first). So it
cannot exercise the `writeStream()` finally-block unlink at all.

**vfsStream.** Rejected as heavier than needed. vfsStream would require
the whole `fetch()` download to flow through the in-memory FS, including
the HTTP read of the test server's response — more moving parts and a
different failure shape than "real partial file, failing removal".

**Custom stream wrapper (chosen).** A small test-only
`FailingUnlinkStreamWrapper` registered for the `failunlink://` protocol
proxies every filesystem op to a real base directory *except* `unlink()`,
which always returns `false`. The destination directory `failunlink:///wrap`
maps to `$tempDir/wrap`, so the download writes a genuine partial file
while its eventual `unlink()` is forced to fail deterministically —
no dependence on filesystem permissions or running as root. This is the
most faithful reproduction of the production failure mode.

Two wrapper gotchas I hit and fixed during the work:
1. `failunlink:/big` (one slash after the scheme) is **not** dispatched to
   the wrapper — PHP requires `failunlink://` (two slashes). So the
   destination directory must be `failunlink:///wrap` (and `fetch()`'s
   `rtrim($dir, '/') . '/' . $file` keeps the `//` once the scheme is
   present).
2. `realPath()` strips the scheme prefix literally with a regex rather
   than `parse_url(..., PHP_URL_PATH)`, because the latter mishandles the
   trailing-slash boundary and produced a wrong base path.

## What I was unsure about

- **Whether to append to `docs/helpers/faq.md` directly.** The repo's
  `docs/helpers/README.md` rule #2 states only the retro step writes
  there; `coder`/`review` subagents *propose* candidate entries and the
  retro step (`docs/workflow.md` steps 15/16) commits them. I therefore
  did **not** edit `faq.md`. I instead propose the exact candidate
  one-line note in my report and in `findings-coder.md`, to be landed by
  the retro step. If the parent wants it landed now, the proposed text is
  ready to paste verbatim.
- **Class-in-file count.** The wrapper is a second class in the test
  file. `php-cs-fixer` here does not enable `one_class_per_file`, so this
  is lint-clean; it also keeps the helper next to its only consumer. If a
  future lint rule forbids multiple classes per test file, move the
  wrapper to `tests/Phar/FailingUnlinkStreamWrapper.php`.
