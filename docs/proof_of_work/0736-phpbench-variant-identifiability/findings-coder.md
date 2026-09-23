## #736 implementation findings

- Biggest implementation obstacle: identifying why `variant`/`params` rendered null even though phpbench's built-in `default` report showed the set names. The difference is the expression generator's `aggregate` partition: without a `variant_index` partition, `variant_name` is null. Probed empirically with throwaway `phpbench.json` configs before touching the committed one.
- The fix is confined to `phpbench.json`; no benchmark PHP changes were needed.
- FAQ-028 currently states "the repo's `aggregate` report renders the `params` column as null, so parameterized rows are distinguishable only by provider order". That guidance is now stale and a candidate knowledge-base update (promote/fix FAQ-028) is proposed in the report; the main session owns `docs/helpers/`.
- No additional out-of-scope bugs identified.
