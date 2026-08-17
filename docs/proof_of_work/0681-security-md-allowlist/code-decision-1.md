# code-decision-1.md — merge duplicate allowlist bullets in docs/security.md (issue #681)

Round 1. Scope: docs-only change for issue #681, touching exactly one file —
`docs/security.md` — plus this directory.

## The approach

**Deleted the redundant bullet, kept the explanatory first bullet intact.**
The two bullets in `docs/security.md`'s "Security Considerations" list were:

- 225: `- **Prefer the allowlist over the denylist**: without
  $allowedExtensions, the default posture is "serve everything except the
  denylist above" — safe for a directory that contains only public assets,
  but the denylist is a last line of defence, not a guarantee. Configure
  $allowedExtensions to only permit the file types your application
  actually serves as static assets.`
- 226: `- **Use the allowlist**: Configure $allowedExtensions to only
  permit the file types your application actually serves as static
  assets.`

Bullet 226 is a verbatim subset of bullet 225's final sentence, exactly as
the issue states. The fix is a one-line deletion: bullet 225 already
*contains* the merged form, so removing 226 produces the merged bullet with
zero information loss.

**The brief's suggested wording was rejected in its literal form.** The
brief suggested the merged bullet read `- **Prefer the allowlist over the
denylist** — configure $allowedExtensions to only permit the file types
your application actually serves as static assets.` That wording drops the
"default posture / denylist is a last line of defence, not a guarantee"
reasoning, which is the *why* that motivates the allowlist. I verified the
whole Static Files Protection section of security.md (lines ~130-232): the
"Extension Allowlist" section explains the mechanics (only listed
extensions served, denylist takes precedence) but the default-posture
framing of the denylist exists nowhere else in the file. Deleting it would
weaken the doc for an issue whose stated complaint is only the duplicated
sentence. The issue title itself ("'Use the allowlist' bullet is a verbatim
subset… merge into one") is satisfied by the deletion.

**Cross-reference check.** Grepped the whole repo (excluding vendor,
node_modules, .git, and the untracked `.pi-subagents` agent artifacts) for
`Use the allowlist` and `Prefer the allowlist`: the only hits are the two
bullets themselves. No tests, no other docs, no KB entries reference either
string, so the deletion breaks nothing.

## What I was unsure about

1. Whether the issue author intended the default-posture explanation to
   survive. The issue body's complaint is exclusively about the duplicate
   sentence; the suggested merged wording in the brief is likely an
   auto-generated shorthand. I chose the information-preserving variant and
   flagged the deviation here; if review prefers the tighter wording it is
   a one-line follow-up.
2. Whether `composer test` was needed. The change touches a `.md` file only;
   no PHP, YAML, or bin/ script changes, and the grep proved no test
   references the edited strings. `composer lint` was run because
   `bin/kb-lint.php` may sweep markdown; the full daemon-booting test
   suite is unnecessary for this diff.
