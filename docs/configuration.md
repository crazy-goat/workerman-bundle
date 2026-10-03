# Configuration

This page lists every config key and every environment variable of the bundle.
All keys live under `workerman:` in `config/packages/workerman.yaml`.

## See the live reference

The bundle can print all keys with their defaults and help texts:

```bash
bin/console config:dump-reference workerman
```

> **Note:** `bin/console` is the console of your application.
> It is not the `bin/` directory of this bundle.

## Top-level keys

| Key | Type | Default | What it does |
|-----|------|---------|--------------|
| `runtime_dir` | string | `%kernel.project_dir%` | A writable directory for cache, logs and PID files. In PHAR or BIN mode, the default is the directory of the PHAR or BIN file. The bundle creates sub-directories with mode 0700. The `WORKERMAN_RUNTIME_DIR` variable overrides it. See [build-packaging.md](build-packaging.md#writable-paths). |
| `user` | string or null | `null` | The Unix user of the processes. `null` means the current user. |
| `group` | string or null | `null` | The Unix group of the processes. `null` means the current group. |
| `stop_timeout` | int, seconds | `2` | The maximum time a child process may work before the master kills it. |
| `cache_warmup_timeout` | int, seconds, minimum 1 | `30` | The maximum time to wait for the cache warmup in the forked process. The `WORKERMAN_CACHE_WARMUP_TIMEOUT` variable overrides it. |
| `status_timeout` | int, seconds | `5` | The maximum time to wait for the status file after the `status` command sends a signal. |
| `pid_file` | string, not empty | `%kernel.project_dir%/var/run/workerman.pid` | The file that holds the PID of the master process. |
| `log_file` | string, not empty | `%kernel.project_dir%/var/log/workerman.log` | The Workerman log file. |
| `stdout_file` | string, not empty | `%kernel.project_dir%/var/log/workerman.stdout.log` | The file that gets all output (`echo`, `var_dump`) when the server runs as a daemon. |
| `max_package_size` | int, bytes | `10485760` (10 MB) | The maximum size of one request. A server can replace it with its own `body_size_cap`, which can be lower or higher. See [security.md](security.md#body_size_cap-per-server). |
| `connection_timeout` | int, seconds, minimum 0 | `120` | The maximum time to wait for a complete request. It protects against slowloris attacks. `0` turns it off. |
| `keepalive_timeout` | int, seconds, minimum 0 | `30` | The maximum time an idle keep-alive connection stays open. `0` turns it off. |
| `response_chunk_size` | int, bytes | `2048` | The chunk size of streamed responses. |
| `trusted_hosts` | list of strings | `[]` | Regular expressions for allowed `Host` headers. Write them without delimiters, for example `^example\.com$`. A request with another `Host` gets a 400 error. An empty list accepts all hosts. |

## servers

`servers` is a list. Each entry is one server: one listening socket and its workers.
A worker is a child process that handles requests.
The default of `servers` is an empty list.

Example:

```yaml
workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080
      processes: 4
      middlewares:
        - workerman.middleware.static_files
```

| Key | Type | Default | What it does |
|-----|------|---------|--------------|
| `servers[].name` | string | required | The name of the server process. It must not be empty. |
| `servers[].listen` | string or null | `null` | The listen address, for example `http://0.0.0.0:80`. It is required: if you leave it out, the server does not start. It starts with a scheme. Use `http://` or `https://`. |
| `servers[].local_cert` | string or null | `null` | The path to the certificate file (PEM). `https://` needs it. The bundle rejects symbolic links. See [security.md](security.md#ssl-certificate-and-key-validation). |
| `servers[].local_pk` | string or null | `null` | The path to the private key file (PEM). `https://` needs it. The bundle rejects symbolic links. |
| `servers[].processes` | int or null | `null` | The number of workers of this server. `null` means the number of CPUs times 2. In a container the CPU limit is used (cgroup v2 or v1), not the CPUs of the host. |
| `servers[].reuse_port` | bool | `false` | Turns on `SO_REUSEPORT`. Many processes can then use the same port. |
| `servers[].body_size_cap` | int or null, bytes, minimum 1 | `null` | The maximum request size of this server. `null` means the global `max_package_size`. |
| `servers[].middlewares` | list of service IDs | `[]` | The middlewares of this server. The first one is the outermost. See [Middlewares](../README.md#middlewares). |

## reload_strategy

A reload strategy tells the server when to replace a worker with a new one.
You can use all strategies together.
Each strategy has an `active` switch.

Example:

```yaml
workerman:
  reload_strategy:
    exception:
      active: true
    max_requests:
      active: true
      requests: 1000
      dispersion: 20
    memory:
      active: true
      limit: 134217728
```

| Key | Type | Default | What it does |
|-----|------|---------|--------------|
| `reload_strategy.exception.active` | bool | `true` | Reload the worker each time an exception is thrown while it handles a request. |
| `reload_strategy.exception.allowed_exceptions` | list of class names | `Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`, `Symfony\Component\Serializer\Exception\ExceptionInterface` | These exceptions do not cause a reload. |
| `reload_strategy.max_requests.active` | bool | `false` | Reload the worker after N requests. This helps against memory leaks. |
| `reload_strategy.max_requests.requests` | int | `1000` | The number of requests after which the worker reloads. |
| `reload_strategy.max_requests.dispersion` | int, percent | `20` | Makes the workers reload at different times. With 1000 requests and 20 percent, a worker reloads after 800 to 1000 requests. |
| `reload_strategy.file_monitor.active` | bool | `false` | Reload all workers when a file changes. Use it in `dev` only. |
| `reload_strategy.file_monitor.source_dir` | list of paths | `%kernel.project_dir%/src`, `%kernel.project_dir%/config` | The directories to watch. |
| `reload_strategy.file_monitor.file_pattern` | list of patterns | `*.php`, `*.yaml` | The files to watch inside `source_dir`. |
| `reload_strategy.file_monitor.polling_interval` | int, seconds, minimum 1 | `3` | The time between two checks. It is used only without `ext-inotify`. |
| `reload_strategy.file_monitor.max_files_per_tick` | int, minimum 1 | `500` | The maximum number of directory entries that one check looks at. It is used only without `ext-inotify`. |
| `reload_strategy.always.active` | bool | `false` | Reload the worker after each request. |
| `reload_strategy.memory.active` | bool | `false` | Reload the worker when it uses too much memory. |
| `reload_strategy.memory.limit` | int, bytes | `134217728` (128 MB) | The memory limit. The bundle measures PHP memory with `memory_get_usage()`. This is not the memory that `top` shows. |
| `reload_strategy.memory.gc_limit` | int, bytes | `100663296` (96 MB) | Above this memory, the worker runs `gc_collect_cycles()` to free memory. |
| `reload_strategy.memory.gc_cooldown` | int, seconds | `60` | The minimum time between two garbage collections. |

## build

The `build` keys set up the PHAR and the standalone binary.
See [build-packaging.md](build-packaging.md) for the commands and for what the keys do.

| Key | Type | Default | What it does |
|-----|------|---------|--------------|
| `build.build_dir` | string | `%kernel.project_dir%/build` | The output directory. |
| `build.kernel_class` | string | `App\Kernel` | The class name of your Symfony kernel. |
| `build.phar_filename` | string | `app.phar` | The name of the PHAR file. |
| `build.bin_filename` | string | `app.bin` | The name of the binary file. |
| `build.bin_php_version` | string or null | `null` | The PHP version of the binary. `null` means the current PHP version. |
| `build.sfx.url` | string or null | `null` | The URL to download `phpmicro.sfx` from. |
| `build.sfx.file` | string or null | `null` | The path to a local `phpmicro.sfx` file. |
| `build.sfx.sha256` | string or null | `null` | The SHA-256 digest of the SFX file. The bundle checks the download against it. |
| `build.sfx.allow_insecure` | bool | `false` | Turns off the TLS check of the download. Use it only for a local mirror. |
| `build.exclude_patterns` | list of regular expressions | `[]` | More files to leave out of the PHAR. The built-in rules always apply. |
| `build.exclude_files` | list of paths | `[]` | Files to leave out of the PHAR. The paths start at the project root. |
| `build.custom_ini` | string or null | `null` | Extra `php.ini` lines for the binary. It works in BIN mode only. |

## Deprecated keys

These keys are deprecated since version 0.9.3.
The bundle removes them in 1.0.
Use `StaticFilesMiddleware` instead.
See [security.md](security.md#static-files-protection).

| Key | Type | Default | What it does |
|-----|------|---------|--------------|
| `servers[].serve_files` | bool | `false` | Serves files from `root_dir`. |
| `servers[].root_dir` | string or null | `null` | The directory that `serve_files` serves. |
| `servers[].static_files.allowed_extensions` | list of strings | `[]` | The allowed file extensions, without a dot. It works only with `serve_files` and `root_dir`. A `StaticFilesMiddleware` that you register as a service does not read it. It uses its constructor argument `$allowedExtensions`. |

## Environment variables

The bundle reads these variables.
Each variable is read from different places.
The "Read from" column shows the exact places.
Only `WORKERMAN_CACHE_WARMUP_TIMEOUT` and `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE` look in `$_SERVER`, then `$_ENV`, then `getenv()`.

| Name | Default | What it does | Read from |
|------|---------|--------------|-----------|
| `WORKERMAN_RUNTIME_DIR` | not set | A writable directory in PHAR or BIN mode. It overrides `runtime_dir`. | `$_SERVER` |
| `WORKERMAN_CACHE_WARMUP_TIMEOUT` | not set | Seconds to wait for the cache warmup. It must be a whole number of 1 or more. It overrides `cache_warmup_timeout`. | `$_SERVER`, `$_ENV`, `getenv()` |
| `WORKERMAN_TRUST_UNSAFE_CONFIG_CACHE` | not set | `1`, `true`, `on` or `yes` turns the owner check of the config cache into a warning. This lowers security. Any other value keeps the strict check. See [security.md](security.md). | `$_SERVER`, `$_ENV`, `getenv()` |
| `GRPC_ENABLE_FORK_SUPPORT` | not set | It must be `1` or `true` when `ext-grpc` is loaded. Otherwise the server logs a warning. See [troubleshooting.md](troubleshooting.md). | `$_ENV`, `getenv()` |
| `APP_CACHE_DIR` | not set | The base cache directory. The bundle adds the environment name to it. | `$_SERVER` |
| `APP_LOG_DIR` | not set | The log directory of Symfony. The PHAR stub tries to set it to `<runtime dir>/var/log`, but see the note below. | `$_SERVER` |
| `APP_RUNTIME` | not set | Set it to `CrazyGoat\WorkermanBundle\Runtime` to start the server through `public/index.php`. The PHAR stub sets it for you. | Symfony Runtime |
| `APP_ENV` | the environment of the build (PHAR) | The Symfony environment. | `$_SERVER` |
| `APP_DEBUG` | `false` (PHAR) | The Symfony debug mode. | `$_SERVER` |

> **Note:** In PHAR mode, the stub sets `APP_CACHE_DIR` and `APP_LOG_DIR` in `$_ENV`, but Symfony and the bundle read `$_SERVER`.
> So the two values have no effect.
> This is a known bug: see [issue 916](https://github.com/crazy-goat/workerman-bundle/issues/916).

## Full examples

A development config:

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080
      processes: 2

when@dev:
  workerman:
    reload_strategy:
      file_monitor:
        active: true
        source_dir: ['%kernel.project_dir%/src', '%kernel.project_dir%/config']
        file_pattern: ['*.php', '*.yaml']
```

A production config:

```yaml
# config/packages/workerman.yaml
workerman:
  user: www-data
  group: www-data
  stop_timeout: 10
  trusted_hosts: ['^example\.com$']
  servers:
    - name: 'Symfony webserver'
      listen: http://0.0.0.0:8080
      reuse_port: true
      body_size_cap: 1048576

when@prod:
  workerman:
    reload_strategy:
      exception:
        active: true
      max_requests:
        active: true
        requests: 1000
        dispersion: 20
      memory:
        active: true
        limit: 134217728
        gc_limit: 100663296
        gc_cooldown: 60
```
