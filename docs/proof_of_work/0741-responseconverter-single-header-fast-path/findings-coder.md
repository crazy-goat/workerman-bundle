## #741 implementation findings

- Biggest implementation obstacle: preserving all existing distinctions in `flattenHeaderValues()` while bypassing both intermediate array allocations for the dominant one-value case. The new branch excludes Set-Cookie and null values; all other shapes still use the original implementation.
- `ResponseConverterTest` already covers multi-value Set-Cookie and all-null headers, so the regression test adds the common single-value conversion case without duplicating those checks.
- No additional out-of-scope bugs identified during this change.
