# Feature review — abilities-bridge (ntdst-core 5.5.0)

**Reviewed:** `origin/feature/abilities-bridge` `8d89c53` over `9a593c5` (v5.4.2) — plan `specs/abilities-bridge/plan.md` (two review rounds), spec `netdust-plugins/specs/wp-ai-base/spec.md` R1–R7 — plus the named consumer's proof, Stride `chore/abilities-bridge` `926e9dad` over `3e2197ce` (spec R6, never merged). Reviewers, all on the most capable model, on a detached checkout of the head with the gate reproduced there (`OK (1277 tests, 4011 assertions)`, `bin/guard.sh` rc 0, `bin/zero-readers.sh` 0 findings, INV-11's grep empty): superpowers' code reviewer, `security-sentinel` (the plan carries a threat model), `invariant-auditor` (`ARCHITECTURE-INVARIANTS.md`, INV-11 included), `netdust-wp:ntdst-drift-reviewer` (both trees). Reviewed 2026-10-30; the four reports follow this summary.

## Blocking

None.

## Should fix — the one fix pass, each closed RED→GREEN through its seam

1. **`api/Rest.php:315` — `abilityInputSchema()` keeps its own list of eleven JSON-Schema keys where WordPress has `rest_get_allowed_schema_keywords()`** (INV-5; the cause of the coder's C1 and C4: Stride's `user_ids` `maxItems: 500` never reaches the ability's `input_schema`, so the LLM sees no cap). Fix: `$keys = array_flip(rest_get_allowed_schema_keywords())`; stub the function in both test setUps; one `maxItems` assertion in `testTheRegisteredArgsAreExactForAReadAndAWrite`; README's eleven-keys sentence names the WordPress function instead. — code reviewer I1
2. **`api/Rest.php:380-393` — the synthetic request never receives `set_url_params()`**, so a per-object permission callable (`fn($r) => current_user_can('edit_post', (int) $r['id'])`) denies every caller through the ability while the same route passes over HTTP (fail-closed; a dead ability for a legitimate declaration). Fix: collect the substituted values in the callback, `$request->set_url_params($values)` after construction, assert `get_url_params()` in `testAPathParameterIsEncodedNotInterpolated` (the shared double gains `get_url_params()`); README's two-request sentence says the first one now carries the path too. — code reviewer I2
3. **The threat model does not state the exposure delta** (`specs/abilities-bridge/plan.md` `## Threat model` and `docs/threat-models/2026-10-abilities-bridge.md`): core writes `mcp.public` on every ability with no opt-out short of not declaring, so on Stride `search-users` (every user's email and organisation to any `stride_view` holder) and the three writes become MCP tools for any capability holder with an application password — a channel the old registrars did not reach. Same capability floor, new client class. Fix: the Asset sentence, a T9 row "a read ability as a PII channel" (mitigation in this diff: none — the consumer declares `ability` only on routes whose response may leave the browser; `output_schema` validates, it does not filter), the Deferrals line (who holds an application password is baseline C1; the adapter's transport gate is `read` and filterable); the sentinel's three further Deferrals sentences (the first permission run sees raw params, never the route's sanitising; exception messages reach the client through the run controller; `rest_authentication_errors` is not re-run inside `rest_do_request()`); one line under T7 (a wrong output schema on a non-idempotent write is a committed effect reported as a failure); T8 "lives in the two walks" (code reviewer M10). On the Stride branch: one sentence beside `search-users` (`AdminAPIController.php:406-408`) naming the PII it relays. — security-sentinel I1, M1, M3, M5, M6
4. **`api/Rest.php:98` — `PATH_PARAMETER_TYPE` is a new INV-8 (B) hit the doc does not name**: (B) returns 2, the Status line says 1, and the constant exists to dodge the retired-type pin (INV-8's own rule: name it, never rename around it). Fix: keep the constant (the guard-exemption alternative costs more) and name it under `### INV-8 — every hit the field-type check returns`, group "(B)'s hits", with the auditor's text; Status "Re-run verbatim at 5.5.0: (A) 55, same per-file shape; (B) 2". — invariant-auditor D1, drift reviewer
5. **`api/Rest.php:833-840` — `abilitiesTiming()` duplicates `timing()`** (same body, one hook literal), and `timing()`'s docblock "the ONE place the question is asked" is now false. Fix: `timing(string $hook = 'rest_api_init')`, the three abilities call sites (`:187`, `:1025`, `:1084`) pass `'wp_abilities_api_init'`, `abilitiesTiming()` deleted; the existing timing tests are the GREEN. — invariant-auditor R1/D2, drift reviewer
6. **`ARCHITECTURE-INVARIANTS.md` record drift introduced by this branch**: INV-7's Status pins (`:928`/`:949`) moved to `:1304`/`:1325` — re-pin at 5.5.0; INV-11 gains its calibration sentence (the comment filter drops nothing; on a consumer the bare word also matches a `function_exists('wp_register_ability')` version guard — drop that shape by content, `| grep -vE "function_exists\(['\"]wp_register_ability"`, rather than switching to the call shape, which loses a string-callable registration; drift check #16 runs the call shape plus `AbilityRegistrar`) and its Status re-run at the head commit; INV-5's `Rest::$abilities` clause names the duplicate check in `ability()` as its third reader; the doc header notes that ugrep honours `.gitignore` on a recursive run, so a consumer-root run skips composer-installed directories unless `--no-ignore-files` is given. — invariant-auditor D3, B6, B4, B1
7. **README `### 5.5.0`, three sentences**: on a bridge-down request (WordPress < 6.9, or a late mount) the `ability` block is not validated — the route registers, one debug notice, nothing else (Decision 3; ruling: keep it inert — invariant-auditor B3); `['type' => 'object']` is a valid, permissive `output_schema`, so the response-schema price is optional (code reviewer); the path paragraph names that regex outside named groups stays in the built path and that substituted values arrive percent-encoded (code reviewer M4).
8. **Stride proof — the deleted `ReadAbilityRegistrar::init()` also hooked `ntdst_assistant/system_prompt`** (`injectDomainPrompts`), the only reader of `Modules/Assistant/prompts/domain.md` and `formatting.md`; on the branch the assistant silently lost Stride's domain and formatting prompts and two files have no reader. Fix: a plain class (`Modules/Assistant/AssistantPrompts.php`) hooking the filter from `plugin-config.php`, and F10 in the findings list ("a deleted registrar carries more than registrations"). — drift reviewer (Drift)
9. **Stride proof — `Handlers/BulkRegistrationHandler.php:120` `'maxItems' => 500` beside `Handlers/Support/BulkRunner.php:33` `MAX_BATCH = 500`**, a second home of the cap that may drift. Fix: `public const MAX_BATCH`, `'maxItems' => BulkRunner::MAX_BATCH`. — drift reviewer (Borderline)

## Minor — in the same pass where cheap, else ledgered as deferred

- `api/Rest.php:387` — `ltrim` where WordPress `trim`s: a route declared `/foo/` dispatches to `/ns/foo/` and gets `rest_no_route` through this door only. One word, one test case. — code reviewer M3, security-sentinel M4b
- Multi-verb routes (`'GET, POST'`): annotated as a write, dispatched as the first verb with query params. Ruling: refuse at declaration — `ability:methods — one verb per ability` — a twelfth provider case; R3's shape. — code reviewer M5, security-sentinel M4a
- Non-bool permission results: the route's guard admits `1`, WordPress's ability check coerces it to `false`. `(bool)` for non-`WP_Error` results in the permission closure, one test. — code reviewer M6, security-sentinel M2
- Typeless-only args give `'properties' => []` (a JSON array where JSON Schema wants an object). `(object) []`, or omit `properties` when empty. — code reviewer M7
- No test for a missing named-group value (`''` → the server's regex refuses). — code reviewer M9

## Note — no change on this branch

- INV-11's bare-word grep vs drift check #16's call shape (the coder's C2, code reviewer M1): the auditor's verdict is to keep the bare word in core — it also catches `call_user_func('wp_register_ability', …)` — and to calibrate the consumer run by content (item 6); #16 already is the call shape. The spec's R5 line and the harness are netdust-plugins follow-ups. — invariant-auditor, drift reviewer
- Harness `netdust-wp/skills/ntdst-framework/SKILL.md:114-115` over-states what shipped ("the permission and the limiter run once"; "core derives nothing from the verb" — `readonly` is the verb's and a read's annotations are ignored, Ruling 8). A netdust-plugins follow-up. — code reviewer
- Two calibration lessons for `ntdst-drift-reviewer.lessons.md`: a `function_exists('wp_register_ability')` presence test is not a door; on a #16 fix that deletes a `*AbilityRegistrar`, list every hook the class registered and name the new home of each one that is not an ability. `/session-learn` candidates. — drift reviewer
- INV-11 has no suite-side mirror beside INV-2's in `CoreShapeCluster3FeatureTest` (B5) — precedent, not a rule. — invariant-auditor
- Discovery invokes no consumer permission at all (the threat model's T2 over-states WordPress; harmless). — security-sentinel
- One rule spelled three times (`/^[a-z0-9-]+$/` at `:1107`, `:1113`, `:1144`) — a `private const` would be one home; nit. — invariant-auditor B2
- Pre-existing line-pin drift in INV-1, INV-4 and INV-7's MetaboxGenerator pins at base — not this branch's. — invariant-auditor
- The posture itself — unconditional `mcp.public`, spec Decision 12 — is Stefan's decision; the reviewers require only that the threat model states its consequence (item 3). — security-sentinel

Findings: 0 Blocking · 9 Should fix · 13 Note


---

# Security review — ntdst-core `feature/abilities-bridge` (5.5.0), `9a593c5..8d89c53` — security-sentinel

Checkout read: `/tmp/claude-1000/-home-ntdst-Projects-netdust-plugins/f07572ac-69e7-49a9-aa74-73907cf6b5c6/scratchpad/review-8d89c53/` (line numbers below are the checkout's, not the diff's). WordPress 7.1 source read at `/home/ntdst/Sites/stride/web/wp/wp-includes/rest-api/` (the real `WP_REST_Server::dispatch()` / `WP_REST_Request`) and the abilities copy in the scratchpad. MCP Adapter 0.7.1 read at `/home/ntdst/Sites/worktrees/stride-abilities-bridge/web/app/plugins/mcp-adapter/includes/`. Consumer proof read at `/home/ntdst/Sites/worktrees/stride-abilities-bridge/` (`3e2197ce..926e9dad`). Checklist: `~/.claude/plugins/netdust-wp/references/wp-review-checklists.md` (SEC-nn cited per item at the end).

## Executive summary

No Critical, no code-level Important. The bridge preserves the route's permission floor twice (WordPress's `check_permissions` on the unguarded resolved permission, then the route's own `guard()` inside `rest_do_request()`), the limiter is charged exactly once inside the dispatch, path input cannot escape the shadowed route, and a `->public()` write cannot become an ability. Every mitigation in the plan's `## Threat model` and `docs/threat-models/2026-10-abilities-bridge.md` does what it says and lives where it says.

One Important finding, and it is a statement gap in the threat model rather than a defect: core writes `public` / `show_in_rest` / `mcp.public` all `true` on every ability, there is no opt-out short of not declaring, and on Stride that moves five routes — including `search-users`, which returns every user's email and organisation to any `stride_view` holder — from a channel the old registrars did NOT reach (the MCP Adapter) onto it. Same capability floor, new client class. The threat model names the asset but not this delta. Six Minor notes, all narrower-than-route or documentation.

## Findings

### Critical
None.

### Important

**I1 — The exposure delta is real on the consumer and the threat model does not say it (your Q4).**
`api/Rest.php:290-292` writes `'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true]` unconditionally. The MCP Adapter decides exposure at `includes/Abilities/McpAbilityExposure.php:63-67` (`mcp.public` if set, else `meta.public`, else false); its transport gate defaults to `read` (`includes/Transport/HttpTransport.php:122-129`), and the `discover-abilities` / `execute-ability` meta-tools gate on logged-in + `read` (`DiscoverAbilitiesAbility.php:126-142`, `ExecuteAbilityAbility.php:209-228`). The deleted Stride registrars set `show_in_rest => true` and `annotations` only — never `public` or `mcp.public` (review-stride-proof.diff lines 555-559, 1941-1945) — so under 7.1's `prepare_properties()` (`class-wp-ability.php:370-371`) they resolved `public = false` and the adapter did not list them; only the server-side ntdst-assistant reached them (it filters on `show_in_rest`, `web/app/plugins/ntdst-assistant/src/AbilityBridge.php:45-47`). After the proof branch, `stride-v1/search-users` (`AdminAPIController.php:394-410`; the handler returns `user_email`, :2121-2126) and the three writes are MCP tools for any user holding `stride_view` / `stride_manage` plus an application password, driven by an external LLM.

The capability floor is unchanged (that is T1 and it holds). What is new is the client: an LLM whose context goes to a model provider and which can be prompt-injected into calling `search-users` and relaying the result. That is a data-handling risk the consumer owns, but core's decision created it, so core's threat model should state it in one place so a consumer reads it before declaring. Decision 12 ("declared is exposed; no private ability") is in README `### 5.5.0` (:101-102) but not in the threat model.

What I would add, verbatim-sized:
- Under **Asset**: "…the inventory of what exists; and, because every ability is written `mcp.public`, the DATA each read route returns, now relayed through an LLM client rather than a browser the consumer controls."
- A row **T9 — A read ability as a PII channel.** Actor: an LLM client acting for a capability holder, or a prompt injected into it. Path: `execute-ability` → the route's data in the model context. Mitigation in this diff: none — there is no MCP-private ability by Decision 12; the only control is not declaring. Deferred to the consumer: declare `ability` only on routes whose response may leave the browser; narrow with the block's `output_schema` (it does not strip fields — WordPress validates, it does not filter — so narrowing means a dedicated route); name each such route in the consumer's threat model. On Stride: `search-users` (email, organisation) is the instance; its declaration should carry that sentence.
- One line in **Deferrals**: "Who may hold an application password is baseline C1; the adapter's transport gate is `read` by default (`mcp_adapter_default_transport_permission_user_capability`) — a site that wants MCP narrower than the capability floor filters there."

### Minor

**M1 — The first permission run sees un-sanitised input; the deferral says "headers", not "params" (your Q5).** `abilityInputSchema()` copies only JSON Schema keys (`api/Rest.php:315`) and drops `pattern`, `validate_callback`, `sanitize_callback`, `additionalProperties`. WordPress validates the ability input against that derived schema (`class-wp-ability.php:519-536`) and hands it to the permission callable on a synthetic request (`:361-363`) that never passes `sanitize_params()`; via `check_permissions()` called directly — the assistant at `AbilityBridge.php:86,147`, the adapter at `ExecuteAbilityAbility.php:192` and `McpTool.php:379` — it is raw, as WordPress's docblock warns (`:609-610`). The second run, the guard inside `dispatch()`, sees validated and sanitised params (`class-wp-rest-server.php:1063-1120`, `class-wp-rest-request.php:833-860`, default `rest_parse_request_arg` for typed args). Narrower, never wider — the handler only ever runs after the second — but a consumer callable that reads a param (`current_user_can('edit_post', $request->get_param('id'))`) runs on raw bytes once. On Stride all five permissions are capability strings; no impact. Add to Deferrals: "…sees a synthetic request with no headers and raw params (schema-checked by WordPress, never sanitised by the route)".

**M2 — A non-bool truthy permission result passes the route and is refused by the ability.** `guard()` accepts anything truthy that is not a `WP_Error` (`api/Rest.php:1309`); `WP_Ability::check_permissions()` coerces non-bool, non-`WP_Error` to `false` (`class-wp-ability.php:653-655`). A consumer callable returning `1` or a string allows the route and denies the ability. Narrower, inconsistent. Optional one-liner in `abilityCallbacks()` (:361-363): return `$r instanceof WP_Error ? $r : (bool) $r`. Or leave it and name it.

**M3 — Output validation runs after the write committed.** `WP_Ability::execute()` validates output after `do_execute()` (`class-wp-ability.php:854-862`). A wrong `schema` / `output_schema` on a write ability means the handler ran, the side effect is committed, and the LLM is told `ability_invalid_output` — whose natural reaction is to retry. Stride's three writes are `idempotent: true` (`EditionAdminController.php:115`, `BulkRegistrationHandler.php:130` — repeat enrol answers `already_enrolled`), so the proof is safe; README :116-118 already says the schema describes the response. Worth one line under T7's family: "a wrong output schema on a non-idempotent write is a committed effect reported as a failure; the `execute-once` flow is the proof per write ability".

**M4 — Two `abilityRequest()` edges, functional not security.** (a) Multi-verb `->route('/x', 'GET, POST', …)`: `readonly` is false (:1062) so write annotations are demanded, but the dispatch verb is the first one, `GET`, with query params (:377, :389-390) — an ability annotated as a write that performs the read branch. Narrower; refuse (`ability:verbs — one verb per ability`) or document. (b) `ltrim` (:387) vs WordPress's `trim` in `register_rest_route()`: a route declared with a trailing slash never matches its own regex → `rest_no_route`. (c) The nested-parentheses limitation (:380 comment) leaves a dangling `)` in the path → `rest_no_route`. All three fail closed.

**M5 — Exception messages surface further than on the route.** `WP_Ability::invoke_callback()` catches a `Throwable` from the permission callable or the handler and returns `ability_callback_exception` with `esc_html($e->getMessage())` (`class-wp-ability.php:591-603`); the run controller returns the permission `WP_Error` as-is with a 401/403 (`class-wp-rest-abilities-v1-run-controller.php:172-176`) and `execute_ability()` returns the execute error as-is (:93-96). On the plain route an uncaught exception is WordPress's generic fatal page. Authenticated callers only; upstream behaviour. Name it in Deferrals.

**M6 — `rest_do_request()` skips `check_authentication()`.** `serve_request()` runs `rest_authentication_errors` (`class-wp-rest-server.php:438, :197`); `rest_do_request()` is `dispatch()` only (`rest-api.php:613-616`), which does run `rest_pre_dispatch` (:1079), `has_valid_params`, `sanitize_params`, the route's `permission_callback` and `rest_request_before/after_callbacks`, but not `rest_post_dispatch` (the `_fields` filter, `rest_send_allow_header`). A site rule hooked on `rest_authentication_errors` and keyed on the inner route's path (the "Disable REST API"-style allowlist) sees `/wp-abilities/v1/…` or `/mcp/…`, not `/stride/v1/…`. No such rule in ntdst-core, ntdst-baseline, or Stride (grep: only `netdust-lti`'s `rest_pre_dispatch` on `/wp/v2/lti-*`, `LTIDataService.php:39-52`, which does run inside the dispatch). Name it in Deferrals: "the ability is a second door for `rest_authentication_errors`-keyed rules; `rest_pre_dispatch` rules, including `charge()`'s documented pattern (:1366-1374), still apply".

## Answers to the five questions

**Q1 — Can input reach another route or widen the method? No.** Namespace and route come from the record (:375-387); only named groups are substituted, each `rawurlencode`d (:381) so `/` becomes `%2F`, and `match_request_to_handler()` matches the raw `get_route()` against each route's regex with no decoding (`class-wp-rest-server.php:1155-1172`) → `rest_no_route`. `.` is unreserved so `..` survives, but nothing normalises it and the route's own regex decides — the same bytes a `?rest_route=` client could send. Non-path input goes to query params on `GET/HEAD/OPTIONS` or body params otherwise (:389-392); neither can alter the match, and the inner `dispatch()` validates + sanitises typed args. The verb is the first declared verb (:377), never from input; the WordPress run controller additionally pins the OUTER method to the annotations (`run-controller.php:110-133`). Pinned by `testAPathParameterIsEncodedNotInterpolated`. Edges: M4.

**Q2 — Executed or discovered by someone the route refuses? Execute: no.** The ability's `permission_callback` is the route's resolved permission (:361-363, stored at :1058 before `guard()` wraps it at :1050), and the dispatch re-runs the guard on the inner request. `wp_pre_execute_ability` short-circuits before anything is dispatched; `wp_ability_permission_result` could widen the first check but the guard still refuses and the handler never runs. **Discovery charges nothing and invokes no consumer permission:** `wp_get_abilities()` (`abilities-api.php:498-573`) calls none; the REST list controller gates on `read` + `show_in_rest` (`list-controller.php:180-194`, :90); the adapter's `discover-abilities` lists name/label/description after `read` (`DiscoverAbilitiesAbility.php:86-108`); `tools/list` calls no per-tool permission (`ToolsHandler.php:66-110`); the assistant lists by `show_in_rest` (`AbilityBridge.php:45-47`). What a `read` user sees: name, label, description, category, input_schema (= args), output_schema (= schema), annotations. The route already gives args and schema to ANYONE via `OPTIONS` (`rest_handle_options_request`, `rest-api.php:837-864`, `help` context → `class-wp-rest-server.php:1651`) and the namespace index. So the only new text is label/description/annotations — the consumer's words (R7). One asymmetry to know: `show_in_index => false` hides a route from the index but not its ability from the list. A `->public()` GET ability is anonymous-executable through `/wp-abilities/v1/<name>/run` (`__return_true`), equal to the route; the limiter still charges inside the dispatch.

**Q3 — Anonymous destructive ability? No.** `registerOne()` refuses a shorthand posture (`is_user_logged_in` or `__return_true`) on any verb outside `GET/HEAD/OPTIONS` at :987-995, before the block is read at :1024. `$anonymous` arrives only through `public()` (:707-744), which writes a property on the pending declaration, never an option; `'permission' => 'public'` is refused at :962; the string `'__return_true'` resolves to `current_user_can('__return_true')` (:935-939) — nobody. Pinned by `testAPublicWriteNeverBecomesAnAbility`. The one route to an anonymous write is a consumer closure `fn() => true` — the route's existing posture, counted by Stride's own INV table (two, neither with `ability`).

**Q4 — See I1.** The threat model does not state the consequence plainly enough. Core writes the three flags with no opt-out; the old registrars were not MCP-public; `search-users` is the concrete instance; the text I would add is above.

**Q5 — Double evaluation abuse?** Nothing exploitable beyond the route's own posture. (a) Refusal residual: a user without the capability can drive the consumer's callable unboundedly through `run` or `execute-ability` at zero limiter cost — identical to the route, whose guard returns refusals before charging (:1304-1311, documented residual :1290-1293). (b) Side effects fire 2× per execution (WordPress check + guard), 3× through the assistant (`AbilityBridge.php:86` + execute + guard), 4× for a confirmed write (:147 re-check); a consumer-authored limiter INSIDE a permission closure is charged that many times and once per discovery-side `check_permissions`; core's limiter is not. T1 names 2×; the assistant is the actual Stride consumer, so name 3–4×. (c) TOCTOU: both runs read the same `$input` values; the first sees raw (M1), the second sanitised; the handler runs on the second, so the authoritative decision is co-located with the data the handler sees. (d) The memoize is a `WeakMap` keyed on the request object (:1418-1432); the two synthetic requests are distinct objects and die with the call — no cross-call poisoning. (e) Non-bool results: M2. (f) Exceptions: M5.

## Mitigations verified one by one

| | Verdict | Where, what I checked |
|---|---|---|
| T1 permission first, dispatch second | holds | `abilityCallbacks()` :353-365: permission = `$record['permission']` (the resolved value stored at :1058, before `guard()` at :1050); execute = one `rest_do_request()` :357 returning `get_data()` or `as_error()` :359. WordPress side: `execute()` :824-840 denies before `do_execute()` :854. Inner `dispatch()` runs `has_valid_params` → `sanitize_params` → `permission_callback` (= `guard()` :1297) → handler (`class-wp-rest-server.php:1063-1120, 1238-1318`). `testAnUnauthorizedExecutionIsRefusedBeforeTheHandlerRuns` asserts no dispatch, no handler. The second half is honestly declared un-unit-tested (threat model :34-38). |
| T2 discovery charges nothing | holds, over-stated | :361 never touches `guard()`; `testThePermissionCallbackChargesNothing` with its guard control. The row's "any read user listing abilities runs the permission_callback" over-states WordPress: listing (`wp_get_abilities`, the list controller, the adapter's discover, `tools/list`) calls no permission at all; only `check_permissions`/run do. Harmless. |
| T3 no anonymous write | holds | :987-995 before :1024; Q3 above. |
| T4 one door | holds | `bin/guard.sh:276-284` — the grep excludes `api/Rest.php`, `vendor/`, `tests/`, comment lines; `--include=*.php` so `.bak` files are not scanned; reproduced 0 findings. INV-11 text at `ARCHITECTURE-INVARIANTS.md:40-47`. Stride's own script dropped the `AbilityRegistrar` exclusion (`scripts/check-invariants.sh`, invariants :26). |
| T5 path smuggling | holds | :380-384; Q1 above. |
| T6 after-init notice | holds | :1083-1087 non-fatal, route registers (`$bridgeUp` :1025 is false when timing is `after`, so a null block never drops the route); `abilitiesTiming()` :833-840; `mountAbilities()` late refusal :185-197 logs (default `log: true`); `abilities:unavailable` :1078 passes `log: false` and `refuse()` :1502 returns before `ntdst_log` — `_doing_it_wrong` only, deduped per declaration (:1474-1494). Tests `testADeclarationAfterTheHookIsNoticedNotLost`, `testWithoutTheBridgeMountedTheOptionIsInertAndNoticed`, `testMountingAfterTheAbilitiesHookRefusesLoudly`. |
| T7 lying annotation | deferred, honest | `readonly` from the verb :1062; annotations :286-289. Stride: `mark-attendance` `destructive: true` (`EditionAdminController.php:114`) is truthful — the handler deletes the row on `unmarked`/`''` (:966-972); `enroll-user` `destructive: false, idempotent: true` truthful (repeat → `already_enrolled` skipped); the three GET abilities' handlers only read (`searchUsers` :2110-2126 checked). I did not run `wp-abilities-verify`; I read the handlers. |
| T8 walk side effect | accepted | :217, :234 — `rest_get_server()` is idempotent after the first call; the only cost is `rest_api_init` on a request that would otherwise not fire it. |

## Checklist items (SEC-nn) and OWASP sweep of the changed files

- SEC-01 holds: `defined('ABSPATH') || exit` in `api/Rest.php:36`, `tests/Support/wp-rest-doubles.php:11`, both new test files.
- SEC-16/17/18: every route still goes through `register_rest_route()` with a `permission_callback` (:1047-1051); `__return_true` only via `->public()` on a read verb (not a finding per SEC-17's rule); capability strings resolve to `current_user_can()` (:938). The `ability` key never reaches WordPress (`array_diff_key(... OWN)` :1047, pinned by `testAValidDeclarationRegistersTheRouteWithItsGuard`).
- SEC-19: the inner dispatch validates and sanitises typed args; the first permission run does not (M1).
- SEC-20: the run controller and the adapter's HTTP transport are themselves REST routes served through `serve_request()`, so cookie clients still owe `X-WP-Nonce`; STDIO is the CLI user (`wp mcp-adapter serve --user=`).
- SEC-02..07, SEC-21..24, SEC-25..27, SEC-28..31: no `eval`/`exec`/`unserialize`/includes, no superglobals, no SQL, no output, no uploads in the changed files.
- MIG-nn: not applicable — a header + branch-alias bump, `PackageBootIntegrityTest` pins the header.
- OWASP A01 (access control): floor preserved twice. A03 (injection): path encoded, no SQL. A04 (insecure design): I1. A05 (misconfiguration): `abilities:unavailable` is debug-only by Decision 3 — a consumer on WP < 6.9 sees nothing in the api log; deliberate. A08 (integrity): `WP_Ability::__wakeup` throws. A09 (logging): refusals logged; executions are the consumer's via `wp_ability_invoked`. A10 (SSRF): no outbound fetch.
- netdust layer: the Stride proof REDUCES raw `register_rest_route()` 23 → 20 (`scripts/check-invariants.sh:116-117`). The raw `/confirm` route in `ntdst-assistant/src/ChatController.php:41` is pre-existing and outside the range.

## Remediation roadmap (all documentation; no code change required to merge)

1. I1 — add the Asset sentence, the T9 row and the Deferrals line to both `specs/abilities-bridge/plan.md` `## Threat model` and `docs/threat-models/2026-10-abilities-bridge.md`; on the Stride branch, one sentence beside `search-users` (`AdminAPIController.php:406-408`) naming the PII it relays.
2. M1, M5, M6 — three Deferrals sentences (raw params; exception messages via the run controller; `rest_authentication_errors` not re-run).
3. M3 — one line under T7; the `execute-once` flow is the proof per write ability.
4. M2, M4 — optional: normalise the permission result to `bool|WP_Error` in `abilityCallbacks()`; refuse or document multi-verb routes and the trailing-slash case.

## Declined to judge

- Whether unconditional `mcp.public` (Decision 12) is the right product decision — I only hold that the threat model must state its consequence; the human decides the posture.
- Prompt injection through `label` / `description` (R7) and the LLM-side handling of relayed data — the consumer's.
- The MCP Adapter 0.7.1's own transport security and the application-password policy (baseline C1) — the adapter's and baseline's.
- WordPress upstream behaviours I relied on but did not design around: output validated after execution; `has_valid_params()` running only explicit `validate_callback`s while `sanitize_params()` does the schema work; exception messages returned by the run controller.
- The `wp-abilities-verify` runtime report on Stride — not run by me; the five handlers were read instead.
- The ntdst-assistant plugin as a whole (its `/confirm` raw route, HMAC flow) — outside the range; I read only enough of `AbilityBridge.php` to confirm the write-confirmation gate keys on `annotations.readonly` (:94-105), which the bridge writes, and that `describe_input` is optional (:214-217).


---

# Code review — ntdst-core `feature/abilities-bridge` (5.5.0), `9a593c5..8d89c53` — superpowers' code reviewer

Checkout: `/tmp/claude-1000/-home-ntdst-Projects-netdust-plugins/f07572ac-69e7-49a9-aa74-73907cf6b5c6/scratchpad/review-8d89c53/` (all `file:line` below are in it unless a path is absolute). Re-run at head by the caller and confirmed by me: `OK (1277 tests, 4011 assertions)`, `bin/guard.sh` rc 0, `zero-readers: 0 findings`, INV-11's grep empty, INV-5's grep 21 hits (the Status line's count holds). Nine commits of code, two of plan; read-only throughout.

## What was implemented

A route declared through `ntdst_rest()` with an `ability` option is recorded (`registerOne()` `api/Rest.php:1022-1031`, `:1053-1064`), validated through `refuse()` (`ability()` `:1075-1157`), and registered as a WordPress ability by two walks mounted once at boot (`mountAbilities()` `:180-207`, `walkCategories()` `:215-225`, `walkAbilities()` `:232-243`, reader `ntdst-core.php:66`). Its `execute_callback` is one `rest_do_request()` of a synthetic request; its `permission_callback` is the route's resolved, unguarded permission (`abilityCallbacks()` `:353-365`, `abilityRequest()` `:375-396`). INV-11 and its guard line, docs, threat model, one shared REST doubles file, two test files. Proof on Stride `chore/abilities-bridge` (five abilities moved, registrars deleted).

## Strengths

- **The shape is the plan's, nothing more** — one option in `OWN` (`:42`), two statics (`:160`, `:163`), one public static with its one reader, one defaulted parameter on `refuse()` (`:1472`, `:1502-1504`). Every refusal goes through the file's one refusal home, including the late mount (`:187-194`, Ruling 10) and the walk-time schema failure (`:262-269`).
- **Discovery charges nothing, provably.** `permission_callback` closes over `$record['permission']`, never `guard()` (`:361-363`); `tests/Unit/NtdstRestAbilityExecuteTest.php:250-263` asserts an empty transient store AND runs the route's real guard on the same verb as a control that it does charge — the test would fail if the closure were the guard.
- **The closures are called, not inspected.** All eight dispatch tests invoke `$execute`/`$permission` and read the `WP_REST_Request` handed to the `rest_do_request` stub (`:87-90`): method, route, body vs query, the `rawurlencode`d `12%2F..%2Fx` path with `id` absent from the query (`:342-353`). The walk test pins the exact registered array minus the two closures and asserts both are `Closure` (`tests/Unit/NtdstRestAbilityTest.php:573-631`), the edge-8 split the plan asked for.
- **Refuse rather than guess, completely.** Sixteen provider cases over the eleven ids (`NtdstRestAbilityTest.php:303-336`, `:341-356`); the inert path validates nothing (`'name' => 'Not/Valid'` registers its route, one `_doing_it_wrong` at `'5.5.0'`, no api-log line, de-duplicated — `:444-462`), which is Decision 3 to the letter. `->public()` write never reaches the block (`:500-517`).
- **Timing is right against WordPress 7.1.** `invoke_callback()` passes `$input` only when an `input_schema` exists (`wp71-abilities/class-wp-ability.php:581-584`) — the closures' `$input = null` default and `(array) $input` handle both; `execute()` returns a `WP_Error` from `execute_callback` before `validate_output()` (`:854-862`), so `as_error()` keeps the route's status without hitting `ability_invalid_output`. The walk's `rest_get_server()`-first order is pinned by call sequence (`NtdstRestAbilityTest.php:558-571`) and a declaration flushed inside the hook is recorded (`:534-546`).
- **Two RED→GREEN tightenings landed mid-plan** (`d4829af` a present `output_schema` must be non-empty; `8697dbf` an `args` entry refines a path parameter, never empties it — `NtdstRestAbilityTest.php:658-678`), each with its test.
- **The doubles consolidation** removed four guarded copies for one file the bootstrap requires (`tests/Support/wp-rest-doubles.php`), net −87 lines, two subclasses still compiling against the `string|array` constructor.
- **Docs are complete and honest**: README `### 5.5.0` names every refusal and notice, the two-request cost, and that `schema` describes the whole return; the threat model (38 lines) says which half of T1 is NOT unit-tested; INV-11's Status line names a commit and the INV-5 list gained `Rest::$abilities`.
- **The Stride proof reads naturally.** Each move is `args` + `schema` + `ability` on the existing declaration line (`review-stride-proof.diff:200-215`, `:226-241`, `:252-269`, `:416-431`, `:2300-2317`); the registrars (−1,800 lines) are replaced by ~190 lines, most of it response schema. `mark-attendance` admits the `''` cohort.js sends and says `destructive: true` honestly (`:2307`, `:2314`). Stride's own invariants doc and `check-invariants.sh` baseline moved with it.

## Issues

### Critical (Must Fix)

None.

### Important (Should Fix)

**I1 — `abilityInputSchema()` keeps a table WordPress already keeps (INV-5), and it is the cause of C1.**
- `api/Rest.php:315` — `$keys = array_flip(['type', 'description', 'enum', 'default', 'items', 'properties', 'format', 'minimum', 'maximum', 'minLength', 'maxLength'])`.
- WordPress has the word: `rest_get_allowed_schema_keywords()` (`/home/ntdst/Sites/worktrees/stride-abilities-bridge/web/wp/wp-includes/rest-api.php:2169-2197`, since 5.6; 25 keywords including `maxItems`, `minItems`, `pattern`, `exclusiveMinimum`, `uniqueItems`, `additionalProperties`, `anyOf`, `oneOf`). This class's own header cites INV-5 (`:109`), and ARCHITECTURE-INVARIANTS.md's governing rule is "where WordPress has a word, core uses it".
- Effect: the named consumer's `user_ids` `maxItems: 500` (`review-stride-proof.diff:421`) never reaches the ability's `input_schema`, so the LLM sees no cap; the route still answers 400 inside the dispatch (fail-closed), so this is fidelity, not security.
- Fix: `$keys = array_flip(rest_get_allowed_schema_keywords());` — one line; the walk only runs on 6.9+, so the function exists. The two test files stub it in `setUp()` (`Functions\when('rest_get_allowed_schema_keywords')->justReturn([...])`), and `testTheRegisteredArgsAreExactForAReadAndAWrite` gains one `maxItems` on an arg. README's sentence listing the eleven keys becomes "the JSON Schema keywords WordPress allows (`rest_get_allowed_schema_keywords()`)". C4's "describe_input hook" is then unnecessary: the args ARE the description.

**I2 — The synthetic request carries no URL params, so a per-object permission callable kills its own ability silently.**
- `api/Rest.php:380-393` — path values are written into the route string and `unset` from `$input` (`:381-382`); the remainder goes to query or body; `set_url_params()` is never called. Inside the dispatch WordPress's `match_request_to_handler()` sets them from the regex match, so the route's guard sees `id`; the ability's `permission_callback` does not.
- A consumer writing the canonical WordPress per-object check — `'permission' => fn($r) => current_user_can('edit_post', (int) $r['id'])` on `GET /items/(?P<id>\d+)` — gets `ability_invalid_permissions` for every caller, admins included (`get_post(null)` → `do_not_allow` outside an admin screen), with no notice and no log line; the same declaration passes over HTTP. Fail-closed, so no security effect — a dead ability for a legitimate declaration (INV-3 makes a callable first-class). The README's "runs on two request objects" sentence does not say the first one is missing the path.
- The plan's Interfaces (`specs/abilities-bridge/plan.md:313`) specified query/body only, so this is an improvement the plan did not ask for; it is three lines: collect `$values[$match[1]] = (string) ($input[$match[1]] ?? '')` in the callback, `$request->set_url_params($values)` after construction, and one assertion in `testAPathParameterIsEncodedNotInterpolated` (the double needs `get_url_params()`, which WordPress has).

### Minor (Nice to Have)

**M1 — INV-11's grep is the bare word; drift check #16 is the call shape (C2).** `bin/guard.sh:278`, `ARCHITECTURE-INVARIANTS.md:677` and spec R5 (`/home/ntdst/Projects/netdust-plugins/specs/wp-ai-base/spec.md:137`) grep `"wp_register_ability"`; the drift reviewer's #16 greps `wp_register_ability(_category)?\(|AbilityRegistrar` (`/home/ntdst/Projects/netdust-plugins/plugins/netdust-wp/agents/ntdst-drift-reviewer.md:623`). Run verbatim on a consumer (Part 1 of the drift reviewer), INV-11 hits `/home/ntdst/Sites/worktrees/stride-abilities-bridge/web/app/plugins/ntdst-assistant/ntdst-assistant.php:26` — a `function_exists('wp_register_ability')` version guard, which is not a door. Align the three copies on #16's regex; `api/Rest.php:182`'s own `function_exists` is already excluded by file. The spec line is Stefan's to change.

**M2 — `PATH_PARAMETER_TYPE` is a fifth symbol the simplicity rule did not list.** `api/Rest.php:93-98`. It exists so `['type' => 'string']` does not sit bare on a shipped line, which `RETIRED_TYPES` in `bin/guard.sh:252` would fire on (`'string'` is a retired field-type alias, `api/FieldTypes.php:65`; the guard's own comment at `:247-248` says a bare `'type' => 'string'` is indistinguishable from a retired declaration). The docblock states the why, which is the legitimate "workaround" comment; the alternative — an exemption row in the guard plus `PackageBootIntegrityTest::REST_ARG_SCHEMA_LINE` — costs more. Accept, but it is a deviation from "nothing else" and the review should say so. It stays after I1 (the literal at `:313` remains).

**M3 — `ltrim` where WordPress `trim`s.** `api/Rest.php:387` builds `'/' . trim($ns, '/') . '/' . ltrim($route, '/')`; `register_rest_route()` stores `trim($route, '/')` and `rest_api_loaded()` `untrailingslashit`s the HTTP path. A route declared `/foo/` dispatches to `/ns/foo/` and gets `rest_no_route` through this door only. One word.

**M4 — Only one limit of the path builder is named.** `:379` names nested parentheses. Regex outside named groups (`/?$`, `\.json`, a bare `[a-z]+`) stays in the built path (`:380-385`) and matches nothing; and a value that needs encoding reaches the handler percent-encoded, where the HTTP door's path is decoded once before matching. Name both in README's path paragraph, or — the file's own answer — refuse at declaration (`ability:route`) when the route has regex metacharacters outside its groups.

**M5 — Multi-verb and custom-verb routes dispatch on `verbs()[0]` (`:377`) while `readonly` reads all verbs (`:1062`).** `'GET, POST'` is annotated as a write and dispatched as a read; a custom write verb (`PURGE`) gets body params that WordPress's `get_parameter_order()` reads only for POST/PUT/PATCH/DELETE, so its required `args` are "missing" → 400. Both fail closed. A twelfth refusal, `ability:methods` (one verb, one WordPress reads params for), is the shape consistent with R3.

**M6 — Permission-result parity.** WordPress's `check_permissions()` coerces anything not `bool`/`WP_Error` to `false` (`wp71-abilities/class-wp-ability.php:650-653`); the route admits any non-`false`/non-`null` value. A callable returning `1` opens the route and kills the ability. A `(bool)` cast for non-`WP_Error` results at `:362` matches the guard's own truthiness rule (`:1309`). (The coder's "truthy-but-not-true fails closed" item — it is WordPress's rule, one cast makes the two doors agree.)

**M7 — Typeless-only args yield `'properties' => []` (`:339`)** — a JSON array where JSON Schema wants an object; WordPress's validator accepts it, a strict MCP client may not. `(object) []`, or omit `properties` when empty.

**M8 — `ability()` is 83 lines (`:1075-1157`).** Eleven flat early returns, plan-sanctioned ("no nested validation helper"), readable but past one screen. Leave; if M5 adds a twelfth, a `[predicate, id, message]` table is the shape.

**M9 — No test for a missing named-group value** (`''` → the server's regex refuses). One more `$execute([])` assertion in `testAPathParameterIsEncodedNotInterpolated`.

**M10 — Threat model T8 says the side effect "lives in `mountAbilities()`"**; the `rest_get_server()` calls live in the two walks (`:217`, `:234`). Wording (`docs/threat-models/2026-10-abilities-bridge.md:22`).

## Recommendations

- **Harness line to correct** (`/home/ntdst/Projects/netdust-plugins/plugins/netdust-wp/skills/ntdst-framework/SKILL.md:114-115`): "the permission and the limiter run once" and "Core derives nothing from the verb" both over-state what shipped. The resolved permission runs twice per execution (README names it; plan review N2 settled it) and `readonly` IS derived from the verb with `destructive`/`idempotent` ignored on a read (Ruling 8). The code matches `:114`'s mechanics and `:116` exactly; two clauses in the harness repo need the code's words.
- **The consumer's one real cost is Decision 2's.** Stride paid ~127 lines of response schema in `AdminAPIController` (`review-stride-proof.diff:276-402`, a `pagedSchema` helper plus three), ~40 in `BulkRegistrationHandler` and ~22 in `EditionAdminController`, and `editionsSchema()` unions two views' keys because WordPress validates the whole return. Consciously taken; add one README line that `['type' => 'object']` is a valid, permissive `output_schema`, so a consumer knows the price is optional.
- After I1 the "eleven keys" sentence in README collapses to one clause.
- Drop C4 (a `describe_input` hook): with I1 the route's `args` carry every keyword an assistant can read.

## Declined to judge

- The task reports' evidence (RED runs for row 4's guard and the tests, the Stride flows `discover` / `execute-once` / `denied-write`, the `wp-abilities-verify` and drift-reviewer quotes) — not in the checkout (`specs/abilities-bridge/` holds `plan.md` and `plan-review.md` only); the reviewer session holds them.
- `guard()` returning a falsy non-`null`/non-`false` result untouched (`:1309-1311`) while WordPress admits it — pre-existing, outside the range.
- Stride's deleted `ReadAbilityEditionStatsTest` and the `getEditionStats` completion-rate logic that went with the registrar — `get-stats` is finding F3 by R6; the branch is never merged.
- Stride's `ARCHITECTURE-INVARIANTS.md` and `check-invariants.sh` edits (`RAW_BASELINE` 23→20) — Stride's own, on the proof branch.
- `tests/Unit/NtdstRestTest.php:56-63`'s guarded `WP_Error` double, now unreachable behind the bootstrap's — the plan said keep every `WP_Error` double.
- A throwing `schema` callable at walk time (coder's deferral) — a consumer bug surfacing in WordPress's hook; core refuses rather than catches elsewhere, and wrapping would hide it.
- `abilityArgs()`'s `_doing_it_wrong` naming `NTDST_Rest::ability` (`:268`) — the plan asked for `'ability'`.
- WordPress's own order inside `dispatch()` (args validation before the permission callback, so a 400 never reaches the limiter) — pre-existing for every route.

## Assessment

**Ready to merge: With fixes.**

**Reasoning:** The implementation matches the plan's Interfaces, Review Focus and test map row for row, the spec's R1–R7 and the harness's `:114`/`:116`, with every closure exercised and the gate, guard and both invariant greps green at head. I1 (an INV-5 table where WordPress has `rest_get_allowed_schema_keywords()`, one line) and I2 (`set_url_params()` on the synthetic request, three lines) are the fix pass; nothing is Critical and nothing widens reach.


---

# Invariant audit — ntdst-core `feature/abilities-bridge` (5.5.0), `9a593c5` → `8d89c53` — invariant-auditor

Checkout: `/tmp/claude-1000/-home-ntdst-Projects-netdust-plugins/f07572ac-69e7-49a9-aa74-73907cf6b5c6/scratchpad/review-8d89c53/` (all `file:line` below are relative to it unless prefixed). Every mechanical check was run verbatim from that root with this machine's ugrep 7.8.4 (no `.ugreprc`), then broadened. Read-only; nothing changed.

## Critical
None. No convergence point is bypassed, no second door exists, every invariant's check returns what its doc expects except INV-8 (B) (below, Drift — a record error, not a behaviour one).

## Drift (record disagrees with code — introduced by this branch)

**D1 — INV-8 (B) returns 2, the doc says 1.** New hit `api/Rest.php:98` `private const PATH_PARAMETER_TYPE = 'string';` (base `9a593c5`: zero (B) hits in `api/Rest.php`). Unrecorded: INV-8 Status still reads "(B) 1" and `## Deliberate exceptions` → "(B)'s hits, now one". The constant's own docblock (`:93-97`) says it exists "only because the retired-field-type sweep reads that literal pair as a field declaration" — i.e. `bin/guard.sh:252` `RETIRED_TYPES` would match `['type' => 'string']` at `:313` and the `REST_ARG_SCHEMA_LINE` content discriminator (`:253`) cannot exempt that line (no `sanitize_callback|validate_callback|items|enum` on it). So one check was spelled around and another gained a hit, and neither is written down. INV-8's own words on the `MAX_REQUESTED_TYPES` precedent: "It is named below rather than renamed around — a check that only stays quiet because the constant was spelled to dodge it is a check that has stopped asking." Proposed entry under `### INV-8 — every hit the field-type check returns`, group "(B)'s hits": *"**`NTDST_Rest::PATH_PARAMETER_TYPE`** (`api/Rest.php`) — (B)'s second hit since 5.5.0. A `private const … = 'string'`: the JSON-Schema type of a URL capture in an ability's `input_schema` — not a field type and not a list. It is a constant because the literal `['type' => 'string']` on its one use site carries none of the REST-only words the retired-type pin exempts by content, so the pin would read it as a retired declaration. (B) hunts a second LIST; a one-word scalar is the shape to recognise. (B) is TWO at 5.5.0: this and `admin/RelationField.php:46`."* Plus INV-8 Status: "Re-run verbatim at 5.5.0: (A) 55, same per-file shape; (B) 2."

**D2 — `timing()`'s docblock is now false.** `api/Rest.php:810-812`: "Where this call stands relative to rest_api_init — the ONE place the question is asked." `abilitiesTiming()` at `:833-840` is a second place (see R1).

**D3 — INV-7 Status pins stale as of this branch.** Doc: `$permission($request)` at `api/Rest.php:928` precedes `attempt` at `:949`. Head: `:1304` and `:1325` (base `9a593c5` still had `:949`, so this branch moved them and re-pinned only INV-5 and INV-11). Order still holds.

**Pre-existing line-pin drift (identical at base — NOT this branch's; reported so it is not charged here):** INV-1 (2) doc `:206,:223,:266,:1932,:1955,:2071,:2075,:2127` vs actual `:215,:232,:275,:2162,:2185,:2301,:2305,:2357` (same ten shapes); INV-4 `:1864` → `:1871`; INV-7 MetaboxGenerator `:2064,:2106,:2116` → `:2071,:2113,:2123`.

## Borderline

**B1 — The INV-11 line is not portable to a consumer root on this machine (finding about the CHECK).** Measured on `/home/ntdst/Sites/worktrees/stride-abilities-bridge`: ugrep honours `.gitignore` on `-r` by default here, so from the Stride root the verbatim line silently skips `web/app/mu-plugins/ntdst-core/` (`.gitignore:13`) and `web/app/plugins/*` (`.gitignore:2`). With `--no-ignore-files` the SAME line hits `web/app/mu-plugins/ntdst-core/api/Rest.php:220,:240` (the vendored convergence point — the `^(\./)?api/Rest\.php` anchor does not match a nested path), `web/app/plugins/mcp-adapter/includes/{Abilities/DiscoverAbilitiesAbility.php:34, Abilities/GetAbilityInfoAbility.php:34, Abilities/ExecuteAbilityAbility.php:36, Core/McpAdapter.php:387}` and `web/wp/wp-includes/abilities-api*`. GNU grep would show all of those. The doc header records the `./`-prefix difference and not this one. Core side is unaffected: core's `.gitignore` is `vendor/` + caches, and INV-2 / INV-11 are identical with `--no-ignore-files` (both EMPTY).

**B2 — One rule spelled three times.** `'/^[a-z0-9-]+$/'` at `api/Rest.php:1107` (slug), `:1113` (name), `:1144` (category). WordPress's registry spells the compound once (`class-wp-abilities-registry.php:86` `'/^[a-z0-9-]+\/[a-z0-9-]+$/'`). A `private const` would be one home. Nit.

**B3 — A malformed `ability` block is accepted silently when the bridge is down.** `ability()` returns at `:1077-1080` (`abilities:unavailable`) or `:1083-1086` (`abilities:after-init`) BEFORE any shape/key/name validation, so on WP < 6.9 an unknown key or a bad name inside the block registers the route with one debug-only notice. The "`NTDST_Rest` refuses a route with a typo'd option" deliberate exception does not reach inside the block on that path. README says the route registers; it does not say the block goes unchecked. Unrecorded intent — needs a ruling or a sentence.

**B4 — `self::$abilities` has a third reader.** Beyond the two walks (`:219`, `:236`) the duplicate check at `:1150` reads it. INV-5's clause names only the buffer role; WordPress refuses a duplicate too, but at walk time after the route is already registered — core's early fatal is the same shape as the typo'd-option exception. One more sentence in INV-5's clause would close it.

**B5 — INV-11 has no suite-side mirror.** INV-2's check runs twice: `bin/guard.sh` is not where it lives, but `tests/Unit/CoreShapeCluster3FeatureTest.php:556-574` runs it as an assertion over `SHIPPED_DIRS`. INV-11's only home is `bin/guard.sh:276-285`. Not a rule (CODE-MAP's two-homes rule is for retired symbols), but the precedent exists.

**B6 — INV-11 calibration sentence missing.** The comment filter carries nothing (EMPTY with and without it); siblings say so (INV-3). Status pinned at `409bb34`, three docs-only commits behind head — re-run at `8d89c53`: EMPTY.

## INV table (verbatim at `8d89c53`)

| INV | Verdict | Expected (doc) | Actual |
|-|-|-|-|
| INV-1 | HONORED — NOT TOUCHED | (1) EMPTY; (2) TEN hits, one reader; (3) EMPTY | (1) EMPTY rc=1; (2) TEN: `api/Data.php:122` (reader), `:128,:2162` (docblocks), `:232,:275,:2185,:2305` (warning strings), `:215,:2357` (args), `:2301` (post-type read) — lines drifted pre-base, shapes identical; (3) EMPTY |
| INV-2 | HONORED | EMPTY | EMPTY, with and without the comment filter, and with `--no-ignore-files`. `register_rest_route(` one caller `api/Rest.php:1047` |
| INV-3 | HONORED | EMPTY | EMPTY with and without the comment filter. Verb rule `:987-995` precedes the ability block read at `:1024` |
| INV-4 | HONORED — NOT TOUCHED | THREE in MetaboxGenerator; `fetch(` EMPTY | THREE: `:342,:712` (`wp_nonce_field`), `:1871` (`wp_verify_nonce`; doc `:1864` pre-base drift); `fetch(` EMPTY |
| INV-5 | HONORED | TWENTY-ONE, every one named | 21: the twenty + `api/Rest.php:160 $abilities`. `$abilitiesMounted` (`:163`, bool) not a hit; `PATH_PARAMETER_TYPE` not a hit (pattern wants `[`/`null`) |
| INV-6 | HONORED — NOT TOUCHED | FIVE + ONE/ONE at the 5.4.0 pins | `core/TemplateLoader.php:147,150,191,205,216`; `api/Response.php:159`; `core/TemplateLoader.php:32` — identical |
| INV-7 | HONORED | NINE; RateLimiter the only counter; `$permission($request)` before `attempt` | NINE: `support/RateLimiter.php:161,216,223,226,244,251`, `admin/MetaboxGenerator.php:2071,2113,2123`. Guard order `api/Rest.php:1304` → `:1325` (doc pins `:928/:949` stale — D3) |
| INV-8 | (A) HONORED, (B) DRIFT | (A) 55; (B) 1 | (A) **55**: MetaboxGenerator 28, Data 15, RelationField 5, Rest 3 (`:709,:936,:1049`), Pages 2, LogLevel 1, Response 1 — identical shape; (B) **2**: `api/Rest.php:98` (NEW, D1) + `admin/RelationField.php:46`. `N` regenerated from the registry matches the doc's literal |
| INV-9 | HONORED | stdout 0, exit 0, all roots present | 0 lines, rc 0, "719 files, 208295 lines searched", 22 advisory on stderr. `mountAbilities()` absent from the advisory list — its reader `ntdst-core.php:66` was found |
| INV-10 | HONORED | (1) 0/0 (2) EMPTY (3) one file (4) `:501` behind `:408` | `ntdst-core.php:0`, `core/Bootstrap.php:0`; EMPTY; `core/Bootstrap.php`; `core/Bootstrap.php:501` behind `class_exists` `:408`, beside `core/Container.php` decl/docblocks and `core/Theme.php:58` |
| INV-11 | HONORED | EMPTY | EMPTY — verbatim; without comment filter; call shape `wp_register_ability(\|wp_register_ability_category(`; with `--no-ignore-files`. All other mentions: `api/Rest.php:182` (`function_exists`, inside the excluded file) and Brain Monkey aliases in `tests/Unit/NtdstRestAbilityTest.php:104,110`, `tests/Unit/NtdstRestAbilityExecuteTest.php:111,116` |

Broadening added: nothing in core. Broadened INV-11 (`WP_Ability|wp_get_ability|abilities()->register|WP_Abilities_Registry|wp_abilities_api_*`) hits only `api/Rest.php` (`:190,:199,:202,:230,:832,:835,:839,:1084`) — the hook names, no registry call.

## Q1 — does every changed path route THROUGH the named point?

- **`wp_register_ability_category()` / `wp_register_ability()`**: exactly `api/Rest.php:220` (`walkCategories()`) and `:240` (`walkAbilities()`). HONORED.
- **The record is written once**: `self::$abilities[...] =` only at `:1054`, inside `registerOne()`, AFTER `register_rest_route()` at `:1047` — a refused route never records (`-F 'self::$abilities'` over `api core admin support services ntdst-core.php`: writers `:1054`; readers `:219,:236,:1150`). HONORED.
- **The ability's permission is the resolved one, never `guard()`**: record `permission` = `$permission` from `:968` = `permission()`'s return (`:919-946`: `'__return_true'` / `'is_user_logged_in'` / `static fn => current_user_can($cap)` / the consumer's callable). `abilityCallbacks():361-363` calls `($record['permission'])(...)`. `guard()` is reached only at `:1050` for the ROUTE's `permission_callback`. INV-7: the charge is inside `rest_do_request()` (`:357`) → the route's guard closure `:1303-1334` → `attempt` `:1325` after `$permission($request)` `:1304`; `$matched` (`:1316-1318`) holds because `abilityRequest()` uses `verbs()[0]` of the same methods (`:377`). Charged once; discovery charges nothing. HONORED.
- **Late-mount refusal has one home**: `mountAbilities():186-194` → `self::forNamespace('ntdst')->refuse('(abilities)', '-', 'abilities:mounted-late …', false, '5.5.0', 'mountAbilities')`. `_doing_it_wrong` occurs once in the file (`:1496`, inside `refuse()`); the only other `ntdst_log` is the pre-existing CORS-throw at `:645`. The plan review's B1 asked for a raw `_doing_it_wrong` + `ntdst_log` pair; Ruling 10 overrode it; the code follows Ruling 10. HONORED — one refusal home.
- **INV-3 / T3**: `->public()` write refused at `:987-995` before the block is read at `:1024`. HONORED.
- **INV-9**: one new public static, `mountAbilities()` `:180`; reader `ntdst-core.php:66`. HONORED.
- **INV-10**: `ntdst-core.php:66` is a static call on a class `require_once`'d at `:65`; nothing derived, nothing scanned. It is the first non-`require_once` top-level statement in that file — precedent set, not broken. HONORED.
- **INV-5**: `$abilities` named with its reason (WP's `wp_register_ability()` refuses outside the hook — verified at Stride's `web/wp/wp-includes/abilities-api.php:290-303`, the doc's citation is correct). HONORED, see B4.

## Q2 — Reinventions

**R1 — REINVENTED: `abilitiesTiming()` (`api/Rest.php:833-840`) duplicates `timing()` (`:823-830`).** Same three states, same `function_exists` guards, same `doing_action`/`did_action` pair; only the hook literal differs. The plan prescribed it verbatim (`specs/abilities-bridge/plan.md:189` "the same three states and the same function_exists guards as timing()") and the plan review did not flag it — a planned duplicate is still two homes, and `timing()`'s docblock now lies (D2). Survivor: `timing(string $hook = 'rest_api_init')`; the two existing callers stay as they are, the three new ones (`:187`, `:1025`, `:1084`) become `self::timing('wp_abilities_api_init')`, `abilitiesTiming()` deleted. One line cheaper than recording an exception.

None found for the other three — searched over `api core admin support services ntdst-core.php`:
- second request builder: `new WP_REST_Request|rest_do_request|rest_ensure_request` → only `:357,:387`;
- second schema mapper: `array_intersect_key|'properties'|'minLength'|'required' =>` → `abilityInputSchema()` maps REST `args` → JSON-Schema object; `api/Data.php::schemaFor()` (`:270`) maps FIELD declarations → meta schema (different vocabulary, different direction); WordPress ships schema→args, not args→schema;
- second slug rule: `str_replace('/'|sanitize_title|sanitize_key` → only `:845`; Bootstrap's `basename(str_replace('\\','/',$class))` is class→short name; WordPress's `sanitize_key()` strips `/` rather than replacing it, so it is not the same word.
- `refuse()`'s new `$log` parameter is a reinforcement of the one refusal home, not a second one.
- Worth naming as the opposite: the branch REMOVED a reinvention — four guarded `WP_REST_Request` doubles (`NtdstRestTest`, `CoreShapeCluster3FeatureTest`, `RelationFieldSearchTest`, `RelationSearchRouteTest`) converged into `tests/Support/wp-rest-doubles.php`, required once by `tests/bootstrap.php:96`.

## INV-11 text against the doc's shape

Shape matches siblings: prose → **Convergence point** → **Bypass smell** → **Mechanical check** with expected output → **Status** with a sha. Convergence point names the record site, the mount, its reader and the two walks, and matches the code. The bare `wp_register_ability` is a deliberate superset of the call shape (it also catches a string-callable registration); the grep covers `_category` by prefix. Missing against siblings' habit: the calibration sentence that the comment filter carries nothing (B6), and any statement of which form a consumer runs. Bypass smell carries a consumer-specific token (`*AbilityRegistrar`); acceptable since drift check #16 keys on it.

## C2 — the bare word vs the call shape

**Premise correction:** drift check #16 does NOT reuse INV-11's line. In the marketplace source, `/home/ntdst/Projects/netdust-plugins/plugins/netdust-wp/agents/ntdst-drift-reviewer.md:623` reads `grep -rnE "wp_register_ability(_category)?\(|AbilityRegistrar" --include=*.php <scope> | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"` — already the call shape plus the registrar token. The false positive belongs to INV-11's line when lifted onto a consumer, not to #16.

**Measured on Stride (`/home/ntdst/Sites/worktrees/stride-abilities-bridge`, `926e9dad`):**
- INV-11 verbatim from the root → ONE hit: `web/app/plugins/ntdst-assistant/ntdst-assistant.php:26` `if (!function_exists('wp_register_ability')) {` — a WordPress 6.9 version guard, not a door.
- Call shape from the root → EMPTY.
- #16's own grep over `web/app` → `web/app/mu-plugins/ntdst-core/api/Rest.php:220,:240` (the vendored convergence point; "every hit outside `api/Rest.php` is drift" reads it out) and `mcp-adapter` x4 (WordPress's own MCP adapter plugin, not Stride's code). Scope choice decides those; neither is a Stride door. The Stride diff deletes both registrars (`ReadAbilityRegistrar.php`, `WriteAbilityRegistrar.php`), their `plugin-config.php` rows, and the `AbilityRegistrar` exclusion from Stride's INV-11 audit line.

**Verdict:** keep the bare word in core's `bin/guard.sh` — measured cost in core is zero either way (EMPTY with and without the comment filter, with and without `--no-ignore-files`), and the bare form is what catches `call_user_func('wp_register_ability', …)` / `array_map('wp_register_ability', …)`, which the call shape loses. For a consumer, do not switch forms; drop the one false-positive SHAPE by content, the way CODE-MAP says an exemption must be written (a discriminator the exempted shape cannot fake): append `| grep -vE "function_exists\(['\"]wp_register_ability"`. If the call shape is preferred for readability, record that it trades the string-callable form away. Either way the doc should say which form consumers run, that #16 is already the call shape, and (B1) that this machine's ugrep skips gitignored directories so a consumer run differs from GNU grep's.

## Doc drift — proposed entries

1. INV-8 `## Deliberate exceptions` "(B)'s hits" entry and Status line — text under D1.
2. INV-7 Status: re-pin `:1304` / `:1325` at 5.5.0 (D3).
3. `api/Rest.php:811-812` docblock — true again once R1 merges; otherwise it must say "one of two".
4. INV-11 Mechanical check, one sentence: *"The comment filter drops nothing today (EMPTY with and without it). On a consumer the bare word also matches a `function_exists('wp_register_ability')` version guard (Stride `ntdst-assistant.php:26`); drop that shape by content — `| grep -vE "function_exists\(['\"]wp_register_ability"` — rather than switching to the call shape, which loses a string-callable registration. Drift check #16 runs the call shape plus `AbilityRegistrar`."* Status: add `8d89c53`: EMPTY.
5. Doc header, beside the `./` note: *"ugrep also honours `.gitignore` on a recursive run; from a consumer root the checks skip composer-installed directories unless `--no-ignore-files` is given."* (B1)
6. INV-5's `Rest::$abilities` clause, one more sentence: it is also read by the duplicate check in `ability()`, which refuses at declaration what WordPress would refuse at walk time after the route had already registered (B4).
7. B3 needs either a ruling in the plan's ledger or a README sentence: on a bridge-down request the block is not validated.

## Open questions
1. R1: merge `abilitiesTiming()` into `timing($hook)` now (three callers, one line) or record it as an exception? The fix is cheaper than the record.
2. B3: should `ability()` validate the block before the two bridge-down returns, so "a typo'd option refuses" reaches inside the block on every WordPress version?
3. B5: is a suite-side mirror of INV-11's grep wanted beside INV-2's in `CoreShapeCluster3FeatureTest`?
4. B1: does `bin/zero-readers.sh`'s sweep over the consumer roots share the gitignore skip? Out of this diff's scope, but the same tool runs it (719 files reported; not verified against `--no-ignore-files`).

## STATUS
STATUS: DONE
FINDINGS: 0 bypassed / 1 reinvented / 3 doc-drift (this branch: D1 INV-8 (B) unrecorded hit, D2 `timing()` docblock, D3 INV-7 pins) — plus 3 pre-existing line-pin drifts not charged to this branch, and 1 finding about the check's portability on consumers (B1). Nothing merge-blocking.


---

# NTDST Drift Audit: ntdst-core `feature/abilities-bridge` (8d89c53) + Stride proof `chore/abilities-bridge` (926e9dad) — ntdst-drift-reviewer
Date: 2026-10-30
Core version: core header `Version: 5.5.0`, branch-alias `5.5.x-dev`; Stride pins `netdust/ntdst-core: dev-feature/abilities-bridge as 5.5.0` (vendored at 8d89c53 — its `ARCHITECTURE-INVARIANTS.md` is byte-identical to the reviewed checkout's)
Files scanned: core — Part 1 over the package root, Part 2 reads on the 5 changed PHP paths; Stride — Part 1 over `web/app/mu-plugins/stride-core` (243 PHP files), Part 2 reads on the 4 changed PHP files plus their JS clients
grep: ugrep 7.8.4 (both shells); every `-E` zero was sanity-checked against a known-matching line

## Short answers

**(a)** Check #16 is EMPTY on stride-core (`grep -rnE "wp_register_ability(_category)?\(|AbilityRegistrar" --include=*.php .` → 0, comment filter applied) and empty over all of `web/app` consumer code. INV-11's bare-word grep is empty at `<scope>`; re-aimed at `web/app` it hits exactly one line, `web/app/plugins/ntdst-assistant/ntdst-assistant.php:26: if (!function_exists('wp_register_ability'))` — a presence test in a regular plugin whose header already says `Requires at least: 6.9`, not a registration, and outside the plan's scope (Task 7 names that plugin read-only). It is a false positive of INV-11's bare-word spelling; check #16's `\(`-anchored spelling does not see it. Calibrate (see the last section) — do not re-spell INV-11, which is core's document; read the hit.

**(b)** Yes on all three. Every new refusal (the 11 in `ability()`, `abilities:schema` in `abilityArgs()`, `abilities:mounted-late` in `mountAbilities()`) goes through `refuse()` — 26 `refuse(` sites in the file, the only `_doing_it_wrong(` is inside `refuse()` at `api/Rest.php:1496`, and the late-mount one reports through `self::forNamespace('ntdst')->refuse()` as Ruling 10 says. Statics: `tests/Support/RestApiInitHarness.php:105-115 resetRestStatics()` resets every static with a default by reflection, so `$abilities = []` and `$abilitiesMounted = false` reset with no harness change. `OWN` carries `'ability'` (`:42`); `$known` inherits it and the `array_diff_key($options, array_flip(self::OWN))` strip keeps it from `register_rest_route()` (the Task 2 test asserts that). Second homes: ONE request builder (`new WP_REST_Request` only at `:387`, `abilityRequest()`); ONE slug rule (`str_replace('/', '-'` only at `:845`); TWO timing predicates — `timing()` `:823-830` and `abilitiesTiming()` `:833-840` have the same body with only the hook name different. The plan names it ("timing()'s three states, for wp_abilities_api_init"), so Borderline, not Drift.

**(c)** The five declarations use the door as SKILL.md:114-116 describes it: `name`/`label`/`description` on each, write verbs state `destructive`+`idempotent` (`mark-attendance` true/true — truthful, `''`/`unmarked` deletes; `enroll-user` false/true), reads omit them, every one has a `schema` callable, names match `^[a-z0-9-]+$`, full names `stride-v1/*`. Permissions are capability strings (`stride_view` ×3, `stride_manage` ×2), which is exactly what `canViewAdmin()`/`canManageAdmin()` were (`AdminAPIController.php:492-503`) and what Stride's own routing rule asks for. `args`: the three GETs' `args` blocks pre-existed on the raw routes (the diff only adds `description`), so `edities.js`, `gebruikers.js`, `gsearch.js` see no validation change. `/admin/attendance` gains NEW args: `cohort.js:325` sends `{session_id, user_id: Number(...), status: next}` with `''` to clear; `edition-admin.js:580` sends `nextStatus` cycling `unmarked/present/absent/excused` — all five values are in the enum, and the handler still coerces `''`→`unmarked` at `:933-936`. `/admin/bulk/enroll-in-edition` gains NEW args: `shell.js:810-819` sends `{edition_id: Number, user_ids: persons.map(p => p.user_id)}` — integer + array of integers. Every `schema` describes the RESPONSE as `get_data()` returns it:

| route | schema callable | matches `get_data()` |
|---|---|---|
| `GET /admin/editions` | `editionsSchema()` (`AdminAPIController.php:524`) | yes — `pagedSchema` envelope `items/total/page/perPage/totalPages` + `view` enum (`:874-879`, `:1185-1190`); item = `EditionAdminMapper::toItem()` (`id, course{id,title}, capacity, registeredCount, status, editUrl`) + list keys (`title, course.tags, startDate, endDate, dateLabel, venue, isToday, isPast`, `:858-868`) + agenda keys (`sessionId, sessionTitle, date, startTime, endTime`, `:1167-1180`); union declared, nothing `required`, so either view validates |
| `GET /admin/editions/{id}` | `editionSchema()` (`:566`) | yes — `:1435-1452`; `price`/`priceNonMember` are `(int)` (`:1396-1397`), `speakers` `?: null`, `sessions[]` id/title/date/startTime/endTime/type |
| `GET /admin/users/search` | `userSearchSchema()` (`:612`) | yes — `:2149-2167` |
| `POST /admin/attendance` | `attendanceSchema()` (`EditionAdminController.php:1002`) | yes — `getAttendanceTotals()` (`presentCount`, `totalCount`) + `attendanceId/sessionId/userId/status` (`:989-993`, `status` null on unmarked) |
| `POST /admin/bulk/enroll-in-edition` | `enrollReportSchema()` (`BulkRegistrationHandler.php:136`) | yes — `runOver()`'s report (`BulkRunner.php:364-370`); `code` is `['string','integer']` because `get_error_code()` may be either (`:330-336`) |

**(d)** Two candidates, at the end.

---

## Part 1 — core's invariants, on the CORE checkout (from `/tmp/claude-1000/-home-ntdst-Projects-netdust-plugins/f07572ac-69e7-49a9-aa74-73907cf6b5c6/scratchpad/review-8d89c53/ARCHITECTURE-INVARIANTS.md`, 11 `## INV-` headings, 11 `**Mechanical check:**` lines — one more than the ten the agent expects; INV-11 is the new one)

Run verbatim from the package root (this IS core's tree, so the doc's numbers are the target, not a calibration). `N` derived from `api/FieldTypes.php` by the sed pipeline: `array|bool|boolean|callback|checkbox|content|date|datetime|decimal|double|email|file|float|gallery|html|image|int|integer|json|longtext|media|number|person|post_relation|relation|repeater|select|signed_int|string|text|textarea|url|wysiwyg`.

```
INV-1 · expected: → empty (1) / → ten hits, and every one is declaresRest() itself… (2) / → empty (3) · actual: 0 hit(s) (1) / 10 hit(s) (2) / 0 hit(s) (3)
       cmd: (1) grep -rn "register_post_meta\|register_meta(" --include=*.php . | grep -vE "^(\./)?api/Data\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
       cmd: (2) grep -rn "show_in_rest" --include=*.php api/Data.php
       cmd: (3) grep -rn "public_fields\|public_shape\|publicRow" --include=*.php . | grep -vE "(^|/)vendor/|(^|/)tests/"
INV-2 · expected: → EMPTY · actual: 0 hit(s)
       cmd: grep -rn "register_rest_route(\|wp_ajax_\|ntdst/api_data\|ntdst_actions" --include=*.php --include=*.js . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
INV-3 · expected: → EMPTY · actual: 0 hit(s)
       cmd: grep -rn "__return_true\|=> 'public'\|->public()\|public_actions" --include=*.php . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
INV-4 · expected: → THREE hits, all in admin/MetaboxGenerator.php (1) / → EMPTY (2) · actual: 3 hit(s) (1) / 0 hit(s) (2)
       cmd: (1) grep -rn "wp_create_nonce\|wp_verify_nonce\|check_ajax_referer\|wp_nonce_field\|get_nonce\|HTTP_ORIGIN\|HTTP_REFERER" --include=*.php --include=*.js api core admin assets
            hits: admin/MetaboxGenerator.php:342, :712, :1871 (doc says :1864 — pre-existing line lag, file untouched by this branch)
       cmd: (2) grep -rn "fetch(" assets/js
INV-5 · expected: TWENTY-ONE hits — the twenty, plus Rest::$abilities · actual: 21 hit(s), every one named in the doc (Rest::$abilities at api/Rest.php:160)
       cmd: grep -rnE '(private|protected) +(static +\??array +\$|const +)[A-Za-z_]+ *= *(\[|null)' --include=*.php api core admin support services
INV-6 · expected: → FIVE hits, all core/TemplateLoader.php (1) / → ONE each (2) · actual: 5 hit(s) (1) / 1 + 1 (2): api/Response.php:159, core/TemplateLoader.php:32
       cmd: (1) grep -rn "is_404 = false\|redirect_canonical\|locate_template(\|extract(" --include=*.php api core admin support services ntdst-core.php
       cmd: (2) grep -rn "function addPath\|function redirect" --include=*.php api core admin support services ntdst-core.php
INV-7 · expected: → only support/RateLimiter.php counts attempts · actual: 9 hit(s), the doc's nine; guard order holds — $permission($request) at api/Rest.php:1304 precedes RateLimiter::attempt at :1325 (the doc's Status line still says :928/:949 — moved by this branch's ~250 inserted lines, not re-pinned)
       cmd: grep -rn "set_transient\|get_transient" --include=*.php api core admin services support
INV-8 · expected: (A) returns 55 lines and (B) returns 1 · actual: 55 hit(s) (A) / 2 hit(s) (B)
       cmd: (A) grep -rnE "(switch|\bmatch) *\(|case '($N)'|'($N)' *, *'|'($N)' *=>|=> *'($N)'|[!=]== *'($N)'"'|[!=]== *"('"$N"')"' --include=*.php . | grep -vE "^(\./)?api/FieldTypes\.php|(^|/)vendor/|(^|/)tests/|(^|/)specs/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
            per file: MetaboxGenerator 28, Data 15, RelationField 5, Rest 3 (:709, :936, :1049 — the doc's three), Pages 2, LogLevel 1, Response 1 — identical to the 5.4.0 pin
       cmd: (B) grep -rnE '(const|static +\??array +\$)[ A-Za-z_]*[Tt][Yy][Pp][Ee][Ss]?[A-Za-z_]* *=' --include=*.php . | grep -vE "^(\./)?api/FieldTypes\.php|(^|/)vendor/|(^|/)tests/|(^|/)specs/"
            hits: admin/RelationField.php:46 (named) and api/Rest.php:98 `private const PATH_PARAMETER_TYPE = 'string';` (NOT named — see finding)
INV-9 · expected: 0 · actual: not run by this audit — read-only principle (the script is an executable in the audited tree); the caller's reproduced gate reports `sweep 0 findings`. The one new public symbol has its reader: `ntdst-core.php:66 NTDST_Rest::mountAbilities();` (grep -rn -e "mountAbilities(" --include=*.php . minus vendor/tests)
       cmd: bash bin/zero-readers.sh | wc -l — not executed here
INV-10 · expected: (1) prints 0 for both files / (2) empty / (3) core/Bootstrap.php, one file / (4) one call that takes a class, core/Bootstrap.php:501 · actual: 0 + 0 (1) / 0 hit(s) (2) / core/Bootstrap.php (3) / 7 hit(s) (4): Bootstrap.php:501 gated by class_exists, Container.php docblocks + :296 declaration, Theme.php:58
       cmd: (1) grep -c "glob(\|file_get_contents(\|preg_match('/^\\\\s*namespace\|spl_autoload_register" core/Bootstrap.php ntdst-core.php
       cmd: (2) grep -rnE "[Cc]lass[A-Za-z_]* *\. *['\"][^'\"]*\.php|['\"][^'\"]*\.php['\"] *\. *[$][[:alpha:]_]*[Cc]lass|\{[$][[:alpha:]_]*[Cc]lass[A-Za-z_]*\}[^'\"]*\.php|\([^)]*[$][[:alpha:]_]*[Cc]lass[A-Za-z_]*[^)]*\) *\. *['\"][^'\"]*\.php|sprintf *\([^)]*[$][[:alpha:]_]*[Cc]lass" --include=*.php . | grep -vE "(^|/)vendor/|(^|/)tests/|(^|/)specs/"
       cmd: (3) grep -rln "\['services'\]" --include=*.php . | grep -vE "vendor|tests|specs"
       cmd: (4) grep -rn "ntdst_set(" --include=*.php api core admin services support ntdst-core.php
INV-11 · expected: → EMPTY · actual: 0 hit(s) (and bin/guard.sh:277-285 carries the same line)
       cmd: grep -rn "wp_register_ability" --include=*.php . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
```

## Part 1 — core's invariants, re-aimed at STRIDE (from `/home/ntdst/Sites/worktrees/stride-abilities-bridge/web/app/mu-plugins/ntdst-core/ARCHITECTURE-INVARIANTS.md`, identical to the above; 11 checks parsed)

Run from `<scope>` = `web/app/mu-plugins/stride-core`; `X='(^|/)vendor/|(^|/)ntdst-core/|(^|/)ntdst-baseline/'` appended to every exclusion. Core's counts are calibration here, not targets; only what I can name is flagged.

```
INV-1 · expected: → empty (1) / ten hits… (2) / → empty (3) · actual: 1 hit(s) (1) / 48 mentions, 1 reader (2) / 0 hit(s) (3)
       cmd: (1) grep -rn "register_post_meta\|register_meta(" --include=*.php . | grep -vE "^(\./)?api/Data\.php|(^|/)vendor/|(^|/)tests/|$X" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
            hit: Modules/User/DashboardPageMetabox.php:57 register_post_meta('page', …) — WordPress's `page` type, not an NTDST model; pre-existing, not in the diff
       cmd: (2) grep -rn "show_in_rest" --include=*.php . | grep -vE "$X|(^|/)tests/"  — the one reader is Support/fields.php:119, which Stride's own INV doc (Ruling 103) names as THE read-back; pre-existing
       cmd: (3) grep -rn "public_fields\|public_shape\|publicRow" --include=*.php . | grep -vE "(^|/)vendor/|(^|/)tests/|$X"
INV-2 · expected: → EMPTY · actual: 20 hit(s), all Admin/AdminAPIController.php — Stride's ARCHITECTURE-INVARIANTS.md "Known accepted baseline — do NOT re-flag", ratcheted 23 → 20 by this branch (scripts/check-invariants.sh RAW_BASELINE=20). Not re-flagged.
       cmd: grep -rn "register_rest_route(\|wp_ajax_\|ntdst/api_data\|ntdst_actions" --include=*.php --include=*.js . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/|$X" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
INV-3 · expected: → EMPTY · actual: 1 hit(s) — Admin/AdminDashboardService.php:55 add_filter('show_admin_bar', '__return_true', 999): a WordPress admin-bar filter, not a route posture; false positive, pre-existing
       cmd: grep -rn "__return_true\|=> 'public'\|->public()\|public_actions" --include=*.php . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/|$X" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
INV-4 · expected: THREE hits… (1) / → EMPTY (2) · actual: 24 hit(s) across 17 files (1) / 0 hit(s) (2) — all admin FORM-POST nonce pairs (the INV-4 exception shape); none in the diff hunks
       cmd: (1) grep -rn "wp_create_nonce\|wp_verify_nonce\|check_ajax_referer\|wp_nonce_field\|get_nonce\|HTTP_ORIGIN\|HTTP_REFERER" --include=*.php --include=*.js . | grep -vE "$X"
       cmd: (2) grep -rn "fetch(" --include=*.js . | grep -vE "$X"
INV-5 · expected: TWENTY-ONE hits… · actual: 56 hit(s) — consumer tables, calibration only; none in the diff
       cmd: grep -rnE '(private|protected) +(static +\??array +\$|const +)[A-Za-z_]+ *= *(\[|null)' --include=*.php . | grep -vE "$X"
INV-6 · expected: → FIVE hits… (1) / → ONE each (2) · actual: 4 hit(s) (1) / 1 hit(s) (2) — (1) all `EnrollmentDataExtras::extract(`, a method name, not PHP's extract(); (2) EditionDuplicator.php:109 `private function redirectToList(): never`, pre-existing. Nothing in the diff.
       cmd: (1) grep -rn "is_404 = false\|redirect_canonical\|locate_template(\|extract(" --include=*.php . | grep -vE "$X"
       cmd: (2) grep -rn "function addPath\|function redirect" --include=*.php . | grep -vE "$X"
INV-7 · expected: → only support/RateLimiter.php counts attempts · actual: 33 hit(s) in 15 files — one counter outside the limiter, Admin/AdminAPIController.php:2346/:2360 (revealSensitiveField), which Stride's own INV doc names as exception E4; pre-existing, not in the diff
       cmd: grep -rn "set_transient\|get_transient" --include=*.php . | grep -vE "$X"
INV-8 · expected: (A) returns 55 lines and (B) returns 1 · actual: 578 hit(s) (A), 161 in the changed files / 18 hit(s) (B) — (A) is WordPress JSON-Schema `'type' => 'integer'|'string'|…` in REST `args` and the five new `schema` callables (the shape core's own INV-8 names as RelationField's exception; Stride lessons.md:259 says the same); (B) is `POST_TYPE`/`*_TYPES` constants whose NAME carries the word. Calibration only.
       cmd: (A) as above with `|$X` added to the exclusion; (B) likewise
INV-9 · expected: 0 · actual: not run — core-only (bin/zero-readers.sh sweeps core's package, not this scope) → Part 2 #13 covers the consumer side
       cmd: bash bin/zero-readers.sh — not executed
INV-10 · expected: (1) prints 0… / (2) empty / (3) one file / (4) … · actual: 5 summed (1) / 1 hit(s) (2) / stride-core.php (3) / 43 (4) — (2) is autoload.php:19, the consumer's own PSR-4 autoloader, which INV-10 allows ("by any autoloader the consumer installed"); (1) ordinary consumer file reads. Nothing in the diff.
       cmd: (1) grep -rc "glob(\|file_get_contents(\|preg_match('/^\\\\s*namespace\|spl_autoload_register" --include=*.php . | grep -vE "$X" | grep -v ':0$'  (summed per file)
       cmd: (2)(3)(4) as core's, re-aimed at `.` with `|$X`
INV-11 · expected: → EMPTY · actual: 0 hit(s) at <scope>; 1 hit(s) at web/app — plugins/ntdst-assistant/ntdst-assistant.php:26 `if (!function_exists('wp_register_ability'))`, a presence test, out of scope, not a door
       cmd: grep -rn "wp_register_ability" --include=*.php . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/|$X" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"
```

## Findings: 5 total
  0 Critical
  2 Drift (works correctly but violates framework convention)
  3 Borderline (might be a legitimate exception — flagged for review)

## Critical — fix before merge
None.

## Drift — schedule for cleanup

**CORE · INV-8 "One field-type vocabulary; a type name resolves in one table" — `api/Rest.php:98` `private const PATH_PARAMETER_TYPE = 'string';`** Its own docblock says it exists "only because the retired-field-type sweep reads that literal pair as a field declaration" — i.e. it was spelled to keep (A) at 55, and in doing so it trips (B), which now returns 2 where the doc's Status line (and the 5.4.0 pin) says 1. The constant is not named under `## Deliberate exceptions`. INV-8's own text on the only other (B) hit is the rule: "It is named below rather than renamed around — a check that only stays quiet because the constant was spelled to dodge it is a check that has stopped asking." Rule: INV-8 Mechanical check + Deliberate exceptions. Fix (one line): drop the const, write `'type' => 'string'` inline in `abilityInputSchema()`, re-run (A) → 56 with `api/Rest.php` at 4, and name that line under the INV-8 exceptions beside `admin/RelationField.php`'s two args-schema `'type' => '<name>'` lines (the same shape: WordPress JSON Schema, not a field type); re-pin (A)/(B) in the Status line. Alternative: keep the const and name it under (B) with the status line at 2. Either way the doc at 8d89c53 is wrong about (B) today, and `invariant-auditor` will see it.

**STRIDE · #13 zero-reader + an unnamed feature loss — `Modules/Assistant/prompts/domain.md`, `prompts/formatting.md`.** The deleted `ReadAbilityRegistrar::init()` carried a third hook beside the two registrations: `add_filter('ntdst_assistant/system_prompt', [$this, 'injectDomainPrompts'], 10, 2)` — the only reader of those two files. `web/app/plugins/ntdst-assistant/src/SystemPrompt.php:38` still applies the filter; nothing in stride-core hooks it now (`grep -rn "ntdst_assistant/system_prompt\|injectDomainPrompts" web/app` → the plugin's `apply_filters` only). So on the proof branch the assistant silently lost Stride's domain and formatting prompts, and two files have no reader. Not in F1–F9; the CLAUDE.md diff names `Assistant/ # prompts/` as what remains without naming who reads it. Rule: core's INV-9 / check #13; `SKILL.md` "Service, or plain class?". Fix: a plain class (e.g. `Modules/Assistant/AssistantPrompts.php`) hooking the filter from `plugin-config.php`, or delete the two files — and add the line as F10 to the findings list either way (Task 7's report says "each a shape mismatch or a missing route"; this is neither).

## Borderline — author judgement

**CORE · a second home of the timing predicate — `api/Rest.php:823-830 timing()` and `:833-840 abilitiesTiming()`.** Same body, same `function_exists` guards, same three states; only the hook name differs. The plan names it in Task 2's Interfaces, so it is a named deviation. One-line fix if you want one home: `timing(string $hook = 'rest_api_init')` and `timing('wp_abilities_api_init')` at the three abilities call sites. Rule: core's INV-5 spirit / CODE-MAP "a refusal in two homes drifted once".

**CORE · INV-7 Status line not re-pinned — `ARCHITECTURE-INVARIANTS.md` says `$permission($request)` at `api/Rest.php:928` precedes `attempt` at `:949`; the branch's inserted lines moved them to `:1304` / `:1325`.** The invariant holds; the doc's evidence line is stale on a branch that re-pinned INV-5 and INV-11 but not INV-7. Doc-only.

**STRIDE · a second home of the bulk cap — `Handlers/BulkRegistrationHandler.php:120` `'maxItems' => 500` beside `Handlers/Support/BulkRunner.php:33` `private const MAX_BATCH = 500`.** The two agree today and are free to drift; and because `args` validate first, an over-cap request now gets WordPress's `rest_invalid_param` 400 instead of the handler's own Dutch message (`BulkRunner.php:187`), which `shell.js` renders. Fix: make `MAX_BATCH` `public const` and write `'maxItems' => BulkRunner::MAX_BATCH`, or drop `maxItems` and let the handler's cap answer (the ability's input_schema then carries no bound — say which). Rule: check #14 / INV-5 spirit.

## Open questions
- `wp-abilities-verify` runtime mode (Task 7 Step 4) is the coder's to run on its DDEV; this read did not execute anything on either tree. Its check 1 should read Source+Runtime for all five `stride-v1/*` names, and Runtime-only for nothing (the registrars' `stride/*` prefix is gone from source).
- `INV-9` on core was not executed by this audit (read-only rule); the caller's reproduced gate reports `sweep 0 findings`. If the review wants it in this report's own hand, run `bash bin/zero-readers.sh` from the checkout beside the six site symlinks and paste the stdout line count.
- Out of the diff, pre-existing, not counted (for the record, each already named by Stride's own invariants doc or lessons): the 20 raw routes (INV-16 ratchet); `AdminAPIController.php:2346/2360` hand-rolled reveal counter (E4); `_ntdst_` batch-meta literals in `AdminAPIController.php:781-814, 1090-1132, 1343, 1489-1497, 1716-1722` (Stride INV-3 leg); `web/app/plugins/ntdst-assistant/ntdst-assistant.php:16` `if (!function_exists('ntdst_get'))` — a check #15 shape in a regular plugin core always precedes, outside this plan's scope.

## What's clean
Core: the whole option lives inside `api/Rest.php`'s own idiom — one entry in `OWN`, refusals only through `refuse()`, the record written after `register_rest_route()`, the two walks the only callers of `wp_register_ability*()`, `rest_get_server()` first in both walks, the permission handed to WordPress the resolved one and never `guard()`, one `rest_do_request()` per execution, one request builder, one slug rule; INV-1 through INV-7, INV-10 and INV-11 answer exactly what the doc says, INV-8 (A) is unchanged at 55 with `api/Rest.php` at its three named lines, INV-5 is the doc's 21, and `bin/guard.sh` carries INV-11's line. Stride: check #16 is empty everywhere; the five routes declare through `ntdst_rest('stride/v1')` with capability strings, every write names its two annotations truthfully, every `schema` describes the envelope `get_data()` returns, the new `args` admit exactly what `cohort.js`, `edition-admin.js` and `shell.js` send, and the three re-declarations ratchet the raw-route baseline from 23 to 20 with `scripts/check-invariants.sh` updated in the same commit. No repository bypass, no `ob_start`, no `template_include`, no baseline shadowing, no `function_exists('ntdst_')` guard in the changed files; the five new `*Schema()` methods each have their one `[$this, '…']` reader.

## Suggested calibration updates
1. **INV-11 re-aimed at a consumer: a `function_exists('wp_register_ability')` is a presence test, not a door.** Core's mechanical check is a bare word, so on a consumer tree it also matches `function_exists(` / `is_callable(` guards (first real case: `ntdst-assistant.php:26`). Check #16's `wp_register_ability(_category)?\(` spelling is the consumer-side form and does not see them. Rule: when INV-11's consumer run returns a hit whose match sits inside `function_exists(` or `is_callable(`, report it as a presence test on that line and do not count it as a bypass; never re-spell core's own check.
2. **A deleted registrar carries more than registrations — read its `init()` before calling #16 clean.** Stride's `ReadAbilityRegistrar::init()` hooked `wp_abilities_api_categories_init`, `wp_abilities_api_init` AND `ntdst_assistant/system_prompt`; the plan's mapping table moved the first two and nobody owned the third, so the domain prompts silently stopped reaching the assistant. Rule: on a #16 fix that deletes a `*AbilityRegistrar`, list every `add_action`/`add_filter` the class registered and name the new home of each one that is not an ability, beside the abilities-with-no-route list the check already asks for.
