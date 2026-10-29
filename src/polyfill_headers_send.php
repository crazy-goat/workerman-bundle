<?php

declare(strict_types=1);

/*
 * Polyfill for headers_send(): the SAPI function exists on FrankenPHP, where
 * Symfony's Response::sendHeaders() uses it to emit informational (1xx)
 * responses. On the CLI SAPI — where this bundle runs — the function does
 * not exist and sendHeaders(103) silently drops the response, which makes
 * AbstractController::sendEarlyHints() a no-op.
 *
 * Defining it here routes the 103 through
 * CrazyGoat\WorkermanBundle\Http\EarlyHints, which writes the interim
 * response to the current connection ahead of the final response. The
 * conditional keeps FrankenPHP's native implementation untouched.
 */

if (!function_exists('headers_send')) {
    function headers_send(int $status): void
    {
        \CrazyGoat\WorkermanBundle\Http\EarlyHints::send($status);
    }
}
