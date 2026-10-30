# Threat model — the abilities bridge (Class D, stakes high)

**The diff.** A route declared through `ntdst_rest()` with an `ability` option also becomes
a WordPress ability. Its `execute_callback` dispatches that route through
`rest_do_request()`; its `permission_callback` is the route's resolved permission. An MCP
client is a logged-in user, and the route's capability is the floor.

**Asset.** The write surface behind capabilities such as `stride_manage`, now reachable by
an LLM client acting as a logged-in user; the limiter's budget (`wp_options` rows); the
application password or session the MCP user holds; the inventory of what exists (names,
descriptions, schemas).

| # | Threat | Mitigation in this diff |
|---|---|---|
| T1 | **An MCP client beyond its capability executes a write.** A logged-in user holding an application password reaches `WP_Ability::execute()` → `execute_callback`. | The ability's `permission_callback` is the route's resolved permission and denies first. Past it, `execute_callback` dispatches through `rest_do_request()`, so the route's `guard()` answers with its own 401/403 and the handler never runs. Lives in `walkAbilities()` / `abilityCallbacks()` (`api/Rest.php`). Cost named: a consumer's permission callable runs on two request objects per execution (the memoize is per object), so a side-effectful callable fires twice; WordPress requires a callable and uses it for discovery, so `__return_true` is not an option. |
| T2 | **Discovery as a drain.** Any `read` user listing abilities or asking `check_can_execute` runs the `permission_callback`. | The permission handed to WordPress is the UNGUARDED resolved permission; the limiter is charged only inside the dispatch. `abilityCallbacks()` reads the record's `permission`, never `guard()`. |
| T3 | **An anonymous destructive ability.** Nobody logged in; a `->public()` write carries an `ability` block. | The verb rule in `registerOne()` refuses a `->public()` write before the block is read (INV-3): no record, no ability. Lives in `registerOne()` (existing). |
| T4 | **A second door.** A consumer calls `wp_register_ability()` with its own permission. | INV-11's grep in `bin/guard.sh` fails the gate; drift check #16 covers consumers. Lives in `bin/guard.sh`, `ARCHITECTURE-INVARIANTS.md`. |
| T5 | **Path smuggling.** An input value for a path parameter such as `12/../../wp/v2/users`. | Values are `rawurlencode`d into the path; the REST server matches the route's own regex and answers `rest_no_route` to anything else. Lives in `abilityRequest()`. |
| T6 | **A declaration that never becomes an ability.** A consumer declares after `wp_abilities_api_init` finished. | `abilities:after-init`, one `_doing_it_wrong` per declaration; the route still registers (R4 for core, the R15 trap for consumers). Lives in `registerOne()` via `abilitiesTiming()`. |
| T7 | **A lying annotation.** `readonly` on a GET whose handler writes. | `readonly` is derived from the verb; `destructive` / `idempotent` are the consumer's own words, and core cannot read a handler. Deferred to the upstream `wp-abilities-verify` adversarial check, run on the Stride branch (Task 7). |
| T8 | **The walk's side effect.** `rest_get_server()` inside the abilities hooks on an admin or CLI request fires `rest_api_init` there. | Accepted, Decision 13: WordPress's own admin already does this for the editor. Lives in `mountAbilities()`. |

**Deferrals.** Prompt injection through `label` / `description` text is the consumer's
words and out of scope for core (R7). Who may hold an application password at all is
baseline's C1 (plan 4; administrators by default). The MCP transport's own authentication
is the adapter's (REST transport = WordPress auth; STDIO = the CLI user). A consumer
permission callable that reads request headers (a nonce, an origin) sees a synthetic
request with none and denies: narrower, never wider. A third plugin registering abilities
from inside `rest_api_init` while the walk runs is wrong on WordPress's own terms
(`wp_register_ability` refuses outside its hook). WordPress < 6.9: inert, one notice per
declaration, the route registers.

**Denial path under test.** `testAnUnauthorizedExecutionIsRefusedBeforeTheHandlerRuns`,
`testThePermissionCallbackChargesNothing` and `testAPublicWriteNeverBecomesAnAbility`, each
asserted RED first. T1's second half, the route's 401/403 inside `rest_do_request()`, is
not unit-tested in the new files (they stub `rest_do_request()`); it rests on the existing
guard tests and the Stride `denied-write` flow.
