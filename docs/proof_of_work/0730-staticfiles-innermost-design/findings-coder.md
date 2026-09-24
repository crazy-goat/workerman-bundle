## #730 implementation findings

- The ordering is already correct and intentional: `MiddlewareDispatcher` documents "first registered = first executed", and `withRootDirectory()` appends `StaticFilesMiddleware` last, so it is closest to the controller. The gap was documentation, not code.
- Biggest obstacle: choosing between documenting intent and hoisting the layer. Hoisting is a behavioural change (static assets would stop traversing user middleware), so it is out of scope for a design-confirmation issue without acceptance criteria.
- Side effect: `docs/helpers/decisions.md` grows past its advisory 300-line budget (already over before this change). The faq/decisions budget regression is tracked by #744, which is in this milestone.
- No additional out-of-scope bugs identified.
