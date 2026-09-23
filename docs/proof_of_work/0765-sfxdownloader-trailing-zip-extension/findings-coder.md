## #765 implementation findings

- `src/Phar/SfxDownloader.php::locateSfxEntry()` used `str_replace()` and therefore removed `.zip` segments anywhere in the archive basename. The new suffix-only removal addresses the reported wrong-first-candidate path; the archive-entry fallback remains intact.
- Biggest implementation obstacle: the target method is private and the public download flow couples it to archive extraction/network setup. The existing test class already uses reflection helpers for private SfxDownloader methods, so a focused filesystem-backed test can exercise the behavior without a network request.
- No additional out-of-scope bugs identified during this change.
