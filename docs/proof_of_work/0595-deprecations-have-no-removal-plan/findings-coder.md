# Findings — Coder (issue #595)

## Process defect: subagent output-derailment mid-edit

The first implementation run (a subagent) fell into a severe output-degradation
loop while editing `tests/DependencyInjection/ConfigurationTreeBuilderTest.php`
and, as a result:

- introduced a broken `createDefinitionConfigurator()` helper (the
  `$treeBuilder = new TreeBuilder('workerman');` line was dropped, and later a
  stray edit removed the opening `{`), which caused every test in that file to
  fail with a `TypeError` — 13 errors / 0 assertions.
- reported without committing anything and without writing either proof-of-work
  file.

Both were recovered by the main session before any commit: the helper was
restored and the POW files written by hand. The lesson: an agent that shows
signs of output corruption should be verified against `git diff` + a green test
run before its report is trusted, and its uncommitted tree inspected rather
than resumed blindly.

## Reminder for a future round

`Request::withHeader()` already had a deprecation test
(`testWithHeaderTriggersDeprecation` in `tests/RequestTest.php`) before this
issue — no new test was needed for it. The config-node deprecation test is new
(`testConfiguredTreeDeprecatesLegacyStaticFileNodes` +
`provideDeprecatedNodes`). The `Utils::reboot()` deprecation test is genuinely
blocked on #588 (missing `symfony/deprecation-contracts` declaration), not
merely deferred by habit.

## Out-of-scope note

The deprecated `serve_files` path remains fully wired — it is the only consumer
of the `servers[].static_files` config node and the reason
`HttpRequestHandler::withStaticFileConfig()` /
`withRootDirectory()` / `StaticFileHandlerInterface` exist. Removing it at 1.0
(closing the `static_files.allowed_extensions` config-key trap in the process)
is future work, deliberately not done here (see `code-decision-1.md`).
