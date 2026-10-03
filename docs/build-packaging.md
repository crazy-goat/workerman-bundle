# PHAR and Standalone Binary Packaging

WorkermanBundle supports packaging your entire Symfony application into a single-file PHAR archive or a standalone binary for simplified deployment.

## Quick Start

### PHAR Build

```bash
# Build a PHAR archive (requires PHP on target system)
php -d phar.readonly=0 bin/console workerman:build:phar

# Run from the PHAR
php app.phar workerman:server start -d
php app.phar cache:clear
php app.phar doctrine:migrations:migrate
```

### Standalone Binary Build

```bash
# Build a self-contained binary (no PHP required on target)
php -d phar.readonly=0 bin/console workerman:build:bin

# Run from the binary
./app.bin workerman:server start -d
```

## Configuration

All `build` keys, with their defaults, are in [configuration.md](configuration.md#build).
`runtime_dir` is in [configuration.md](configuration.md#top-level-keys).

Example:

```yaml
# config/packages/workerman.yaml
workerman:
  build:
    build_dir: '%kernel.project_dir%/build'
    kernel_class: 'App\Kernel'
    phar_filename: 'app.phar'
    bin_filename: 'app.bin'
    exclude_patterns:
      - '/\.git/'
    exclude_files:
      - '.env.local'
```

The SFX source is chosen in this order: `--sfx-file`, `build.sfx.file`, `--sfx-url`, `build.sfx.url`, the default URL.

### `build.sfx.sha256`

The SHA-256 hex digest of the expected phpmicro.sfx artifact. When configured, the downloaded
artifact is verified against this checksum immediately after download, **before** it is
extracted — the extractor never runs over unverified bytes — protecting against supply-chain
attacks (corrupted download, man-in-the-middle substitution).
**Required** for all builds unless `--unsafe-no-checksum` is explicitly passed.

The `--sfx-checksum` CLI option overrides this config value when provided.

```bash
# Obtain the checksum for a specific PHP version
curl -sL "https://download.workerman.net/php/php8.3.micro.sfx" | sha256sum
# After obtaining a trusted copy, use its checksum in a subsequent build:
php -d phar.readonly=0 bin/console workerman:build:bin --sfx-checksum="$(sha256sum /path/to/trusted.sfx | cut -d' ' -f1)"
```

Cross-reference: the `build.sfx` node in `src/DependencyInjection/ConfigurationTreeBuilder.php`.

### `--unsafe-no-checksum`

Bypasses the mandatory checksum requirement and allows the download without SHA-256 verification.
Use only when:
- You are downloading from a trusted, local mirror
- You have verified the binary integrity through out-of-band means

Without this flag, the build **fails** with an error when no `--sfx-checksum` or `build.sfx.sha256`
is configured.

```bash
# Warning: skips checksum verification
php -d phar.readonly=0 bin/console workerman:build:bin --unsafe-no-checksum
```

### `build.sfx.allow_insecure`

Disables TLS peer verification (`verify_peer`, `verify_peer_name`) when downloading the SFX
binary. **Off by default** — keep it off unless you are serving phpmicro.sfx from a local
mirror with a self-signed certificate.

The `--insecure` CLI flag enables the same behavior.

Security implications when enabled:
- The connection is vulnerable to man-in-the-middle attacks
- Redirects are still policed exactly as in default mode (see `docs/security.md`):
  HTTPS → HTTP downgrades and non-HTTP(S) redirect targets are blocked in both modes
- Always pair with `build.sfx.sha256` to verify the binary after download

Cross-reference: the `build.sfx` node in `src/DependencyInjection/ConfigurationTreeBuilder.php`.

## Security

### `exclude_patterns` regex defence (issue #334)

User-supplied `exclude_patterns` are applied as PCRE regexes against every
file path during the build. To prevent accidental denial-of-service
through a pathological pattern (e.g. `(a+)+`, `(.+)*`, `(a|a)+`), the
build performs defense-in-depth:

1. **Structural lint at compile time.** Every pattern is scanned for
   nested unbounded quantifiers — a group whose body already contains a
   quantifier and is itself followed by a quantifier (`+`, `*`, `?`,
   `{n,m}`). Such patterns are rejected with `InvalidArgumentException`
   before the build ever traverses the source tree.
2. **PCRE compile check.** Each compiled regex is dry-run against a
   short probe string to confirm PCRE accepts it. Patterns that PCRE
   itself rejects are surfaced with a clear error message.
3. **Per-call backtrack limit.** Every `preg_match` call wraps the
   pattern in a temporary `pcre.backtrack_limit` of 1,000,000 (raised
   from PHP's default) and a `pcre.recursion_limit` of 100,000. Both
   ini values are restored after the call. If the limit still trips
   (catastrophic backtracking on a pattern the structural lint allowed
   through), `matches()` returns `false` instead of hanging the build.

Recommended safe idioms:

```yaml
workerman:
    build:
        exclude_patterns:
            # Good — simple glob-style, no nested quantifiers
            - '#\.git/#'
            - '#/tests/#'
            - '#^vendor/#'
```

If you need the matching power that nested quantifiers provide, rewrite
your pattern with **atomic groups** (`(?>...)`) — PCRE's native solution
to catastrophic backtracking:

```yaml
workerman:
    build:
        exclude_patterns:
            # Good — atomic group prevents backtracking
            - '#(?>src/foo|src/bar)+/#'
```

## How It Works

### PHAR Mode

The build command creates a PHAR archive containing your entire Symfony application (source, vendor, config). The embedded stub:

1. Detects the runtime directory (outside the PHAR)
2. Sets `APP_CACHE_DIR` and `APP_LOG_DIR` to writable paths
3. Creates `var/cache`, `var/log`, `var/run` directories with restrictive permissions (0700)
4. Loads `.env` from outside the PHAR (if present)
5. Boots Symfony Console for full CLI access

### BIN Mode

Builds on top of PHAR mode by concatenating:
```
[phpmicro.sfx] + [optional custom_ini header] + [app.phar]
```

The `phpmicro.sfx` is a static PHP interpreter. It's downloaded automatically from `https://download.workerman.net/php/php{VERSION}.micro.sfx`, or you can provide a local file via `--sfx-file` option.

### Writable Paths

In PHAR/BIN mode, all writable paths (cache, logs, PID files) are redirected outside the archive to the runtime directory:

| Path | Normal Mode | PHAR/BIN Mode |
|------|-------------|---------------|
| Cache | `{project}/var/cache/` | `{runtimeDir}/var/cache/` |
| Logs | `{project}/var/log/` | `{runtimeDir}/var/log/` |
| PID file | `{project}/var/run/` | `{runtimeDir}/var/run/` |

The runtime directory defaults to the directory containing the PHAR/BIN file, and can be overridden with `WORKERMAN_RUNTIME_DIR` env var.

## Limitations

- **No file monitor reload** — code updates require a restart (files are frozen inside the archive)
- **`.env` file must be external** — place it next to the PHAR/BIN file
- **phar.readonly must be Off** — both build commands fail otherwise. Pass `-d phar.readonly=0` to PHP, or set `phar.readonly=0` in php.ini during build
- **BIN builds are architecture-specific** — Linux x86_64 by default
- **User uploads must not go into PHAR** — configure upload paths outside the archive

## Commands

All options of `workerman:build:phar` and `workerman:build:bin` are in [Commands](commands.md#workermanbuildphar).

## References

- [webman PHAR packaging](https://webman.workerman.net/doc/en/others/phar.html)
- [webman binary packaging](https://webman.workerman.net/doc/en/others/bin.html)
- [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
- [phpmicro](https://github.com/dixyes/phpmicro)
