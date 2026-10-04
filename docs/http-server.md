# HTTP server

Each entry in `workerman.servers` is one HTTP server.
It has one listening socket and some worker processes.
A worker is a child process that handles requests.
This page tells what a server does with a request.
The config keys are in the [configuration reference](configuration.md#servers).

## Listen address

`listen` is required.
A server without `listen` does not start.
Use `http://` or `https://`, for example `http://0.0.0.0:80`.
This page does not cover WebSocket listeners.

For `https://` you also need `local_cert` and `local_pk`.
The bundle checks both files before the start.
Read [SSL certificate and key validation](security.md#ssl-certificate-and-key-validation).

```yaml
# config/packages/workerman.yaml
workerman:
  servers:
    - name: 'Web'
      listen: 'http://0.0.0.0:80'
      processes: 4
      reuse_port: true
```

## Workers

`processes` is the number of workers of the server.
If you leave it out, the bundle starts the number of CPUs times 2.
In a container it uses the CPU limit, not the CPUs of the host.
Each worker has its own Symfony kernel.
The kernel is booted once, when the worker starts.
It is reused for all requests of this worker.
After each request the bundle resets the services that need it.

`reuse_port: true` lets each worker open the listening port itself.
The system then spreads new connections between the workers.
The default is `false`: the workers share one socket.

When a worker is too big or too old, it is restarted.
Read about this in [reload strategies](reload-strategies.md).

## The way of a request

1. The request goes through the [middlewares](middlewares.md).
2. The last layer is the Symfony controller. It turns the request into a Symfony request and calls the kernel.
3. The server sends the response.
4. The kernel finishes the request (the `kernel.terminate` event).
5. The connection is closed if it has to be closed. Then the reload strategy can ask for a reload.

## Errors

If something throws an error while the server handles a request, the client gets an error page.
The worker keeps running.

| Answer | When |
|--------|------|
| 400 `Bad Request` | The request is bad, for example a header with control bytes, a broken upload or a wrong method. |
| 500 `Internal Server Error` | Everything else, for example an error in a middleware. |

The 500 error is written to the log.
The 400 error is only written to the debug log, so that nobody can fill the log.

## Limits and timeouts

| Key | What it does |
|-----|--------------|
| `max_package_size` | The maximum size of one request. The default is 10 MB. |
| `servers[].body_size_cap` | The maximum size of one request for this server. It replaces `max_package_size`. |
| `connection_timeout` | The maximum time to wait for a complete request. The default is 120 seconds. |
| `keepalive_timeout` | The maximum time that an idle keep-alive connection stays open. The default is 30 seconds. |

Read the defaults in the [configuration reference](configuration.md#top-level-keys).
Read why they matter in [Connection timeouts](security.md#connection-timeouts-slowloris-protection) and [body_size_cap](security.md#body_size_cap-per-server).

## Keep-alive

The server keeps a connection open after a response, so that the client can send the next request.
The server closes the connection after the response when:

- the request uses HTTP/1.0, or
- the request has the header `Connection: close`.

The response then has the header `Connection: close`.

A timer looks at all connections.
It runs every quarter of the shortest timeout that is not `0`, but not more than once per second.
With the defaults it runs every 7 seconds.
If both timeouts are `0`, there is no timer.
It closes a connection that is idle for too long:

- A connection that has not finished a request is closed after `connection_timeout`.
- A connection that has finished a request is closed after `keepalive_timeout`.

A connection that is still sending a big response is not idle.
It stays open as long as the client takes bytes.
If the client takes nothing, the timeout closes the connection.

## Files and streamed responses

The server sends a Symfony response in the way that fits its type.

- A normal response is sent at once.
- A `BinaryFileResponse` sends the file.
- A `StreamedResponse` is sent in chunks with `Transfer-Encoding: chunked`. Then the memory does not grow with the size of the body. HTTP/1.0 clients get the body without chunks, and the connection is closed after it.
- For a `HEAD` request, the server sends the headers and no body.
- A file response that Symfony made bodyless sends no file either: a `1xx`, `204` or `304` status, and the `X-Sendfile` or `X-Accel-Redirect` hand-off, where the server in front sends the file. The reply keeps the `Content-Length` that `prepare()` set — the file size for the hand-off, `0` for the empty statuses — and the file is not opened.

`response_chunk_size` is the size of the chunks of a streamed response.
The default is 2048 bytes.
A value below 8192 is raised to 8192.

The server writes the chunks of a streamed response in one step.
A slow client cannot take data in between.
If the send buffer of the connection is full (about 1 MB), the server stops the response and closes the connection.
The client then sees a broken chunked body.
The server writes a warning to the log.
So do not stream a very big body to a client that can be slow, or send it with a file response.

If your code throws an error in the middle of a streamed response, the headers are already sent.
The client cannot get a 500 answer any more.
The server writes the error to the log and closes the connection.
The client sees a body that has no valid end.
An error before the first byte still gives a normal 500 answer.

A middleware cannot change a streamed response.
The server has already sent it when `$next()` returns.
A header that you add then is not sent.
