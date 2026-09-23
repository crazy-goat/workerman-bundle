## Round 1 — #741

Added a short-circuit before `array_filter()`/`array_values()` when the header is not `Set-Cookie` and its input list contains exactly one non-null value. All other cases retain the original filtering and flattening behavior, including all-null values, mixed null/non-null lists, multiple values, and Set-Cookie arrays.

Rejected broad changes to header flattening because the issue identifies only the common one-value case and changing the multi-value path would add unnecessary behavioral risk. Added a focused conversion test while retaining existing tests for Set-Cookie and null values.
