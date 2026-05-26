# PHAR and Standalone Binary Packaging

WorkermanBundle supports packaging your entire Symfony application into a single-file PHAR archive or a standalone binary for simplified deployment.

## Quick Start

### PHAR Build

```bash
# Build a PHAR archive (requires PHP on target system)
php bin/console workerman:build:phar

# Run from the PHAR
php app.phar workerman:server start -d
php app.phar cache:clear
php app.phar doctrine:migrations:migrate
```

### Standalone Binary Build

```bash
# Build a self-contained binary (no PHP required on target)
php bin/console workerman:build:bin

# Run from the binary
./app.bin workerman:server start -d
```

## Configuration

```yaml
# config/packages/workerman.yaml
workerman:
    # Writable runtime directory (defaults to project_dir)
    # In PHAR mode, automatically set to directory containing the PHAR
    runtime_dir: '%kernel.project_dir%'

    build:
        # Output directory for built artifacts
        build_dir: '%kernel.project_dir%/build'

        # Your Symfony Kernel class
        kernel_class: 'App\\Kernel'

        # Output file names
        phar_filename: 'app.phar'
        bin_filename: 'app.bin'

        # PHP version for phpmicro.sfx (default: current version)
        bin_php_version: 8.3

        # PHPMicro SFX source (priority: sfx-file CLI > sfx.file > sfx-url > sfx.url > default)
        sfx:
            url: null        # Custom download URL
            file: null       # Local path to phpmicro.sfx
            sha256: null     # SHA-256 hex digest for checksum verification (strongly recommended)
            allow_insecure: false  # Disable TLS peer verification (off by default; use only for local mirrors)

        # Files excluded from the PHAR
        exclude_patterns:
            - '/\.git/'
            - '/tests/'
            - '/var/'

        exclude_files:
            - '.env'
            - '.env.local'

        # Custom php.ini directives for the standalone binary (BIN mode only)
        custom_ini: |
            opcache.enable=1
            opcache.enable_cli=1
            opcache.jit=1255
            memory_limit=256M
```

### `build.sfx.sha256`

The SHA-256 hex digest of the expected phpmicro.sfx binary. When configured, the downloaded
SFX file is verified against this checksum before extraction, protecting against supply-chain
attacks (corrupted download, man-in-the-middle substitution). **Strongly recommended** for all
production builds.

```bash
# Obtain the checksum for a specific PHP version
curl -sL "https://download.workerman.net/php/php8.3.micro.sfx" | sha256sum
# Or after a successful download, verify inline:
php bin/console workerman:build:bin --sfx-checksum="$(sha256sum /path/to/downloaded.sfx | cut -d' ' -f1)"
```

Cross-reference: `src/DependencyInjection/ConfigurationTreeBuilder.php:306-309`.

### `build.sfx.allow_insecure`

Disables TLS peer verification (`verify_peer`, `verify_peer_name`) when downloading the SFX
binary. **Off by default** — keep it off unless you are serving phpmicro.sfx from a local
mirror with a self-signed certificate.

Security implications when enabled:
- The connection is vulnerable to man-in-the-middle attacks
- Redirects across schemes (HTTPS → HTTP) are followed, which can downgrade the download to plaintext
- Always pair with `build.sfx.sha256` to verify the binary after download

Cross-reference: `src/DependencyInjection/ConfigurationTreeBuilder.php:310-313`.

## How It Works

### PHAR Mode

The build command creates a PHAR archive containing your entire Symfony application (source, vendor, config). The embedded stub:

1. Detects the runtime directory (outside the PHAR)
2. Sets `APP_CACHE_DIR` and `APP_LOG_DIR` to writable paths
3. Creates `var/cache`, `var/log`, `var/run` directories
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
- **phar.readonly must be Off** — set `phar.readonly=0` in php.ini during build
- **BIN builds are architecture-specific** — Linux x86_64 by default
- **User uploads must not go into PHAR** — configure upload paths outside the archive

## Commands

### `workerman:build:phar`

```bash
php bin/console workerman:build:phar [options]

Options:
  -o, --output-dir=DIR   Output directory (default: config build.build_dir)
      --filename=NAME    Output filename (default: config build.phar_filename)
```

### `workerman:build:bin`

```bash
php bin/console workerman:build:bin [options]

Options:
  -o, --output-dir=DIR     Output directory
      --filename=NAME      Output filename (default: config build.bin_filename)
      --sfx-file=PATH      Local path to phpmicro.sfx
      --sfx-url=URL        URL to download phpmicro.sfx
      --php-version=VER    PHP version for static binary (e.g., 8.3)
```

## References

- [webman PHAR packaging](https://webman.workerman.net/doc/en/others/phar.html)
- [webman binary packaging](https://webman.workerman.net/doc/en/others/bin.html)
- [static-php-cli](https://github.com/crazywhalecc/static-php-cli)
- [phpmicro](https://github.com/dixyes/phpmicro)
