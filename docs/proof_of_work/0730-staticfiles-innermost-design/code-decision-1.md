## Round 1 — #730

Resolved the open design question by documenting intent rather than changing behaviour. `StaticFilesMiddleware` stays the innermost pipeline layer (appended last by `HttpRequestHandler::withRootDirectory()`), because user middleware must run first and be able to short-circuit, authenticate, add headers to, or otherwise wrap static-file responses. Hoisting it outward would make static assets bypass those hooks — a behavioural and security-relevant change that needs its own acceptance criteria, exactly as the issue states.

Recorded a new decision entry `DEC-022` in `docs/helpers/decisions.md` (tags `middleware,static-files,architecture,http`) and a pointer comment at the append site in `src/Http/HttpRequestHandler.php`. No runtime behaviour changed; no test changed.

Rejected hoisting the middleware: it would be a silent behaviour change for every application that relies on middleware running before static files, with no compatibility signal. Rejected leaving the question undocumented: the issue's whole point is that nothing in `docs/helpers/` settles the ordering, which invites a future "optimisation" to move it.
