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
 *  - {@see setHeader()} writes or overwrites a single header by name (the primary API).
 *  - {@see withHeader()} is a deprecated alias kept for backward compatibility.
 *    It emits an `E_USER_DEPRECATED` warning advising use of {@see setHeader()}.
 *
 * **⚠️ PSR-7 semantic mismatch:** Unlike PSR-7's immutable {@see withHeader()},
 * both methods mutate the request in place and return \$this. The PSR-7 convention
 * reserves the `with*` prefix for methods that return a **new** instance. This
 * bundle intentionally deviates because the middleware pipeline passes the same
 * Request instance through each layer, and middleware that mutates headers
 * (e.g. trusted-proxy, authentication, routing) needs to affect the instance seen
 * by downstream layers.
 *
 * **Security caveat:** Because {@see setHeader()} is exposed to every middleware,
 * any middleware can re-inject trust-sensitive headers (notably `X-Forwarded-*`
 * and `Forwarded`) after trusted-proxy validation has run. See the per-method
 * PHPDoc warnings on {@see setHeader()} / {@see withHeader()} and the dedicated
 * section in `docs/security.md` for the full trust model and recommended
 * ordering of middleware vs. trusted-proxy filtering.
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
     *
     * **⚠️ Security warning — trust-sensitive headers:** This method bypasses any
     * earlier sanitization of trust-sensitive headers. Middleware that injects
     * `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`, `X-Forwarded-Port`,
     * `X-Forwarded-Ssl`, or `Forwarded` from untrusted input re-creates the
     * trusted-proxy bypass class of bugs the bundle works to avoid. If your
     * application relies on Symfony's `Request::setTrustedProxies()` /
     * `setTrustedHosts()` filtering, ensure that filtering runs **after** any
     * middleware that calls this method, or scope-limit which middleware is
     * allowed to set forwarding headers. See `docs/security.md` for the full
     * trust model.
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
     * Sets a header value, mutating the current instance.
     *
     * Alias of {@see setHeader()}. Unlike PSR-7's immutable
     * {@see \Psr\Http\Message\MessageInterface::withHeader()} which returns
     * a **new** instance, this method **mutates** the request in place.
     *
     * **⚠️ Security warning — trust-sensitive headers:** Inherits the same
     * trust-sensitive header warning as {@see setHeader()}. Injecting
     * `X-Forwarded-For`, `X-Forwarded-Proto`, `X-Forwarded-Host`,
     * `X-Forwarded-Port`, `X-Forwarded-Ssl`, or `Forwarded` headers from
     * untrusted input re-creates the trusted-proxy bypass class of bugs.
     * See `docs/security.md` for the full trust model.
     *
     * @deprecated since 0.23.0 Use {@see setHeader()} instead.
     *             This method will be removed in the next major version.
     *             The PSR-7 naming is misleading because the mutation semantics
     *             differ from PSR-7's immutable pattern.
     */
    public function withHeader(string $name, string $value): self
    {
        trigger_error(
            \sprintf(
                'Since crazy-goat/workerman-bundle 0.23.0: %s::withHeader() is deprecated, use setHeader() instead.',
                self::class,
            ),
            \E_USER_DEPRECATED,
        );

        return $this->setHeader($name, $value);
    }
}
