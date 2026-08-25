# #718 — Code Decision 1: drop obs-fold / whitespace-padded header names before server-key construction

## Context
A whitespace-prefixed header line (obs-fold style, RFC 7230 §3.2.4) survives
Workerman's `parseHeaders()` as a header name with a leading space:
`parseHeaders()` does `strtolower($parts[0])` **without** trimming, so
`" X-Fold: v\r\n"` becomes the literal name `" x-fold"`.

`RequestConverter::buildServerHeaders()` then computed
`$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name))` using the raw
Workerman name verbatim, yielding `HTTP_ X_FOLD` — a `$_SERVER` key with a
literal space. RFC 7230 §3.2.4 says obs-fold continuation lines should be
folded into the previous header value or rejected; forwarding a malformed key
is a protocol-conformance gap and smuggling-adjacent.

## Chosen approach
A silent `continue` in `buildServerHeaders()`, placed **immediately after** the
existing underscore-drop block (`if (\str_contains($name, '_')) { … continue; }`)
and **before** `$key` construction:

```php
if ($name !== \trim($name)) {
    continue;
}
```

The malformed (whitespace-padded) header name is dropped — not forwarded, not
re-trimmed into the well-formed key. This matches the structural intent of the
adjacent underscore-drop path.

### Why silent (no log)
The underscore path logs because it is an attacker-reachable, deliberate evasion
vector that we want to surface. A leading/trailing space is an obs-fold artifact
that is far less likely to be a deliberate probe and is arguably "not a header"
rather than "a header we refuse". Mirroring the existing drop style with a
silent `continue` keeps the change small and consistent. A short inline comment
explains the RFC 7230 §3.2.4 rationale so the intent is not lost.

## Rejected alternatives
1. **Re-trim the name and forward it under the trimmed key.**
   This would silently merge an obs-fold continuation as if it were a normal
   duplicate, which is the wrong semantic: an obs-fold line is a continuation of
   the *previous* header value, not a distinct header. It also risks creating a
   key that was never in the raw request. The existing `rawHeadMayHaveDuplicates()`
   gate already forces a re-parse for whitespace-padded names, so the well-formed
   `X-Fold` is still correctly joined on the slow path (`a, b`); dropping the
   malformed key removes only the bug, not the legitimate data.

2. **Validate/throw (MalformedRequestException).**
   The existing code's philosophy for malformed-yet-benign headers is to drop
   (underscore names) rather than reject the whole request. Throwing here would
   be a behaviour change inconsistent with the neighbour. Dropping is the
   conservative, least-surprising choice.

3. **Fold into the previous value.**
   Implementing true obs-fold folding would require tracking the previous header
   line and its value across `parseRawHeaderLines()` — a larger change than the
   issue warrants. Reject/drop is explicitly acceptable under RFC 7230 §3.2.4.

## Uncertainties
- Whether to log the drop. Decided against it for size/consistency; can be
  revisited if the team wants symmetry with the underscore path.
- Behaviour for a *trailing*-whitespace name (`"X-Trail : v"`). The same
  `name !== trim(name)` check covers it and the test confirms it is dropped
  (and the trimmed key is also not silently created).
