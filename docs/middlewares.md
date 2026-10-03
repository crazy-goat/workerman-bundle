# Middlewares

A middleware is a small class that runs for every HTTP request.
It can look at the request before the Symfony controller runs.
It can also change the response before the server sends it.
Use middlewares for things like login checks, logging and static files.

## Write a middleware

A middleware is a service that implements `CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface`.
The method `__invoke` gets the request and a `$next` callable.
Call `$next($request)` to run the next layer and get its response.
Do not call `$next` if you want to answer the request yourself.

```php
<?php

use CrazyGoat\WorkermanBundle\Http\Request;
use CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface;
use Workerman\Protocols\Http\Response;

final readonly class MyMiddleware implements MiddlewareInterface
{
    public function __invoke(Request $request, callable $next): Response
    {
        // Before the controller: look at the request or change it.
        if ($request->header('X-Custom') === null) {
            return new Response(400);
        }

        $response = $next($request);

        // After the controller: look at the response or change it.
        $response->header('X-Processed-By', 'MyMiddleware');

        return $response;
    }
}
```

The request is a `CrazyGoat\WorkermanBundle\Http\Request`.
It extends the Workerman request.
The response is a Workerman response, not a Symfony response.

> **Note:** A middleware cannot change a streamed response (a Symfony `StreamedResponse`).
> The server sends it while the controller layer runs, so it is already gone when `$next()` returns.
> A header that you add to it is not sent.

To change a request header, use `$request->setHeader($name, $value)`.
The method changes the request itself.
The old name `withHeader()` still works, but it is deprecated.
Be careful with the `X-Forwarded-*` headers.
Read [Middleware header re-injection](security.md#middleware-header-re-injection-trusted-proxy-bypass) first.

## Register a middleware

First, make the class a service.
The service can be private. The bundle makes the services from the `middlewares` lists public at container build time. If a service ID does not exist, the container build fails with a clear message.
Then write its service ID in the `middlewares` list of a server.

```yaml
# config/services.yaml
services:
  App\Middleware\MyMiddleware:
```

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080
      processes: 4
      middlewares:
        - App\Middleware\MyMiddleware
```

Each server has its own list.
A middleware in one server does not run in another server.

## Order of middlewares

The middlewares run in the order of the `middlewares` list.
The first middleware is the outermost layer.
It sees the request first and the response last.

```
Request -> Middleware 1 -> Middleware 2 -> ... -> Symfony controller -> ... -> Middleware 2 -> Middleware 1 -> Response
```

Put checks like login and rate limits first.
Then they run before the other layers and before the controller.

The deprecated `serve_files` and `root_dir` options also add a static files layer.
This layer is always the last one, so it is the innermost layer.
Your own middlewares run before it.
They can block a static file, add a header to it or log it.
The order does not depend on how you write the config.
A static files middleware that you register yourself in the list works like any other middleware.

## Static files middleware

`CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware` serves files from a directory.
It replaces the deprecated `serve_files` and `root_dir` options.

```yaml
# config/services.yaml
services:
  workerman.middleware.static_files:
    class: CrazyGoat\WorkermanBundle\Middleware\StaticFilesMiddleware
    arguments:
      $rootDirectory: '%kernel.project_dir%/public'
```

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: 'Symfony webserver'
      listen: http://127.0.0.1:8080
      processes: 4
      middlewares:
        - workerman.middleware.static_files
```

The constructor has three arguments:

| Argument | Type | Default | What it does |
|----------|------|---------|--------------|
| `$rootDirectory` | string | required | The directory with the public files. It must exist. If it does not, the worker cannot start and no request is served. Look in the log. |
| `$allowedExtensions` | list of strings | `[]` | Only files with these extensions are served. An empty list allows all extensions, except the blocked files. |
| `$followSymlinks` | bool | `false` | When `false`, a file that you reach through a symbolic link is not served. |

The `static_files` key of a server does not work for a middleware that you register as a service.
It only works for the deprecated `serve_files` and `root_dir` options.
Always use the constructor arguments.
Read [Static files protection](security.md#static-files-protection) for the list of blocked files and for safe settings.

### What the middleware does with a request

1. If `$allowedExtensions` is set and the path ends with another extension, the answer is 404.
2. If the path is not a file in the root directory, the middleware calls `$next`. The next layer can be your controller. A path outside of the root directory is never served.
3. If the file is blocked (for example a dotfile or a `.php` file), the answer is 404. It is the same answer as for a file that does not exist.
4. If the request headers say that the client has the newest file, the answer is 304 with no body.
5. Otherwise the answer is 200 with the file.

### Headers of a static file

A 200 answer has these headers:

| Header | Value |
|--------|-------|
| `Last-Modified` | The time of the last change of the file, in GMT. |
| `ETag` | A quoted value made from the change time and a hash of the file path. |
| `Cache-Control` | `public, max-age=3600, must-revalidate` |

The client can keep the file for one hour.
After that, the client asks again with `If-None-Match` or `If-Modified-Since`.
The answer is 304 when:

- `If-None-Match` is `*`, or it has the `ETag` of the file (a list with commas is fine), or
- `If-Modified-Since` is the same as or later than the change time of the file.

A 304 answer has no `ETag`, `Last-Modified` or `Cache-Control` headers.

The middleware remembers the result of the path lookup for a short time.
It is 60 seconds for a file and 5 seconds for a missing file.
A new file can need a few seconds to show up.
The cache is per worker.

## Move from serve_files and root_dir

The options `serve_files`, `root_dir` and `static_files` of a server are deprecated.
To move to the middleware:

1. Register `StaticFilesMiddleware` as a service. Use the value of `root_dir` as `$rootDirectory`.
2. Use the value of `static_files.allowed_extensions` as `$allowedExtensions`.
3. Add the service ID to the `middlewares` list of the server.
4. Remove `serve_files`, `root_dir` and `static_files` from the server.

If you keep both, the static files layer from the old options runs after your middlewares.
See [UPGRADE.md](../UPGRADE.md) for the deprecation list.

## See also

- [HTTP server](http-server.md): listen, workers, limits and streamed responses.
- [Configuration reference](configuration.md): every config key.
- [Security](security.md): static files protection and header rules.
