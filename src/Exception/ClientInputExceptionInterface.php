<?php

declare(strict_types=1);

namespace CrazyGoat\WorkermanBundle\Exception;

/**
 * Marker interface for exceptions caused by malformed client input.
 *
 * Exceptions thrown during request conversion (control bytes in header
 * values, invalid URI/method, malformed multipart uploads) implement this
 * interface so that HttpRequestHandler can classify them as 400 client
 * errors — logged at debug level to prevent log flooding by an
 * unauthenticated attacker (see issue #577).
 *
 * A middleware or application that throws \InvalidArgumentException for
 * a server-side reason does NOT implement this interface and is therefore
 * correctly classified as a 500 server fault.
 */
interface ClientInputExceptionInterface extends WorkermanExceptionInterface
{
}
