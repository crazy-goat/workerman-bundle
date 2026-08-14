# Code Decision — Round 2 (CI fix) — #688

## Trigger

CI `lint` job (PHP 8.2, phpstan 2.2.8) failed on PR #715 with one error:

```
bin/kb-lint.php:835  Result of function main (void) is used.  [function.void]
```

Local PHP 8.4/8.5 + phpstan 2.2.8 did **not** reproduce it, nor did forcing
`phpVersion: 80200` locally. The difference is the PHP runtime version, not the
PHPStan version (both are 2.2.8). This is an escaped defect — rounds 1 and 2
ran on PHP 8.4/8.5, where the inference differs; the CI lint leg is the only
place that runs under 8.2.

## Root cause (from PHPStan source)

`vendor/phpstan/phpstan/phpstan.phar` → `src/Rules/FunctionCallParametersCheck.php:224`:

```php
if (!$funcCall instanceof Node\Expr\New_ && !$scope->isInFirstLevelStatement() && $scope->getKeepVoidType($funcCall)->isVoid()->yes()) {
    $errors[] = ... 'Result of function ... (void) is used.' identifier('function.void') ...;
}
```

The check fires when the function call is **not a first-level statement** AND
its resolved return type is void (under the running PHP's inference). For
`exit(main(parseArgs(...)))` the `main(...)` call is the argument of `exit(...)`,
so it is not a first-level statement → the check runs, and on PHP 8.2 PHPStan
resolves `main()`'s return as void → flagged.

`bin/pick-issue.php` passed CI because its `main(): void` is called as a **bare
statement** `main(parseArgs(...));`, which is a first-level statement and is
exempt from the check regardless of PHP version.

## Approach taken

Make `kb-lint.php`'s `main()` call `exit()` itself on every return path, and
make the caller a bare statement — mirroring `pick-issue.php`:

- `main()` declared `function main(array $options): int` (unchanged; `never`
  satisfies `int`, PHPStan accepts it).
- Three return sites converted from `return <int>;` to `exit(<int>);`:
  - the JSON-output path (`exit($ok ? 0 : 1);`)
  - the `!$ok` failure path (`exit(1);`)
  - the final success path (`exit(0);`)
- The caller: `exit(main(parseArgs($_SERVER['argv'] ?? [])));` →
  `main(parseArgs($_SERVER['argv'] ?? []));` (a first-level statement, exempt
  from `function.void`).
- The surrounding `try { ... } catch (Throwable $e) { ... exit(2); }` is
  retained: a `Throwable` thrown by `parseArgs()` (or from inside `main()` before
  it reaches an `exit()`) is still caught and reported with exit code 2.

### Why this and not the alternatives

- **Capture to a variable** (`$code = main(...); exit($code);`): rejected. The
  `main(...)` call would still be an operand of the assignment, hence not a
  first-level statement, so `function.void` would still fire on PHP 8.2 if
  `main` is resolved as void. The first-level-statement exemption is the only
  reliable escape, and the only way to get it while still using `main`'s result
  is to move the `exit` inside `main`.
- **`@phpstan-ignore-next-line`**: rejected by project convention (DEC-008 and
  the workflow's "never lower a gate" rule); also it would paper over a real
  inference gap rather than fix the call shape.
- **Ignore-errors entry**: same objection, and it would be a PHP-8.2-only
  suppression baked into `phpstan.neon.dist`, which is exactly the kind of gate
  weakening the workflow forbids.

## Test-safety

`tests/KnowledgeBase/KnowledgeBaseTest.php` and `KbLintScriptTest.php` run
`bin/kb-lint.php` as a **subprocess** (`[\PHP_BINARY, $this->projectDir .
'/bin/kb-lint.php']`) and check the exit code via `proc_close`. Converting
`main`'s `return`s to `exit`s is transparent to that: the process exit code is
identical. Verified: 45 KnowledgeBase tests pass; full `composer test`
2034/0 failures.

## Residual risk

This fix is correct under the structural exemption, but I could **not**
reproduce the PHP 8.2 failure locally (only 8.4/8.5 available; the Docker
php:8.2-cli image lacks the zip extension so composer could not install
phpstan). CI is the authoritative verifier — if the `function.void` error
recurs, the inference is doing something beyond first-level-statement handling
and we will need a real PHP 8.2 reproduction (install the zip extension in the
container, or add a `composer lint`-under-8.2 local parity step) before
iterating further.

## What I was unsure about

Whether `$exitCode = main(...); exit($exitCode);` would also trip the rule.
Reading the PHPStan source confirmed it would (assignment RHS is not first-level),
which is why the move-`exit`-inside-`main` approach was chosen instead.
