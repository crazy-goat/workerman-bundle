# Code decision — round 1 (#594)

## Approach

The four byte-identical / re-indented copies of the server-config shape were
replaced by a single `@phpstan-type ServerConfig` alias declared on
`CrazyGoat\WorkermanBundle\Worker\ServerWorker` (the class that owns the
shape), and imported into `CrazyGoat\WorkermanBundle\WorkermanBundle` with
`@phpstan-import-type ServerConfig from \CrazyGoat\WorkermanBundle\Worker\ServerWorker`.

- `ServerWorker::__construct()`, `configureHandler()` and `createSslContext()`
  are methods of the declaring class, so the alias is in scope without an
  import.
- `WorkermanBundle::loadExtension()` now writes `servers?: list<ServerConfig>`.
- Deprecation notes for `serve_files`, `root_dir` and `static_files` live as
  inline comments inside the alias, right above each key.

## Rejected alternatives

- **`parameters.typeAliases` in `phpstan.neon.dist`.** Less import boilerplate,
  but the shape would be invisible to anyone reading the class, and the issue
  itself argues for source-level `@phpstan-type`. Rejected deliberately.
- **A dedicated `Config` docblock-holder class.** One more type for a single
  alias; `ServerWorker` is the natural owner and already the primary consumer.
  Rejected as overkill.
- **Declaring the reload-strategy aliases too** (`ExceptionStrategyConfig`,
  `MaxRequestsStrategyConfig`, …). The issue marks this lower priority and
  there is no duplication there yet (`WorkermanBundle.php` only). Adding five
  aliases is churn without a correctness payoff; deliberately deferred. A
  follow-up can still do it.
- **Importing `ServerWorker` with a `use` statement** in `WorkermanBundle`.
  The class is referenced only from a docblock, so php-cs-fixer's
  `no_unused_imports` would flag it. Used the FQCN in the import instead.

## Uncertainty / notes

- PHPStan parses the inline `// @deprecated` comments inside the array shape
  without error (verified: `vendor/bin/phpstan` reports no errors at level 8).
  They are plain comments, not a machine-enforced `@deprecated` tag — PHPStan
  cannot attach `@deprecated` to an array-shape key — so they are documentation
  only, matching the acceptance criterion's "marks … as deprecated".
- Acceptance criterion 3 ("adding a key to the alias makes it available at all
  four former call sites") is proven **structurally, not by a PHPStan probe**.
  A temporary `probe_key?: int` was added to the alias and dereferenced from
  `configureHandler()` (`$serverConfig['probe_key'] ?? null`); `vendor/bin/phpstan`
  reported no error. That is not a valid demonstration, because the same "no
  error" is produced for a deliberately unknown key: `treatPhpDocTypesAsCertain: false`
  (phpstan.neon.dist) means PHPStan does not flag unknown offsets in this
  configuration. The real evidence is that all four former sites now dereference
  the one alias, so a new key is visible at every site in one edit. Neither the
  temporary key nor the temporary dereference was committed.
- Round-1 review nit R1-F6 adopted: the inline deprecation comments now name the
  concrete replacement and removal target
  (`@deprecated since 0.9.3, removed in 1.0 — use StaticFilesMiddleware instead`),
  matching `UPGRADE.md:16`.
