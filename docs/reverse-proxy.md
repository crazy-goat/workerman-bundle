# Reverse proxy

A reverse proxy is a server in front of your app, for example nginx or Caddy.
The client talks to the proxy.
The proxy talks to the Workerman server.
This page shows how to set up nginx and Caddy, and how to make Symfony trust the proxy.

The bundle works without a proxy.
But a proxy is a good choice in production:

- It ends HTTPS and HTTP/2. The Workerman server speaks plain HTTP/1.1 behind it.
- It serves static files without PHP.
- It keeps slow clients away from your workers.
- It can serve many apps on ports `80` and `443`, so your app needs no special rights for these ports.

## Checklist

- The server listens on a private address (see [private port](#keep-the-server-port-private)).
- The proxy sends `Host`, `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host` and `X-Forwarded-Port`.
- Symfony trusts only the proxy (see [trusted proxies](#trusted-proxies)).
- `trusted_hosts` is set (see [trusted hosts](#trusted-hosts)).
- The size and time limits of the proxy fit the limits of the server (see [limits](#body-size-and-timeouts)).

## Keep the server port private

The proxy must be the only way in.
If a client can reach the port of the server, it can talk to the server without the proxy.

Let the server listen on the loopback address, or on a private network:

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: web
      listen: http://127.0.0.1:8080
      processes: 4
```

In Docker, do not publish the port of the app.
Only the proxy needs to reach it.
See [Docker](deployment.md#docker).

## Trusted proxies

Behind a proxy, the server sees the address of the proxy as the client.
The real client address, the scheme and the host come in the `X-Forwarded-*` headers.
Symfony reads these headers only when the request comes from a trusted proxy.
So you must tell Symfony which proxies it can trust:

```yaml
# config/packages/framework.yaml
framework:
  trusted_proxies: '127.0.0.1'
  trusted_headers: ['x-forwarded-for', 'x-forwarded-proto', 'x-forwarded-host', 'x-forwarded-port']
```

Use the address of your proxy, or a network such as `10.0.0.0/8`, or a list with commas.
The bundle does not trust `X-Forwarded-Proto` by itself since version 0.16 (see [UPGRADE.md](../UPGRADE.md#x-forwarded-proto-no-longer-trusted-by-default)).
It detects HTTPS only from the real connection.
Behind a proxy, only the Symfony settings above make `$request->isSecure()` true.

We ran nginx in front of the server.
Without `trusted_proxies`, `getClientIp()` returned the address of nginx, and `isSecure()` was `false` on an HTTPS request.
With the address of nginx in `trusted_proxies`, `getClientIp()` returned the real client, and `isSecure()` was `true`.

Do not use the value `REMOTE_ADDR` in `trusted_proxies` if other machines can reach the port of the server.
It trusts every client that connects directly.

### Do not re-inject the headers

A middleware must not copy `X-Forwarded-*` headers from untrusted input.
Read [Middleware header re-injection](security.md#middleware-header-re-injection-trusted-proxy-bypass).

## Trusted hosts

`trusted_hosts` rejects a request with a `Host` that is not on your list:

```yaml
# config/packages/workerman.yaml
workerman:
  trusted_hosts:
    - '^example\.com$'
```

Each entry is a regular expression, without delimiters.
The check runs in the worker, before the Symfony kernel (see [Trusted host enforcement](security.md#trusted-host-enforcement)).

Behind a trusted proxy, Symfony reads the host from `X-Forwarded-Host`.
So the proxy must send the right `Host` and `X-Forwarded-Host`, as in the examples below.
We tested it with nginx: `example.com` got status 200, and another host name got status 400.
The proxy sets `X-Forwarded-Host` itself, so a client cannot choose it.

A request that goes to the server without the proxy, with a host that is not on the list, gets status 400.
A health check that calls the server directly needs a `Host` header from the list.

## nginx

This nginx config is tested.
In our test, the server was a Docker container, and `server` in `upstream` was `app:8080` instead of `127.0.0.1:8080`.
It serves HTTPS with HTTP/2, files from `public/` and the rest from the server:

```nginx
upstream app {
    server 127.0.0.1:8080;
    keepalive 16;
    keepalive_timeout 20s;
}

server {
    listen 80;
    listen 443 ssl;
    http2 on;
    server_name example.com;

    ssl_certificate     /etc/nginx/certs/cert.pem;
    ssl_certificate_key /etc/nginx/certs/key.pem;

    root /var/www/myapp/public;
    client_max_body_size 10m;

    # Never serve dotfiles from nginx, but keep /.well-known/ for certificate renewal.
    location ~ /\.(?!well-known/) {
        deny all;
    }

    # Never serve the PHP source files of public/.
    location ~ \.php$ {
        deny all;
    }

    location / {
        try_files $uri @app;
    }

    location @app {
        proxy_pass http://app;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $remote_addr;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Port $server_port;
        proxy_connect_timeout 5s;
        proxy_read_timeout 60s;
    }
}
```

What the directives do:

- `proxy_http_version 1.1` and `proxy_set_header Connection ""` are needed for keep-alive connections to the server.
  Without them, nginx opens a new connection for each request.
- `keepalive 16` keeps up to 16 idle connections to the server per nginx worker.
- `proxy_set_header X-Forwarded-For $remote_addr` replaces what the client sent.
  We sent `X-Forwarded-For: 6.6.6.6` from outside, and the app still saw the real client address.
  The nginx variable `$proxy_add_x_forwarded_for` keeps the value of the client and adds yours.
  Use it only when another proxy that you trust is in front of nginx.
- `X-Forwarded-Proto $scheme` tells the app if the client used HTTPS.
- `location ~ \.php$` is important: `public/` of a Symfony app has `index.php`.
  Without this rule, `try_files` finds the file and nginx sends its source code.
  We saw this in a test.
- `deny all` for dotfiles keeps `.env` and similar files private.
  The `(?!well-known/)` part keeps `/.well-known/` open, which tools like certbot need.

With `root` set to `public/`, nginx sends a file when it exists, and the server handles all other URLs.
You can also serve files with the [static files middleware](middlewares.md) and use no `root` in nginx.
Then the PHP workers send the files.

Reload nginx after a change: `nginx -t && nginx -s reload`.

## Caddy

This `Caddyfile` is tested in the same way (with `app:8080`, and `local_certs` for a test certificate):

```
example.com {
    reverse_proxy 127.0.0.1:8080
}
```

For a public domain, Caddy gets a certificate and turns on HTTPS and HTTP/2 by itself.
Caddy sets `X-Forwarded-For`, `X-Forwarded-Proto` and `X-Forwarded-Host` itself.
It ignores the values that a client sends, unless you list the client in the Caddy option `trusted_proxies`.
We sent `X-Forwarded-For: 6.6.6.6` from outside, and the app still saw the real client address.
Caddy does not send `X-Forwarded-Port`.
Symfony then reads the port from the `Host` header, which is right when the client uses the port of the URL.

## HTTPS and HTTP/2

Do HTTPS and HTTP/2 at the proxy.
The server then uses `http://` and needs no `local_cert` and `local_pk`.

We tested both proxies with an HTTPS URL and `curl --http2`.
The client used HTTP/2.
The server saw `SERVER_PROTOCOL` as `HTTP/1.1`.
`$request->getScheme()` was `https`, because the proxy sent `X-Forwarded-Proto: https` and the proxy was trusted.

The Workerman server itself speaks HTTP/1.x.
If you need TLS on the server, see [HTTP server](http-server.md#listen-address).

## Body size and timeouts

Each limit works on its own.
The smallest one wins.

| Limit | Where | Default |
|-------|-------|---------|
| `client_max_body_size` | nginx | 1 MB |
| `max_package_size` and `body_size_cap` | server | 10 MB |
| `proxy_read_timeout` | nginx | 60 seconds |
| `connection_timeout` | server | 120 seconds |

- A body bigger than `client_max_body_size` gets status 413 from nginx.
  We sent 11 MB with a limit of 10 MB, and got 413.
  We sent 2 MB through the same nginx, and the server got all 2 MB.
  Set `client_max_body_size` to the size that your app needs, and not above `body_size_cap` of the server (or `max_package_size` when it is not set).
  See [body_size_cap](security.md#body_size_cap-per-server).
- If the server needs longer than `proxy_read_timeout` to answer, nginx closes the request and the client gets status 504.
  Raise it for a slow route, or move the work into a [task](scheduler.md) or a [process](supervisor.md).
- `connection_timeout` is the time the server waits for a full request.
  A proxy sends a request fast, so you do not need to change it.

## Keep-alive

The proxy keeps connections to the server open and uses them again.
The server closes a connection that is idle for `keepalive_timeout` seconds (default 30).
See [HTTP server](http-server.md).

If the proxy sends a request on a connection at the moment that the server closes it, the request can fail.
So let the proxy close idle connections first.
In nginx, set `keepalive_timeout` in `upstream` to a smaller number than the `keepalive_timeout` of the bundle.
The example above uses 20 seconds and 30 seconds.
The `keepalive_timeout` directive in `upstream` needs nginx 1.15.3 or newer, and `http2 on;` needs nginx 1.25.1 or newer.

## Streamed responses

The server sends a `StreamedResponse` to the proxy in one step, after your code has ended (see [Files and streamed responses](http-server.md#files-and-streamed-responses)).
We ran a route that sends one line per second.
The client got all lines at the same time, also with `proxy_buffering off` in nginx and `flush_interval -1` in Caddy.
So proxy settings do not make a response live.

## When the server is down

During a restart, the proxy cannot reach the server.
nginx answers with status 502 (or 504 if the connect times out).
Caddy answers with status 502.
See [Deployment](deployment.md#deploy-script) for restart and reload.

## Check your setup

Add a route that shows what the app sees:

```php
#[Route('/whoami')]
public function whoami(Request $request): JsonResponse
{
    return new JsonResponse([
        'client_ip' => $request->getClientIp(),
        'secure' => $request->isSecure(),
        'host' => $request->getHost(),
    ]);
}
```

Call it through the proxy, with a fake header:

```
curl -k -H 'X-Forwarded-For: 6.6.6.6' https://example.com/whoami
```

`client_ip` must be your own address, not `6.6.6.6` and not the address of the proxy.
`secure` must be `true`.
Remove the route when you are done.
