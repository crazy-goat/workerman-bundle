# Security

## Header Injection Protection

The `RequestConverter` applies security hardening when propagating HTTP headers from Workerman to Symfony:

### Header Names Containing Underscores

HTTP field names may legally contain underscores, but PHP's CGI-style server bag cannot preserve the distinction between a dash and an underscore. Both `X-Forwarded-For` and `X-Forwarded_For` would otherwise become `HTTP_X_FORWARDED_FOR`. If a trusted proxy supplies the dash-spelled header, an attacker could append the underscore-spelled variant and control which value Symfony receives. The same collision could bypass a proxy rule that strips an application header such as `X-Internal-Admin`.

The bundle therefore discards every incoming header whose name contains `_` before constructing the Symfony server bag. A warning containing the dropped header name is written once per worker, so legitimate clients depending on underscore-containing names can be diagnosed without allowing an attacker to flood the logs on every request. This is the default and is intentionally not configurable: Workerman is the front server and must enforce the same boundary operators already expect from nginx (`underscores_in_headers off`) and Apache.

This filtering does not affect ordinary dash-spelled headers or the CGI convention for `Content-Type`, `Content-Length`, and `Content-MD5`: for example, `Content-Type` still becomes `CONTENT_TYPE`, not `HTTP_CONTENT_TYPE`.

### Cookie Header (RFC 6265)

When multiple `Cookie` header lines are present in the request, values are joined with `; ` as required by RFC 6265, rather than the standard HTTP `, ` separator. This prevents cookie smuggling where a `,` byte in a cookie value could be misinterpreted as a separator between cookies.

**Before (vulnerable):**
```
Cookie: session=abc123
Cookie: token=xyz789
→ HTTP_COOKIE: "session=abc123, token=xyz789"
→ Cookies parsed as one cookie: session=abc123, token=xyz789
```

**After (hardened):**
```
Cookie: session=abc123
Cookie: token=xyz789
→ HTTP_COOKIE: "session=abc123; token=xyz789"
→ Cookies correctly parsed as two cookies: session=abc123, token=xyz789
```

### Duplicate Sensitive Headers

Duplicate `Host`, `Content-Length`, and `Authorization` headers are suspicious and may indicate request smuggling or header injection attacks. Only the first value of each is propagated to Symfony; subsequent values are silently discarded.

- **Host**: Prevents Host-header poisoning attacks
- **Content-Length**: Prevents request smuggling via conflicting Content-Length values
- **Authorization**: Prevents authorization header injection

### Control Character Rejection

Header values containing control characters (`\x00-\x08`, `\x0B`, `\x0C`, `\x0E-\x1F`, `\x7F`) are rejected with an `\InvalidArgumentException`. This prevents:

- HTTP response splitting via CR/LF injection in header values
- Log forging via control characters in custom headers
- Protocol-level attacks through malformed header values

### Request URI and Method Validation

The `RequestConverter` also validates:

- **URI**: Control characters in the request URI are rejected (defense against log forging and URI-based access bypass)
- **Method**: Only uppercase ASCII letters are allowed (stricter than RFC 7230 to minimise routing bypass attacks), with a maximum length of 32 characters

## Middleware Header Re-Injection (Trusted-Proxy Bypass)

The bundle exposes `Request::setHeader()` (and its deprecated alias `withHeader()`) so middleware can mutate request headers in place — for example, to attach authentication tokens, override routing hints, or normalise client-supplied values. These methods are intentionally public to every middleware in the pipeline.

### The risk

Trust-sensitive headers — `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port`, `X-Forwarded-Ssl`, and the standardised `Forwarded` header — communicate **client-supplied network information** that downstream code uses to reconstruct the original request origin. Symfony's `Request::setTrustedProxies()` / `setTrustedHosts()` filtering exists precisely to prevent untrusted clients from spoofing these values.

Because `setHeader()` mutates the request **after** the bundle's own header sanitization has run, any middleware that re-injects these headers from untrusted input re-creates the trusted-proxy bypass class of bugs the bundle works to avoid. The trust boundary is the middleware contract: callers must understand that these methods bypass any earlier sanitization. Header mutations are restored after each request dispatch, preventing Workerman's request cache from replaying them to a later request with the same raw buffer.

### Recommended pattern

- **Run trusted-proxy filtering last.** If your application relies on Symfony's `setTrustedProxies()` / `setTrustedHosts()`, ensure that filtering runs **after** any middleware that calls `setHeader()` / `withHeader()`. The bundle does not enforce an ordering — middleware authors are responsible.
- **Scope-limit forwarding-header writes.** If a middleware legitimately needs to set `X-Forwarded-*` (e.g. a load-balancer-aware middleware that knows the proxy topology), restrict that capability to a small, audited set of services rather than exposing it to arbitrary middleware.
- **Treat middleware-supplied values as untrusted.** Even when a middleware is well-behaved, downstream code should treat any header value as attacker-controllable unless the middleware contract explicitly guarantees otherwise.
- **Prefer Symfony's `Request::setTrustedProxies()`** over re-injecting `X-Forwarded-*` from middleware. Symfony's mechanism is the canonical way to declare which upstream IPs are trusted proxies and which forwarded headers to honour.

### When this matters

- **Reverse-proxy deployments** where the application sits behind nginx, HAProxy, or a cloud load balancer and uses `X-Forwarded-*` to reconstruct the client IP / scheme / host.
- **Multi-tenant middleware pipelines** where third-party middleware is loaded dynamically and may not be fully audited.
- **Authentication and rate-limiting middleware** that branches on `X-Forwarded-For` to identify clients — a spoofed value here can bypass IP-based rate limits or impersonate other tenants.

## Trusted Host Enforcement

Host-header poisoning is a class of attack where an attacker controls the `Host` header sent to the server, potentially affecting password-reset links, cache keys, and routing decisions made by the application.

By default, all `Host` header values from incoming requests are accepted. To restrict which hostnames your application responds to, configure `trusted_hosts` in your Workerman configuration:

```yaml
workerman:
    trusted_hosts:
        - '^example\.com$'
        - '^api\.example\.com$'
```

Each entry is a regular expression pattern (without delimiters). Symfony adds the delimiters automatically. A request whose `Host` header does not match any pattern will be rejected with a `SuspiciousOperationException`, resulting in a 400 response.

### Interaction with Symfony's `framework.trusted_hosts`

If you also configure `framework.trusted_hosts` in Symfony, note that:

- `workerman.trusted_hosts` is enforced **inside the Workerman worker process**, before the Symfony kernel handles the request.
- If both are configured, the Workerman-level enforcement is sufficient — the Symfony-level setting is redundant but harmless.
- If you use PHP-FPM alongside Workerman workers, configure both independently.

### When this matters

Configure `trusted_hosts` when your application generates absolute URLs based on the incoming `Host` header (e.g., password-reset emails, webhook callbacks, OAuth redirects). Without it, an attacker can craft a request with a spoofed `Host` header and trick the application into generating URLs pointing to an attacker-controlled domain.

## Static Files Protection

`StaticFilesMiddleware` serves files from a configured root directory. Register it as a service and add it to the server's `middlewares` list. This middleware applies security hardening to prevent accidental exposure of sensitive files:

### Built-in Denylist

The following are **always blocked** (requests return 404):

- **Dotfiles and dot-directories**: Any path component starting with `.` is rejected (e.g., `.env`, `.git/HEAD`, `.htaccess`, `.hidden/secret.txt`).
- **Editor backup residue**: any name ending in `~` (vim/emacs backups, e.g. `index.php~`) and any name wrapped in `#...#` (emacs autosaves) is rejected. The `#...#` rule is enforced defensively at the path-component level — normal HTTP path parsing already strips `#` fragments, so fragment-style names are in practice unreachable through URLs.
- **Source and executable extensions**: `.php`, `.phar`, `.phtml`, `.phps` and `.inc` files are never served. The check applies to every dot-separated segment of the suffix, so a blocked extension is caught wherever it appears in a compound name (`x.php.bak`, `x.php.txt`, `x.phar.gz`).
- **Credentials, dumps and logs**: `.sql`, `.log`, `.pem`, `.key`, `.crt`, `.sqlite`, `.sqlite3`, `.db` — also checked in every segment of a compound suffix.
- **Editor backup and deploy-residue extensions**: `.bak`, `.orig`, `.rej`, `.save`, `.swp`, `.swo`, `.tmp`, `.old`, `.dist` — blocked as the final extension of a file only. An interior segment (`app.dist.js`) or a directory name (`assets.dist/`) is not a leak signal on its own; the contents are still covered by the rules above (and by the allowlist when configured).
- **Well-known leak files**: `composer.json`, `composer.lock`, and `package.json` are blocked.
- **Server configuration files**: `.htaccess` and `.htpasswd` are blocked.

### Extension Allowlist

To restrict which file types are served, configure an explicit extension allowlist:

```yaml
# config/services.yaml
services:
    workerman.middleware.static_files:
        class: CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware
        arguments:
            $rootDirectory: '%kernel.project_dir%/public'
            $allowedExtensions:
                - 'css'
                - 'js'
                - 'png'
                - 'jpg'
                - 'jpeg'
                - 'gif'
                - 'webp'
                - 'svg'
                - 'woff'
                - 'woff2'
                - 'ico'
                - 'html'
                - 'json'
                - 'txt'
```

```yaml
# config/packages/workerman.yaml
workerman:
    servers:
        - name: 'Web'
          listen: 'http://0.0.0.0:80'
          middlewares:
              - workerman.middleware.static_files
```

When `allowed_extensions` is set, only files with one of the listed extensions are served — all others return 404. Files without an extension, including names ending in a dot, are not served. The denylist (dotfiles, `.php`, etc.) takes precedence and is always enforced regardless of the allowlist setting.

### Symlink Protection

By default, `StaticFilesMiddleware` refuses to serve files that are accessed through a symlink under the static root directory (`follow_symlinks: false`). This prevents an attacker or a compromised tool from creating a symlink inside the public directory to expose files outside the intended root.

To restore the previous behaviour and allow symlinks to be followed, set `$followSymlinks: true`:

```yaml
# config/services.yaml
services:
    workerman.middleware.static_files:
        class: CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware
        arguments:
            $rootDirectory: '%kernel.project_dir%/public'
            $followSymlinks: true
```

```yaml
# config/packages/workerman.yaml
workerman:
    servers:
        - name: 'Web'
          listen: 'http://0.0.0.0:80'
          middlewares:
              - workerman.middleware.static_files
```

When `follow_symlinks` is `false` (default), any path component inside the root directory that is a symlink will cause the request to be treated as a non-existent file, passing control to the next middleware.

### Security Considerations

- **Keep `$rootDirectory` isolated**: Point `$rootDirectory` to a dedicated public directory (e.g., `%kernel.project_dir%/public`). Never set it to the project root or a directory containing `.env`, source code, or VCS metadata.
- **Prefer the allowlist over the denylist**: without `allowed_extensions`, the default posture is "serve everything except the denylist above" — safe for a directory that contains only public assets, but the denylist is a last line of defence, not a guarantee. Configure `allowed_extensions` to only permit the file types your application actually serves as static assets.
- **Use the allowlist**: Configure `allowed_extensions` to only permit the file types your application actually serves as static assets.
- **Disable symlinks**: Keep `$followSymlinks: false` (default) to prevent symlink-based file disclosure unless your application explicitly requires symlinks inside the public directory.
- **404 for blocked files**: Denied files always return a 404 response (identical to non-existent files). This prevents attackers from probing whether a blocked file exists.

## Connection Timeouts (Slowloris Protection)

The HTTP server exposes configurable timeouts to protect against slowloris-style denial-of-service attacks, where a small number of clients exhaust worker concurrency by sending data very slowly or keeping connections idle indefinitely.

### connection_timeout

The maximum time (in seconds) to wait for a complete request (headers + body) on a newly established connection. If the client does not send the complete request within this window, the connection is closed. This prevents slow-read attacks where an attacker sends headers or body data byte-by-byte.

Default: `120` seconds.

### keepalive_timeout

The maximum idle time (in seconds) to keep a keep-alive connection open after the previous request has been fully processed. If no new request arrives within this window, the connection is closed. This prevents idle connections from consuming worker capacity indefinitely.

Default: `30` seconds.

### body_size_cap (per-server)

In addition to the global `max_package_size`, each server can be configured with a per-server `body_size_cap` that overrides the global limit for that specific server. This allows tightening limits on public-facing endpoints while keeping larger limits for internal endpoints.

```yaml
workerman:
    max_package_size: 10485760  # 10 MB global default

    servers:
        - name: 'Api'
          listen: 'http://0.0.0.0:80'
          body_size_cap: 1048576  # 1 MB for this server (overrides global)

        - name: 'Upload'
          listen: 'http://0.0.0.0:8080'
          body_size_cap: 52428800  # 50 MB for file uploads
```

### Example Configuration

```yaml
workerman:
    connection_timeout: 60
    keepalive_timeout: 15

    servers:
        - name: 'Web'
          listen: 'http://0.0.0.0:80'
```

### Security Considerations

- **connection_timeout protects against slowloris**: Without this timeout, an attacker can open many connections and send data extremely slowly, holding each worker process indefinitely. Setting this to a reasonable value (e.g., 30-120 seconds) limits the window of exposure.
- **keepalive_timeout limits idle connections**: Long-lived idle keep-alive connections reduce the number of available worker slots. A short keepalive timeout (e.g., 15-30 seconds) frees capacity quickly.
- **body_size_cap for defense-in-depth**: Even with a global `max_package_size`, setting tighter per-server limits on endpoints that expect small payloads (e.g., API endpoints) provides an additional layer of protection against oversized payloads.
- **Timers are per-connection**: Each connection's timers are independent. A slowloris attack on one connection does not affect timing on others.

## SSL Certificate and Key Validation

When configuring HTTPS or WSS servers, the `local_cert` and `local_pk` options specify the paths to the SSL certificate and private key files. These paths are validated before being passed to the TLS stream context:

### Regular File Check

Both paths must point to **regular files**. Configuration pointing to:
- **Directories** are rejected with a clear error message.
- **FIFO/named pipes** are rejected.
- **Device files** (e.g., `/dev/zero`, `/dev/random`) are rejected.

This prevents an attacker or misconfiguration from causing the TLS stack to read unbounded bytes from a device file or hang on a FIFO.

### Symlink Protection

Symlinked certificate and key paths are **rejected by default**. This prevents:
- **Symlink-based file disclosure**: An attacker who controls a compromised tool or deployment process cannot create a symlink pointing the cert/key to a different file.
- **Unintended key material exposure**: A symlink swapped at runtime would load different credentials than expected.

### Error Messages

- `SSL certificate path must not be a symlink: <path>`
- `SSL private key path must not be a symlink: <path>`
- `SSL certificate path must be a regular file: <path>`
- `SSL private key path must be a regular file: <path>`

These checks are applied in addition to the existing `is_readable()` validation, which catches non-existent files and permission issues.

## SFX Download Protection (Zip-Slip)

The `SfxDownloader` downloads and extracts `phpmicro.sfx` from upstream HTTPS mirrors. Before extracting a downloaded ZIP archive, each entry name is validated against path traversal attacks (zip-slip):

- **Backslashes**: Entry names containing backslashes (`\`) are rejected.
- **Absolute paths**: Entry names starting with `/` or a Windows drive letter (`C:\`) are rejected.
- **Path traversal**: Entry names containing `..` segments after normalization are rejected.
- **Destination containment**: Each entry is checked to ensure it resolves to a path inside the destination directory.

If any entry fails validation, the build aborts with a `\RuntimeException`.

## SFX Download Protection (Redirect Scheme)

SFX downloads are always redirected manually: automatic redirect following is disabled in the
stream context (`follow_location: 0`) in **every** mode, so PHP's `http` wrapper never follows a
redirect on the bundle's behalf. Each hop is inspected before it is followed:

- **Cross-scheme downgrades (HTTPS → HTTP) are blocked** with a hard error
- **Redirects to any non-HTTP(S) scheme** (`file://`, `php://`, `ftp://`, ...) are blocked
  with a hard error, regardless of the origin scheme
- Redirects within HTTP(S) (including HTTP → HTTPS upgrades) are still followed, up to the
  5-hop limit; exceeding the limit errors
- A redirect target that cannot be resolved against the base URL is rejected with an error

This policy is active in both `--insecure` and default mode: `--insecure` (or
`build.sfx.allow_insecure: true`) disables TLS peer verification only (`verify_peer` /
`verify_peer_name`) and does not relax the redirect policy. Whether a checksum is configured
has no effect on it either.

Additionally, downloads are capped at a maximum size (256 MiB by default): a response
exceeding the limit aborts the download and removes the partial file, so a hostile or
misconfigured mirror cannot fill the filesystem.

## Status File TOCTOU Protection

`ServerManager` reads and removes status and connections files written by the Workerman master process (`SIGIOT` for status, `SIGIO` for connections). The read-then-unlink sequence is a classic TOCTOU (time-of-check, time-of-use) vulnerability: a local attacker (or another process) on the same host can swap the file between the read and the unlink, redirecting the unlink at an arbitrary path.

### Defence

The `consumeFile()` method uses an **atomic rename-before-read** pattern:

1. The file at `$path` is atomically renamed to a unique temporary path within the same directory (`rename()` is atomic on the same filesystem).
2. After the rename, `$path` no longer exists — a symlink swap at the original path cannot redirect the subsequent read or unlink.
3. The content is read from the renamed temp path.
4. The renamed temp path is unlinked. Since this path was created by our own `rename()`, it is not subject to symlink swaps.
5. Unlink failures are surfaced to the PSR-3 logger instead of being silently suppressed with `@`.

### When this matters

- **Multi-user hosts**: An attacker with write access to the runtime directory (or a compromised sibling process) cannot redirect the `unlink()` to a different file.
- **Shared runtime directories**: If the runtime directory is shared between multiple services or users, the TOCTOU window is broader.

### Verification

- The `testConsumeFileRemovesOriginalInodeAfterRename` test verifies that after `consumeFile()`, a new file at the original path has a different inode.
- The `testConsumeFileCreatesNoOrphanedTempFiles` test verifies cleanup.
- The `testConsumeFileReturnsContentAndRemovesTempFile` test verifies normal operation.

## Runtime Directory Permissions

Runtime directories (PID file, log files, stdout files) are created with mode `0700` (owner-only access). This prevents other users on multi-user systems from reading process-control artifacts such as PID files and status files.

### When this matters

- **Multi-user hosts**: Shared CI runners, containers with multiple service accounts, or any environment where the process user should not be able to read another user's runtime files.
- **PID file protection**: An attacker who can read the PID file can signal the workerman master process.
- **Status file protection**: Status files contain internal process state that should not be visible to other users.

### Behaviour

- Runtime directories are created automatically with `0700` permissions when they do not exist.
- If the directory already exists, its permissions are not modified — ensure it was created with appropriate restrictive permissions if it was pre-created.
- To verify permissions on a running system:
  ```bash
  stat -c '%a %n' var/run/ var/log/
  ```

## Config Cache File Protection

The `ConfigLoader` reads the application configuration from a cached PHP file generated during cache warm-up (`{cacheDir}/workerman/config.cache.php`). This file is loaded via `require` at boot time.

### Trust Requirement

The configuration cache file is a PHP file that gets executed. Any attacker who can write to the cache directory can achieve arbitrary code execution by modifying this file. The bundle relies on the standard Symfony assumption that the **cache directory is not writable by untrusted users**.

### Permission Validation

To mitigate misconfiguration, the bundle validates the cache file **and its
containing directory** before loading it:

- **Directory write check**: If the cache directory is world-writable
  (permissions include `o+w`), loading is **refused**. Replacing the cache
  file requires write permission on the directory, not on the file itself,
  so the directory is the primary object checked.
- **Directory group check**: If the cache directory is group-writable
  (`g+w`) by a group the current process does not belong to — neither its
  effective group nor any supplementary group — loading is **refused**. A
  group-writable directory whose group is a group the process belongs to
  (e.g. `0770` combined with `chgrp` to the webserver group) is
  accepted.
- **Ownership check**: If the cache file is not owned by the process's
  effective user ID, loading is **refused** — a file another user could
  replace would be owned by that user.
- **World-writable file check**: As a secondary signal, a cache file that is
  itself world-writable is **refused**.
- **Unreadable metadata**: If the file or directory metadata cannot be read
  (e.g. on filesystems that do not report permissions), a **warning naming
  the path is emitted** — logged via the PSR-3 logger when one is configured,
  raised as an `E_USER_WARNING` otherwise — and loading proceeds; the check
  degrades loudly instead of silently disappearing.
- **Scope**: The checks cover POSIX owner and permission bits only. They do
  not cover ACLs, extended attributes, or filesystems that do not support
  POSIX permissions.
- **Ownership requirement**: the cache file must be written and loaded by
  the same user. Warm up the cache with the runtime user, or `chown` the
  cache file to that user after warm-up.

### When this matters

- **Multi-tenant environments**: Shared hosting or CI runners where the cache directory might be accessible to other users.
- **Containerised deployments**: Containers with overly permissive `umask` settings (e.g., `umask 0000`) can produce world-writable cache files and directories.
- **Development setups**: Developers running with `umask 0000` or cache directories created with `0777` permissions.

### Remediation

Ensure the cache directory has restrictive permissions:

```bash
chmod 0700 var/cache/
```

Or, if the web server and CLI users differ, use a shared group:

```bash
chmod 0750 var/cache/
chgrp <webserver-group> var/cache/
```

Because the cache file must also be **owned by the runtime user** (see the
Ownership check above), a cache warmed up by a different user — e.g. a
deploy script running as `root` or a CI user, with the server later
running as `www-data` — is refused at boot. Either warm up with the
runtime user:

```bash
sudo -u <runtime-user> bin/console cache:warmup
```

or re-own the cache file after warm-up:

```bash
chown <runtime-user> var/cache/workerman/config.cache.php
```

## SFX Checksum Requirement

The build **fails** if no SHA-256 checksum is configured, unless `--unsafe-no-checksum` is explicitly
passed. This ensures supply-chain integrity by default:

- `--sfx-checksum=HASH` or config `build.sfx.sha256` → the downloaded artifact is verified
  against the checksum immediately after download, **before** it is extracted
- `--unsafe-no-checksum` → no verification (not recommended)
- Neither → build aborts with an error

## Master Process Fingerprint (PID File Hardening)

`ServerManager` identifies the Workerman master process before sending signals (SIGINT, SIGQUIT, SIGUSR1, SIGUSR2, SIGIOT, SIGIO). Without hardening, the identification relied on a loose `str_contains($cmdline, 'WorkerMan')` check against `/proc/$pid/cmdline` — a substring match that could misidentify any co-located process whose command line happens to contain the word "WorkerMan" (an unrelated binary, a script with that name in its path, a build tool). A misidentification could lead to `SIGKILL` being sent to an unrelated process, causing a denial-of-service on adjacent services.

A later revision added `|| str_contains($cmdline, 'php')` to that fallback, which made the check **vacuous**: every PHP process on the host matched — `php-fpm` children, a `composer` run, a cron script, an unrelated Symfony command, even the process asking the question. This was removed again (issue #584); see [Fallback Behaviour](#fallback-behaviour) for what the check enforces today.

### Defence

`ServerManager` writes a **fingerprint file** alongside the PID file (`<pid_file>.fingerprint`). The fingerprint records:

- **PID**: the process ID of the master
- **Start time**: clock ticks since boot, read from `/proc/$pid/stat` field 22. **Linux only** — on POSIX without `/proc` the value is recorded as `0` and the start-time check is disabled.
- **UID**: the Unix user ID of the master process

Before sending any signal, `ProcessInspector` reads the fingerprint and verifies that the candidate PID matches **all three** fields:

1. **PID match**: the candidate PID equals the recorded PID
2. **UID match**: the candidate process is owned by the same Unix user
3. **Start time match**: the candidate process has the same start time as the recorded fingerprint (Linux only)

The start time check is the strongest defense against PID reuse: even if the original master process died and its PID was reassigned to an unrelated process, the new process will have a different start time and will be rejected.

**Non-daemon mode**: the fingerprint is written by `ServerManager` before the runner starts (the CLI process becomes the master).

**Daemon mode**: `Worker::daemonize()` forks twice and the launcher process exits, so the launcher PID is not the master PID. The bundle runs Workerman through `MasterWorker` (a `Workerman\Worker` subclass), which records the fingerprint from **inside the real master process** immediately after the PID file is written (`saveMasterPid()`). The fingerprint therefore describes the actual master PID in every mode — the daemon-mode gap documented in earlier revisions of this file is closed (issue #584). Until the master has started, `ProcessInspector` fails closed rather than signalling an unverifiable PID.

### Fallback Behaviour

If the fingerprint file does not exist (e.g. after upgrading from a version that did not write fingerprints, or if the write failed), `ProcessInspector` falls back to a strict command-line check against the process title Workerman assigns to its master process — `WorkerMan: master process ...`. The check requires that exact title; a process whose cmdline merely contains the word "php" (or "WorkerMan" elsewhere) is **rejected**.

**Runtime dependence**: `cli_set_process_title()` rewrites the argv memory area, and the effect is visible in `/proc/$pid/cmdline` on most PHP builds — but on PHP >= 8.5 some builds keep the original argv. On those runtimes the strict fallback may also reject the *real* master when no fingerprint exists; the command then fails closed ("not running"). The fingerprint is written automatically on every start (including daemon mode), so a single restart after upgrading restores full control-plane operation. This is the documented trade-off: refusing to signal an unverifiable PID is preferred over signalling an unrelated process.

Verification is **fail-closed**: when the cmdline cannot be read (a process owned by another user under `hidepid`, a non-Linux host without `/proc`), `isMasterRunning()` logs a warning and returns `false` — the caller refuses to signal. Earlier revisions returned `true` in this situation (fail-open in the direction of sending a signal); that behaviour is gone (issue #584).

### When this matters

- **Multi-user hosts**: Shared CI runners, containers with multiple service accounts, or any environment where an attacker could spawn a process whose command line contains "WorkerMan" (or any plain PHP process — earlier revisions accepted any cmdline containing "php").
- **Shared runtime directories**: If the PID file directory is shared between multiple services or users, an attacker could write a fake PID file pointing to an unrelated process.
- **PID reuse**: After a master process dies without a clean `stop()` (OOM kill, `SIGKILL`, host reboot with a persisted pid file), its PID may be reassigned to an unrelated process. The start time check prevents the new process from being misidentified as the master; without a fingerprint, the strict cmdline check (master process title) rejects the reused PID.

### Verification

- `testDaemonModeWritesMasterFingerprint` (in `tests/WorkermanCommandTest.php`) verifies end to end that a `start -d` deployment produces a fingerprint for the real master PID (issue #584).
- `testStopWithStalePidFileDoesNotSignalForeignProcess` (in `tests/WorkermanCommandTest.php`) runs the console `stop` command with a stale pid file pointing at a plain PHP process and asserts no signal is sent (issue #584 end-to-end regression).
- `testIsRunningUsesFingerprintWhenAvailable` (in `tests/ServerManagerTest.php`) verifies that `isRunning()` returns true when the fingerprint matches the PID file PID.
- `testIsRunningRejectsUnrelatedProcessWithFingerprint` (in `tests/ServerManagerTest.php`) verifies that `isRunning()` returns false when the fingerprint PID does not match the PID file PID.
- `testIsRunningWithoutFingerprintRejectsPlainPhpProcess` and `testIsRunningWithoutFingerprintAcceptsMasterTitleProcess` (in `tests/ServerManagerTest.php`) verify the strict cmdline fallback: a plain PHP process is rejected, a process carrying the `WorkerMan: master process` title is accepted (issue #584).
- `testStopRefusesToSignalPlainPhpProcessWithoutFingerprint`, `testReloadRefusesToSignalPlainPhpProcessWithoutFingerprint`, `testGetStatusRefusesToSignalPlainPhpProcessWithoutFingerprint` and `testGetConnectionsRefusesToSignalPlainPhpProcessWithoutFingerprint` (in `tests/ServerManagerTest.php`) verify that every signal path refuses to signal when verification fails (issue #584).
- `testKillOrphanedIntermediateForkWithFingerprintDoesNotKillUnrelatedProcess` (in `tests/ProcessInspectorTest.php`) verifies that `killOrphanedIntermediateFork()` does not kill a process whose PID does not match the fingerprint.

## Composer Audit Advisory Suppression Policy

`composer.json` enables `audit.block-insecure: true`, which prevents Composer
from installing packages with known security vulnerabilities. The
`audit.ignore` list is **intentionally empty** — no Composer security
advisory is suppressed.

### Why the ignore list is empty

Suppressed advisories shadow real risk. The `audit.ignore` field is global:
an entry hides the advisory regardless of whether the affected package is
reached via `require` (production) or `require-dev` (development only). If a
transitive non-dev dependency ever pulls in the affected package, the
suppression hides the warning, shipping a known vulnerability to production
users.

### How dev-only advisories are handled

Advisories that affect only `require-dev` dependencies (e.g. PHPUnit,
development-only Symfony components) are **not suppressed**. Instead, CI and
production audits run `composer audit --no-dev`, which excludes the entire
`require-dev` dependency set. This means:

- Development-only advisories do not block CI or production installs.
- Production-only advisories are never hidden by a global suppression.
- The `audit.ignore` list stays empty, so no advisory is ever silently
  hidden from `composer audit` (full, with dev) run by contributors locally.

### Historical context

The `audit.ignore` list previously contained three advisory IDs
(`PKSA-d1rr-z8zb-qnm7`, `PKSA-py8y-z9q7-q197`, `PKSA-xf5h-y6vg-qj98`),
all affecting packages reachable only through `require-dev`
(`phpunit/phpunit` and `symfony/runtime` in development-only Symfony
versions). These were suppressed to keep CI green when upstream had not yet
released a patch. After verification that each affected package was dev-only,
the entries were removed and replaced with the `--no-dev` audit strategy.

### Policy for future suppressions

If an advisory must be suppressed temporarily:

1. **Verify scope**: Run `composer why <package>` and confirm the affected
   package is only reachable through `require-dev`. If it is reachable via
   `require`, do not suppress — update the dependency instead.
2. **Document the justification**: Add an entry in `docs/security.md` under
   this section, with the advisory ID, affected package, CVE/link, the
   `composer why` output proving dev-only scope, and the date.
3. **Remove promptly**: Remove the `audit.ignore` entry as soon as the
   upstream package releases a patched version.
4. **Prefer `--no-dev`**: If the advisory only affects dev dependencies,
   prefer the `--no-dev` audit strategy over suppressing the advisory ID.

### Verification

- `testAuditIgnoreListIsEmpty` (in `tests/ComposerConfigTest.php`) asserts
  that `audit.ignore` is an empty array, preventing accidental re-introduction
  of suppressed advisories.
- `testComposerAuditNoDevIsClean` (in `tests/ComposerConfigTest.php`) runs
  `composer audit --format=json --no-dev` and asserts that the production
  dependency set has zero advisories.
- `testComposerAuditProducesValidJson` (in `tests/ComposerAuditE2ETest.php`)
  verifies the audit command runs and produces valid JSON output.
