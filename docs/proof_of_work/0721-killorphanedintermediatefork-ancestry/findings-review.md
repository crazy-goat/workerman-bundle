# Findings — review, issue #721

| ID | File:Line | What is wrong | Severity | What happened to it |
| --- | --- | --- | --- | --- |
| R1 | `src/ProcessInspector.php:270-274` | Warning message for ancestry title-mismatch says "parent is not a Workerman master process" without naming the check that failed (title). A future reader may confuse it with a fingerprint mismatch. Slightly more explicit wording would help: "parent does not carry the Workerman master process title". | nit | Fixed — reworded to "parent does not carry the Workerman master process title" in `src/ProcessInspector.php:271`. |
| R2 | `tests/ProcessInspectorTest.php:850-913` | New ancestry tests are `@requires OS Linux` and therefore never run on Darwin hosts. A Darwin developer running `composer test` locally gets zero coverage of the fixed branch; only CI's Linux legs exercise it. This is the same gap noted in `findings-coder.md` row 3. | low | Noted — not a correctness issue, but a developer-experience gap. A Darwin-runnable mock (subclass overriding `isLinux()`/`getParentPid()`/`isWorkermanMasterTitle()`) could pin the same logic via `composer test` on macOS. Tracked as follow-up, not blocking. |
