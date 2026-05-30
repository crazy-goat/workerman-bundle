<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Http;

/**
 * Bundle-specific extension of Workerman's HTTP Request.
 *
 * Workerman's {@see \Workerman\Protocols\Http\Request} is a read-only parser —
 * it decodes the raw HTTP buffer but exposes no public mutators for headers.
 * This subclass adds header-write capability needed by the middleware pipeline:
 *
 *  - {@see setHeader()} writes or overwrites a single header by name.
 *  - {@see withHeader()} is a deprecated alias kept for backward compatibility.
 *
 * Unlike PSR-7's immutable {@see withHeader()}, both methods mutate the request
 * in place and return \$this. This is intentional: the middleware pipeline passes
 * the same Request instance through each layer, and middleware that mutates
 * headers (e.g. trusted-proxy, authentication, routing) needs to affect the
 * instance seen by downstream layers.
 *
 * **Security caveat:** Because {@see setHeader()}/\{{@see withHeader()} are exposed
 * to every middleware, any middleware can re-inject `X-Forwarded-*` or other
 * hop-by-hop headers after trusted-proxy validation has run. If your application
 * relies on trusted-proxy header filtering, ensure that filtering runs *after*
 * any middleware that calls these methods, or scope-limit which middleware is
 * allowed to set forwarding headers.
 *
 * @see \Workerman\Protocols\Http\Request The parent read-only request parser.
 * @see \CrazyGoat\WorkermanBundle\Middleware\MiddlewareInterface Middleware that receives this request.
 */
class Request extends \Workerman\Protocols\Http\Request
{
    /**
     * Set a header value on this request.
     *
     * Unlike PSR-7's immutable {@see withHeader()}, this method **mutates** the
     * current instance and returns \$this. Header names are normalised to
     * lowercase internally. If headers have not been parsed yet (lazy parsing),
     * this method triggers parsing before writing.
     *
     * {@see setHeader()} is the primary API for middleware or request handlers
     * that need to modify request headers in place (e.g. adding authentication
     * tokens, overriding routing hints). Use it in preference to the deprecated
     * {@see withHeader()}.
     */
    public function setHeader(string $name, string $value): self
    {
        if (!isset($this->data['headers'])) {
            $this->parseHeaders();
        }

        $name = strtolower($name);
        $this->data['headers'][$name] = $value;

        return $this;
    }

    /**
     * @deprecated Use setHeader() instead. This method is kept for backward compatibility.
     *
     * This method **mutates** the request and returns \$this, mirroring
     * {@see setHeader()}. This differs from PSR-7's immutable
     * {@see \Psr\Http\Message\MessageInterface::withHeader()} which returns
     * a **new** instance. The mutation semantics are intentional for the
     * middleware pipeline — see the class-level docblock for details.
     */
    public function withHeader(string $name, string $value): self
    {
        return $this->setHeader($name, $value);
    }
}
