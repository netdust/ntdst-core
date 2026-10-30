# Threat model — the abilities bridge (Class D, stakes high)

**The diff.** A route declared through `ntdst_rest()` with an `ability` option also becomes
a WordPress ability. Its `execute_callback` dispatches that route through
`rest_do_request()`; its `permission_callback` is the route's resolved permission. An MCP
client is a logged-in user, and the route's capability is the floor.

**Asset.** The write surface behind capabilities such as `stride_manage`, now reachable by
an LLM client acting as a logged-in user; the limiter's budget (`wp_options` rows); the
application password or session the MCP user holds; the inventory of what exists (names,
descriptions, schemas); and, because every ability is written `mcp.public`, the DATA each read route returns, now relayed through an LLM client rather than a browser the consumer controls.

| # | Threat | Mitigation in this diff |
|---|---|---|
| T1 | **An MCP client beyond its capability executes a write.** A logged-in user holding an application password reaches `WP_Ability::execute()` → `execute_callback`. | The ability's `permission_callback` is the route's resolved permission and denies first. Past it, `execute_callback` dispatches through `rest_do_request()`, so the route's `guard()` answers with its own 401/403 and the handler never runs. Lives in `walkAbilities()` / `abilityCallbacks()` (`api/Rest.php`). Cost named: a consumer's permission callable runs on two request objects per execution (the memoize is per object), so a side-effectful callable fires twice; WordPress requires a callable and uses it for discovery, so `__return_true` is not an option. |
| T2 | **Discovery as a drain.** Any `read` user listing abilities or asking `check_can_execute` runs the `permission_callback`. | The permission handed to WordPress is the UNGUARDED resolved permission; the limiter is charged only inside the dispatch. `abilityCallbacks()` reads the record's `permission`, never `guard()`. |
| T3 | **An anonymous destructive ability.** Nobody logged in; a `->public()` write carries an `ability` block. | The verb rule in `registerOne()` refuses a `->public()` write before the block is read (INV-3): no record, no ability. Lives in `registerOne()` (existing). |
| T4 | **A second door.** A consumer calls `wp_register_ability()` with its own permission. | INV-11's grep in `bin/guard.sh` fails the gate; drift check #16 covers consumers. Lives in `bin/guard.sh`, `ARCHITECTURE-INVARIANTS.md`. |
| T5 | **Path smuggling.** An input value for a path parameter such as `12/../../wp/v2/users`. | Values are `rawurlencode`d into the path; the REST server matches the route's own regex and answers `rest_no_route` to anything else. Lives in `abilityRequest()`. |
| T6 | **A declaration that never becomes an ability.** A consumer declares after `wp_abilities_api_init` finished. | `abilities:after-init`, one `_doing_it_wrong` per declaration; the route still registers (R4 for core, the R15 trap for consumers). Lives in `registerOne()` via `timing('wp_abilities_api_init')`. |
| T7 | **A lying annotation.** `readonly` on a GET whose handler writes. | `readonly` is derived from the verb; `destructive` / `idempotent` are the consumer's own words, and core cannot read a handler. Deferred to the upstream `wp-abilities-verify` adversarial check, run on the Stride branch (Task 7). A wrong output schema on a non-idempotent write is a committed effect reported as a failure: WordPress validates the output after the handler ran, and the client's natural answer to `ability_invalid_output` is a retry. The `execute-once` flow is the proof per write ability. |
| T8 | **The walk's side effect.** `rest_get_server()` inside the abilities hooks on an admin or CLI request fires `rest_api_init` there. | Accepted, Decision 13: WordPress's own admin already does this for the editor. Lives in the two walks (`walkCategories()`, `walkAbilities()`). |
| T9 | **A read ability as a PII channel.** Actor: an LLM client acting for a capability holder, or a prompt injected into it. Path: `execute-ability` → the route's data in the model context. Same capability floor, new client class: a registrar that never wrote `public` / `mcp.public` kept its abilities off the MCP Adapter; a declaration here puts them on it. | None in this diff — there is no MCP-private ability (Decision 12); the only control is not declaring. Deferred to the consumer: declare `ability` only on routes whose response may leave the browser; the block's `output_schema` does not strip fields (WordPress validates, it does not filter), so narrowing means a dedicated route; name each such route in the consumer's threat model. On Stride, `search-users` (every user's email and organisation) is the instance, and its declaration carries that sentence. |

**Deferrals.** Prompt injection through `label` / `description` text is the consumer's
words and out of scope for core (R7). Who may hold an application password at all is
baseline's C1 (plan 4; administrators by default). The MCP transport's own authentication
is the adapter's (REST transport = WordPress auth; STDIO = the CLI user). A consumer
A consumer permission callable on the ability's first run sees a synthetic request with no headers and raw params — schema-checked by WordPress, never sanitised by the route (the route's sanitising runs only inside the dispatch, before the second run); one that reads a header (a nonce, an origin) finds none and denies: narrower, never wider. A third plugin registering abilities
from inside `rest_api_init` while the walk runs is wrong on WordPress's own terms
(`wp_register_ability` refuses outside its hook). WordPress < 6.9: inert, one notice per
declaration, the route registers. Who may hold an application password is baseline C1; the adapter's transport gate is `read` by default (`mcp_adapter_default_transport_permission_user_capability`) — a site that wants MCP narrower than the capability floor filters there. A `Throwable` from the permission callable or the handler is caught by WordPress and its (escaped) message returned to the client as `ability_callback_exception` through the run controller — further than the route's generic fatal page; authenticated callers only, upstream behaviour. `rest_do_request()` is `dispatch()` only and does not re-run `rest_authentication_errors`: a site rule hooked there and keyed on the inner route's path sees `/wp-abilities/v1/…` or `/mcp/…` instead, so the ability is a second door for such rules; `rest_pre_dispatch` rules, including `charge()`'s documented pattern, still apply.

**Denial path under test.** `testAnUnauthorizedExecutionIsRefusedBeforeTheHandlerRuns`,
`testThePermissionCallbackChargesNothing` and `testAPublicWriteNeverBecomesAnAbility`, each
asserted RED first. T1's second half, the route's 401/403 inside `rest_do_request()`, is
not unit-tested in the new files (they stub `rest_do_request()`); it rests on the existing
guard tests and the Stride `denied-write` flow.
