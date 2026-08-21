# Threat model — `NTDST_Rest::guard()` ordering (Class D, stakes standard)

**The diff.** `api/Rest.php` `guard()` charges the rate limiter and *then* evaluates the
permission callback. This reverses the two.

**Asset.** The `wp_options` table (transient storage), and the integrity of the per-route
rate limit.

**Precedent.** `api/Actions.php` fixed this exact defect (M2) and wrote the reasoning
down: *"A caller who can never dispatch this action must not be able to make the site
write storage on demand: charging first meant every doomed anonymous request left 2
wp_options rows behind, reaped only by a daily cron. Reading the current user costs
nothing and answers the question outright."* `Rest.php` never received it.

| # | Threat | Mitigation in this diff |
|---|---|---|
| T1 | An anonymous or unprivileged caller hits a route they can never pass; each attempt writes a transient before the refusal. | Permission is evaluated first; a refused caller reaches no limiter code and writes nothing. |
| T2 | Bucket key space grows with distinct callers. Anonymous callers key on hashed IP, so a botnet multiplies rows even though one attacker cannot. Bounded per IP, unbounded in practice across many. | Same reorder. Rows are only written for callers who passed authorization. |
| T3 | **Regression risk** — an authorized caller stops being throttled. | The success path still charges. Asserted by the existing burst test remaining green. |
| T4 | **Regression risk** — the sibling-route drain returns. WordPress calls every sibling route's permission callback to build the `Allow` header, so an unmatched verb must not spend the matched route's budget. | The `$matched` verb check is preserved ahead of the charge, unchanged. |
| T5 | **Residual, accepted.** A refused caller is no longer throttled and may repeat the refusal freely. | Deliberate, and identical to the trade `Actions.php` made: the refusal costs a capability check, not a write. The request still pays WordPress's REST bootstrap, which this limiter never prevented either. |
| T6 | **Out of scope, stated so it is not read as an inconsistency.** `charge()` bills without a permission check. | Unchanged by design — it exists so a consumer can bill its own pre-dispatch refusal. |

**Denial path under test.** A route declaring a denying permission plus a `rate_limit`,
driven through its registered `permission_callback`, must refuse *and* write zero
transients. That assertion fails before the fix and passes after it.
