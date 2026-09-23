## Round 1 — #765

Replace global `str_replace('.zip', ...)` with a trailing-extension check using `str_ends_with()` and `substr()`. This strips exactly the final four characters only when the basename ends in `.zip`, while retaining the prior behavior for names without that extension. A focused test invokes the private lookup method with `my.zip.archive.zip` and an extracted `my.zip.archive` file.

Rejected a regular-expression replacement as unnecessary; the suffix operations state the intended behavior directly. The archive fallback search remains unchanged.
