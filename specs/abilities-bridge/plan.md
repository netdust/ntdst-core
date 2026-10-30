# Abilities bridge Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A route declared through `ntdst_rest()` with an `ability` option is also a WordPress ability, executed by dispatching that route — ntdst-core 5.5.0, proven on a Stride branch that is never merged.

**Architecture:** `registerOne()` validates the `ability` block through the existing `refuse()` path and records the declaration in a static buffer; `ntdst-core.php` mounts two hooks once at boot (`wp_abilities_api_categories_init`, `wp_abilities_api_init`) whose walks call `rest_get_server()` first (so declarations made inside `rest_api_init` exist on a STDIO or admin request) and then register one category per slug and one ability per record. The ability's `execute_callback` builds a `WP_REST_Request` and calls `rest_do_request()`, so the route's permission, limiter and response shaping run once where they already run; its `permission_callback` is the resolved permission without the limiter guard, so discovery charges nothing. Nothing outside `api/Rest.php` calls `wp_register_ability()` — INV-11.

**First working version:** Task 5 — the first thing Stefan can see: on Stride's own DDEV, `wp_get_abilities()` lists `stride-v1/mark-attendance`, the MCP Adapter discovers it over STDIO, one execution increments the limiter once and a subscriber is refused. Tasks 2–4 are its code and cannot be seen without a consumer.

**Tech Stack:** PHP 8.2, WordPress 6.9+ Abilities API (read from 7.1 at `~/.../scratchpad/wp71-abilities/`, see Task 3), Brain Monkey + PHPUnit 10.5 (`composer gate`), Stride on DDEV (WP 7.1, `wordpress/mcp-adapter` 0.7.x), the upstream `wp-abilities-verify` skill in runtime mode.

**Spec:** `/home/ntdst/Projects/netdust-plugins/specs/wp-ai-base/spec.md` — plan 3 of seven: R1–R7; Decisions 1–4, 9, 12–14, 17. The harness already describes this release: `netdust-wp/skills/ntdst-framework/SKILL.md:114-116` (the option, the full name, the verify how-to) and the drift reviewer's check #16; what core ships must match those lines.

## Global Constraints

- Evidence: no task claims a line below without its evidence in the task report — the
  command as run with its output quoted, or the review that owns the line. No quote, not met.
- Base: ntdst-core is the framework base and its layering applies to every new class —
  `netdust-wp:ntdst-framework`; where files live — `netdust-wp:ntdst-patterns`. Owned by the
  whole-branch review, joined on WordPress by `netdust-wp:ntdst-drift-reviewer` on the changed
  paths (Part 1 runs core's invariants live); its `## Findings:` header is quoted.
- Golden path: the plan names the slice this feature is built to (`netdust-wp:ntdst-patterns`
  → `golden-paths/`); every departure from it is named and justified, or `none`. Judged, not
  grepped: `/plan-review` checks the slice is named, the whole-branch review that code keeps it.
- Security: every data flow (AJAX action, REST route, form post, shortcode attribute,
  settings save, custom query) states its four pillars — validate, sanitize, escape,
  authorize — as `netdust-wp:wp-security` defines them; a pillar that does not apply is
  written `n/a — <why>`, never omitted. Evidence per flow: each pillar cited `file:line`; the
  output of `grep -nE "current_user_can|wp_verify_nonce|check_ajax_referer|check_admin_referer|permission_callback" <changed files>`;
  the denial test's name, RED then GREEN. `security-sentinel` owns the review when the plan
  carries a threat model.
- Drift: nothing `netdust-wp:ntdst-drift-reviewer` reports — a Critical or Drift finding is
  fixed or is a deviation the plan names; the review quotes the agent's findings, never "looks clean".
- Tests: the close is `make gate` (a package: `composer gate`). Unit = Brain Monkey, integration = wp-phpunit through
  DDEV, e2e = Playwright; commands and setup are `netdust-wp:wp-testing`'s. Evidence: the exit
  code and the summary lines of a run after the task's last edit. A tier that did not run is
  reported as not run, never as a pass. A site's tests cover its code and its configuration of
  ntdst-core and ntdst-baseline; the packages' own behaviour is tested in their repos.
- Local and ship: DDEV always; the Makefile is the only route to a server
  (`netdust-devops:devops`); anything that must ship names its `deploy.payload` path, and
  `make deploy-test env=staging` output listing it is quoted. An rsync, scp or SFTP to a server
  outside the Makefile is a defect the review reports. A package ships by tag: no payload, no deploy line.
- Abilities: a route an agent will operate declares `ability` (`netdust-wp:ntdst-framework`, core 5.5.0); the task that touches such a route runs the upstream `wp-abilities-verify` skill on its own DDEV, in runtime mode as `ntdst-framework` reads it, and quotes its report in the task report — its reviewer reads the quote there, the way it reads every line's evidence, and never runs it (the headless review reads code, plan and spec; runtime mode executes write abilities); a hand-registered ability is drift check #16.

| Red flag — the excuse | The answer |
|---|---|
| "Admin-only screen, so no nonce" | Capability says who; the nonce says they meant it. Both. |
| "The value comes from the database" | Stored input is input. Escape it on output. |
| "The reviewer will catch it" | The reviewer checks the evidence you quoted. No quote, not met. |
| "`make gate` was green earlier" | Earlier is not after your last edit. Run it, quote it. |
| "The integration tier needs DDEV; skip it" | `ddev start`. A tier not run is not a pass. |
| "Plain WordPress is simpler here" | On ntdst-core that is drift. Use the framework's door or name the deviation. |
| "Too small for the golden path" | Then the departure is `none` or one named line. Silence is the defect. |
| "The denial is obvious, no test needed" | Obvious is untested. Write it RED first. |
| "Just rsync this one file" | A hand-deploy is drift nobody sees. The Makefile, or nothing. |

The spec's own:

- Core's philosophy: wrap WordPress, never replace it; a feature enters only with a named consumer (Stride, R6); invariants are enforced by grep.
- `wp_register_ability()` is callable only during `wp_abilities_api_init`, which fires lazily inside `WP_Abilities_Registry::get_instance()` after `init` (R4). Exposure has three spellings across 6.9 / 7.0 / 7.1 (R2); the bridge writes all three `true`, and there is no `public` option (Decision 12).
- Refuse rather than guess (R3, Decisions 1–4): a write verb's ability names boolean `destructive` and `idempotent`; an `output_schema` comes from the route's `schema` or the option, else refuse; the category is the namespace slug unless `category` says otherwise; on WordPress < 6.9 one `_doing_it_wrong` per declaration, the route still registers.
- Core hooks the abilities init unconditionally and fills the buffer inside the walk (Decision 13).
- The package repo carries no `site.yml`: the gate is `composer gate` (syntax, unit suite, `bin/guard.sh`, `composer audit`), the review is `/feature-review abilities-bridge`, the release is a tag Stefan makes. Integration and e2e tiers do not exist in this package (plan 5) and are reported as not run.
- Branch `feature/abilities-bridge` from `origin/main` `9a593c5` (v5.4.2). Golden path: none — the slice is `api/Rest.php`'s own option shape (an option in `$known`, refusals through `refuse()`, statics reset by `resetRestStatics()`); the departures are named per task.
- The Stride branch of R6 is evidence, never merged, never promoted (Decision 14).

## Review Focus

1. **Denied actor** (edge 2): an MCP client without the route's capability executes a write ability → the ability's own permission denies (`ability_invalid_permissions`) and, past it, the route's `guard()` answers inside `rest_do_request()`; the handler never runs. → Test map rows 1 and 3; Stride flow `denied-write`.
2. **Discovery as a drain**: `permission_callback` is never the limiter guard; listing or `check_can_execute` writes no transient. → Row 3 (`testThePermissionCallbackChargesNothing`), Stride flow `execute-once` (the transient count moves by exactly one, on execution only).
3. **Anonymous destructive** (INV-3): a `->public()` write with an `ability` block is refused before the block is read, so no record exists. → Row 2 (`testAPublicWriteNeverBecomesAnAbility`).
4. **Second door** (INV-11): `wp_register_ability` anywhere but `api/Rest.php` → `bin/guard.sh` grep (row 4), `invariant-auditor` runs it, drift check #16 on Stride.
5. **Wrong order / re-entry** (edge 3): the abilities hook fires on a request where `rest_api_init` has not (STDIO, admin) → each walk calls `rest_get_server()` first; a declaration after `wp_abilities_api_init` finished gets `abilities:after-init`; a declaration while it runs (the walk's own flush) is recorded. Core itself mounting after the hook has fired refuses loudly (`abilities:mounted-late`) and mounts nothing. → Row 2 (`testTheWalkFillsTheBufferBeforeRegistering`, `testADeclarationAfterTheHookIsNoticedNotLost`, `testMountingAfterTheAbilitiesHookRefusesLoudly`, `testADeclarationInsideTheHookIsRecorded`).
6. **Empty / zero** (edge 1): an empty buffer registers no category and no ability; a route without `args` and without path parameters registers no `input_schema` (WordPress then accepts only a null input). → Row 2 (`testAnEmptyBufferRegistersNothing`, `testARouteWithoutArgsRecordsNoInputSchema`).
7. **Boundary** (edge 5): a namespace whose slug is not `^[a-z0-9-]+$` (`my_ns/v1`), a name with a slash or an uppercase letter, a path parameter value carrying `/` or `..` → refused at declaration; the value is `rawurlencode`d and the server's own regex decides. → Rows 1 and 3 (`testAPathParameterIsEncodedNotInterpolated`).
8. **Shape, not behaviour** (edge 8): the walk test asserts the registered args array minus the two closures and that both are closures; the dispatch test CALLS them. → Rows 2 and 3.
9. **Mid-flow failure** (edge 6): the route's `schema` callable returns something that is not a non-empty array at walk time → that one ability is skipped with `abilities:schema`, the route stays, the other records register. → Row 2 (`testASchemaThatResolvesToNothingSkipsThatAbilityOnly`).
10. **INV-7 / INV-9**: the limiter is charged once per execution, inside the dispatch, never from the permission (row 3; Stride count); `mountAbilities()` is the one new public symbol and `ntdst-core.php` is its reader (`bin/zero-readers.sh` at review).

## Simplest design

The simplest thing that meets the ask is what Stride does today: each consumer registers its own abilities on `wp_abilities_api_init` with its own permission closure and its own service calls. It is rejected by the spec's anti-goal — it is a second door with a second permission, no limiter and no response shaping, and the drift reviewer's check #16 exists to find it. The simplest thing inside core would be `wp_register_ability()` inline from `registerOne()`; WordPress refuses that call outside its lazy hook, so the record-and-walk is the smallest shape that works, and the walk's `rest_get_server()` call is the one non-obvious step, forced by `rest_api_init` timing on non-REST requests. The plan uses it. No new class, no new file in the package besides the threat model and the tests.

## Threat model

**Assets.** The site's write surface behind capabilities such as `stride_manage`, now reachable by an LLM client acting as a logged-in user; the limiter's budget (`wp_options` rows); the application password or session the MCP user holds; the inventory of what exists (names, descriptions, schemas).

**Attacks → mitigations.**

1. **An MCP client beyond its capability executes a write** (actor: a logged-in user holding an application password; path: `WP_Ability::execute()` → `execute_callback`) → the ability's `permission_callback` is the route's resolved permission and denies first; past it, `execute_callback` dispatches through `rest_do_request()`, so the route's `guard()` answers with the route's own 401/403 and the handler never runs. *Lives in* `walkAbilities()` / `abilityCallbacks()` (`api/Rest.php`). Cost named: a consumer's own permission callable runs on two request objects per execution (the ability's synthetic one, then the dispatch's — the memoize is per object by design), so a side-effectful callable fires twice per ability run; WordPress requires a callable `permission_callback` and uses it for discovery, so `__return_true` there is not an option.
2. **Discovery as a drain** (actor: any `read` user listing abilities or asking `check_can_execute`; path: `permission_callback`) → the permission handed to WordPress is the UNGUARDED resolved permission; the limiter is charged only inside the dispatch. *Lives in* `abilityCallbacks()`: it reads the record's `permission`, never `guard()`.
3. **An anonymous destructive ability** (actor: nobody logged in; path: a `->public()` write with an `ability` block) → the verb rule in `registerOne()` refuses a `->public()` write before the block is read (INV-3); no record, no ability. *Lives in* `registerOne()` (existing).
4. **A second door** (actor: a consumer author; path: `wp_register_ability()` with its own permission) → INV-11's grep in `bin/guard.sh` fails the gate; drift check #16 on consumers. *Lives in* `bin/guard.sh`, `ARCHITECTURE-INVARIANTS.md`.
5. **Path smuggling** (actor: the MCP client; path: an input value for a path parameter such as `12/../../wp/v2/users`) → values are `rawurlencode`d into the path; the REST server matches the route's own regex and answers `rest_no_route` to anything else. *Lives in* `abilityRequest()`.
6. **A declaration that never becomes an ability** (actor: a consumer declaring after `wp_abilities_api_init` finished; path: boot order) → `abilities:after-init`, one `_doing_it_wrong` per declaration, the route still registers (R4 for core, the R15 trap for consumers). *Lives in* `registerOne()` via `abilitiesTiming()`.
7. **A lying annotation** (actor: a consumer; path: `readonly` on a GET whose handler writes) → `readonly` is derived from the verb, `destructive` / `idempotent` are the consumer's own words; core cannot read a handler. *Deferred to* the upstream `wp-abilities-verify` adversarial check, run on the Stride branch (Task 7).
8. **The walk's side effect** (path: `rest_get_server()` inside the abilities hooks on an admin or CLI request fires `rest_api_init` there) → accepted, Decision 13; WordPress's own admin already does this for the editor.

**Deferrals.** Prompt injection through `label` / `description` text is the consumer's words and out of scope for core (R7). Who may hold an application password at all is baseline's C1 (plan 4; administrators by default). The MCP transport's own authentication is the adapter's (REST transport = WordPress auth; STDIO = the CLI user). A consumer permission callable that reads request headers (a nonce, an origin) sees a synthetic request with none and denies — narrower, never wider. A third plugin registering abilities from inside `rest_api_init` while the walk runs is wrong on WordPress's own terms (`wp_register_ability` refuses outside its hook). WordPress < 6.9: inert, one notice per declaration, the route registers.

Every mitigation is a Test map row (1–4) or the guard (row 4); the denial is asserted RED first in each.

## Test map

| Unit | Seam | Tests: tier · file · command | E2E flows |
|---|---|---|---|
| 1. The `ability` option — refusals and the record (`registerOne()`, `ability()`, `slug()`, `abilitiesTiming()`) | `ntdst_rest($ns)->get/post(..., ['ability' => […]])` → the route table, `logMessages('api', …)`, the recorded buffer read back through the walk | unit · `tests/Unit/NtdstRestAbilityTest.php` · `vendor/bin/phpunit --filter NtdstRestAbilityTest` | — |
| 2. The walk (`mountAbilities()` and its late refusal, `walkCategories()`, `walkAbilities()`, `abilityArgs()`, `abilityInputSchema()`) | `NTDST_Rest::mountAbilities()`, then the two hooks' callbacks → `wp_register_ability_category()` / `wp_register_ability()` recorders | unit · same file | — |
| 3. The dispatch (`abilityCallbacks()`, `abilityRequest()`) | the registered ability's `execute_callback` and `permission_callback` → `rest_do_request()` | unit · `tests/Unit/NtdstRestAbilityExecuteTest.php` · `vendor/bin/phpunit --filter NtdstRestAbilityExecuteTest` | `discover` (STDIO lists the names and schemas), `execute-once` (one dispatch, the limiter +1), `denied-write` (a subscriber is refused) — driven by hand on Stride in Tasks 5 and 7, not committed |
| 4. INV-11 | `bin/guard.sh` | guard · `composer guard` (RED: a `wp_register_ability(` line in a throwaway `services/X.php`, then removed) | — |
| 5. Docs and version (INV text, philosophy, CODE-MAP, README, CHANGELOG, header, branch-alias) | the files | none — proven by review (`PackageBootIntegrityTest::testThePackageNeverClaimsToBeOlderThanWhatItShips` pins the header) | — |
| 6. The Stride proof branch | `ddev wp eval`, `wp mcp-adapter serve` over STDIO, the `wp-abilities-verify` skill in runtime mode, `netdust-wp:ntdst-drift-reviewer` | none committed — the task report, quoted | the three flows above |

Rows 1–3 are a security boundary (an auth/capability surface): **subagent-driven** for Tasks 2–4 (`superpowers:subagent-driven-development`); the plan also outlives one context, so the same mode holds for Tasks 5–7. Name the rule in the ledger.

---

## File structure

- `api/Rest.php` — the option, the buffer, the two walks, the two closures. Grows by one option in `OWN`, two statics, one public static (`mountAbilities`), one parameter on `refuse()` and private helpers; nothing else in the class changes.
- `ntdst-core.php` — one line after the `api/Rest.php` require: `NTDST_Rest::mountAbilities();`, and `Version: 5.5.0`.
- `tests/Support/wp-rest-doubles.php` — ONE `WP_REST_Request` / `WP_REST_Response` / `WP_Error` double for the suite, required from `tests/bootstrap.php` (today four files carry guarded copies with an array constructor, two of them subclassed; the dispatch needs the real `($method, $route)` shape).
- `tests/Support/RestApiInitHarness.php` — unchanged. The abilities hooks and the WordPress ability functions are Brain Monkey stubs in the two new test files (they never reach `function_exists` elsewhere: `registerOne()` reads a flag, not the function).
- `tests/Unit/NtdstRestAbilityTest.php`, `tests/Unit/NtdstRestAbilityExecuteTest.php` — rows 1–2 and 3.
- `docs/threat-models/2026-10-abilities-bridge.md`, `ARCHITECTURE-INVARIANTS.md` (INV-11), `bin/guard.sh`, `docs/philosophy.md`, `docs/architecture/CODE-MAP.md`, `README.md`, `CHANGELOG.md`, `composer.json`.
- Stride, in a worktree at `~/Sites/worktrees/stride-abilities-bridge` on `chore/abilities-bridge`: the registrars deleted, routes gaining `args` + `schema` + `ability`, `plugin-config.php`, `web/app/plugins/ntdst-assistant/src/AbilityBridge.php`, `composer.json` + lock (the core branch and the MCP Adapter), `.ddev/config.local.yaml` (untracked).

## The option, as a consumer writes it

```php
ntdst_rest('stride/v1')->post('/admin/attendance', [$c, 'handleMarkAttendance'], [
    'permission' => 'stride_manage',
    'rate_limit' => 300,
    'args'       => [
        'session_id' => ['type' => 'integer', 'required' => true, 'description' => 'Session post ID'],
        'user_id'    => ['type' => 'integer', 'required' => true],
        'status'     => ['type' => 'string', 'default' => 'present'],   // no enum: the handler also takes 'unmarked' and the '' cohort.js sends to clear a mark
    ],
    'schema'     => [$c, 'attendanceSchema'],          // WordPress's own route option: a callable returning the item schema
    'ability'    => [
        'name'        => 'mark-attendance',             // ^[a-z0-9-]+$ ; full name stride-v1/mark-attendance
        'label'       => 'Mark attendance',
        'description' => 'Marks one user present, absent or excused for one session.',
        'destructive' => false,                         // both required on a write verb
        'idempotent'  => true,
        // 'output_schema' => [...]                      // optional; else the route's schema
        // 'category'      => 'stride',                  // optional; else the namespace slug stride-v1
    ],
]);
```

---

### Task 1: The threat model, as a file

**Files:**
- Create: `docs/threat-models/2026-10-abilities-bridge.md`

**Interfaces:**
- Produces: the document the security review reads (R7); nothing executable.

- [ ] **Step 1: Write the file in the folder's shape** (`docs/threat-models/2026-08-21-rest-auth-before-limiter.md` is the model): a title `# Threat model — the abilities bridge (Class D, stakes high)`, `**The diff.**`, `**Asset.**`, a table `| # | Threat | Mitigation in this diff |` with rows T1–T8 copied from this plan's `## Threat model` (attack → mitigation, each naming the function), a `**Deferrals.**` paragraph with the plan's list, and `**Denial path under test.**` naming `testAnUnauthorizedExecutionIsRefusedBeforeTheHandlerRuns`, `testThePermissionCallbackChargesNothing` and `testAPublicWriteNeverBecomesAnAbility`, and saying that T1's second half — the route's 401/403 inside `rest_do_request()` — is not unit-tested in the new files (they stub `rest_do_request()`) and rests on the existing guard tests and the Stride `denied-write` flow. Under 70 lines.

- [ ] **Step 2: Commit**

```bash
git add docs/threat-models/2026-10-abilities-bridge.md
git commit -m "abilities: threat model — an MCP client is a logged-in user, the route's capability is the floor"
```

---

### Task 2: The `ability` option — refusals and the record

**Files:**
- Modify: `api/Rest.php:42` (`OWN`), `:94-145` (statics), `:694-781` (`registerOne()`), `:579-608` (beside `timing()` / `hookPriority()`), `:1086-1137` (`refuse()` gains `bool $log = true`); `ntdst-core.php:5` (`Version: 5.5.0`) and `:65` (after `require_once NTDST_PATH . '/api/Rest.php';`)
- Create: `tests/Unit/NtdstRestAbilityTest.php`

**Interfaces:**
- Consumes: `refuse(string $route, string $methods, string $why, bool $fatal = true, ?string $since = null, string $from = 'route', bool $perCallSite = false): void`; `permission(mixed, bool): mixed`; `self::READ`; `self::verbs(string): list<string>`; `self::timing()`'s shape.
- Produces:
  - `private static array $abilities = [];` keyed by the full name `<slug>/<name>`; each record is `array{namespace: string, route: string, methods: string, permission: callable|string, args: array, schema: callable|array|null, ability: array, readonly: bool}` — `permission` is the RESOLVED, unguarded value `permission()` returned; `ability` is the validated block with `category` filled in; `readonly` is `array_diff(self::verbs($methods), self::READ) === []`.
  - `private static bool $abilitiesMounted = false;`
  - `public static function mountAbilities(): void` — returns at once when `self::$abilitiesMounted` or `!function_exists('wp_register_ability')`; when `did_action('wp_abilities_api_init')` already reads 1 it refuses loudly and mounts nothing (R4): `self::forNamespace('ntdst')->refuse('(abilities)', '-', 'abilities:mounted-late — wp_abilities_api_init has already fired, so nothing declared through ntdst_rest() can become an ability on this request; load ntdst-core before init', false, '5.5.0', 'mountAbilities')` (Ruling 10; unreachable on a normal boot, where the registry refuses before `init`); else `add_action('wp_abilities_api_categories_init', static function (): void { self::walkCategories(); })`, `add_action('wp_abilities_api_init', static function (): void { self::walkAbilities(); })`, sets the flag. The two walks are Task 3's; a closure naming a method that does not exist yet is legal until it runs. Its one reader is `ntdst-core.php`: `NTDST_Rest::mountAbilities();` on the line after the require, with the one-line comment `// before anything asks the lazy abilities registry (after init)`.
  - `refuse()` gains a last parameter `bool $log = true`; `false` skips the `ntdst_log('api')` line, so a notice can be `_doing_it_wrong` alone — which WordPress itself gates on `WP_DEBUG` (Decision 3, Ruling 9).
  - `ntdst-core.php:5` becomes `Version: 5.5.0` HERE, not in Task 6: `PackageBootIntegrityTest::testThePackageNeverClaimsToBeOlderThanWhatItShips` (`:1378-1419`) reads every `'x.y.z'` after a `_doing_it_wrong(` mention in a shipped file and the `'5.5.0'` literals below would fail it under the 5.4.1 header (a pre-existing lag: the 5.4.2 tag shipped with 5.4.1 in the header).
  - `private static function slug(string $namespace): string` — `str_replace('/', '-', trim($namespace, '/'))`.
  - `private static function abilitiesTiming(): string` — `'before'` / `'inside'` / `'after'` for `wp_abilities_api_init`, the same three states and the same `function_exists` guards as `timing()`.
  - `private function ability(string $route, string $methods, array $options): ?array` — returns the validated block, or `null` after exactly one `refuse()`. Called from `registerOne()` ONLY when `isset($options['ability'])`, after the unknown/retired option checks and before `$limits`; `'ability'` joins `OWN` (so `$known` inherits it and `array_diff_key($options, array_flip(self::OWN))` at `:776` keeps it away from `register_rest_route()`). A `null` from a FATAL refusal returns from `registerOne()` (the route is not registered); a `null` from a non-fatal one lets the route register without a record.
  - The record is written right after `register_rest_route()`.
- Refusals, in this order, each one `refuse()` call whose `$why` starts with the id shown; fatal unless said otherwise, `$since` `'5.5.0'`:
  1. `abilities:unavailable` — `self::$abilitiesMounted` is false (WordPress < 6.9, or core mounted nothing): NON-FATAL and `log: false` — one `_doing_it_wrong` per declaration, no api-log line, so it is debug only (Decision 3): `'the Abilities API is not available on this WordPress (6.9+), or ntdst-core mounted after it had initialized, so the route is registered and the ability is not'`. Checked FIRST, before the block is read: the block is inert, not validated.
  2. `abilities:after-init` — `abilitiesTiming() === 'after'`: NON-FATAL, the route registers, no record.
  3. `ability:shape` — `$options['ability']` is not an array.
  4. `ability:unknown` — a key outside `name, label, description, destructive, idempotent, output_schema, category`.
  5. `ability:namespace` — `slug($this->namespace)` does not match `/^[a-z0-9-]+$/`.
  6. `ability:name` — `name` missing, not a string, or not matching `/^[a-z0-9-]+$/`.
  7. `ability:label`, `ability:description` — missing, not a string, or `''`.
  8. `ability:write-annotations` — a write verb (any verb outside `self::READ`) whose `destructive` or `idempotent` is absent or not a `bool`. On a read verb both keys are accepted and ignored: the verb decides a read's annotations (`readonly` true, `destructive` false, `idempotent` true) — Ruling 8, one comment line.
  9. `ability:output-schema` — neither `output_schema` (a non-empty array) nor the route's `schema` (a callable or a non-empty array) exists.
  10. `ability:category` — present but not a string matching `/^[a-z0-9-]+$/`.
  11. `ability:duplicate` — `self::$abilities` already holds `<slug>/<name>`.

- [ ] **Step 1: Write the failing tests** — `tests/Unit/NtdstRestAbilityTest.php`, `use RestApiInitHarness;`, setUp as `NtdstRestDefaultsTest.php:95-150` (the route-table alias for `register_rest_route`, `rest_get_server`, the `add_action` alias into `$this->hooked`, `_doing_it_wrong` stubbed, `did_action` / `doing_action` aliases that answer `rest_api_init` from the harness fields and `wp_abilities_api_init` from two new private fields `$abilitiesInitDid` / `$abilitiesInitDoing` on the test), plus `Functions\when('wp_register_ability')` / `wp_register_ability_category` aliases recording the args array into `$this->registered[$name]` / `$this->categories[$slug]` (Task 3 reads them; here they stay empty). A private `mount(): void` helper calls `NTDST_Rest::mountAbilities()`. Every test but the inert one mounts first. The denial first:

```php
public function testAWriteVerbWithoutItsAnnotationsIsRefused(): void
    // ->post('/x', $h, ['permission' => 'edit_posts', 'schema' => $schema, 'ability' => ['name' => 'x', 'label' => 'X', 'description' => 'd']])
    // assert: route absent from the table; one 'error' log line whose message contains 'ability:write-annotations'
public function testEachRefusalNamesItsReason(): void
    // a dataProvider over cases 3–11 above: each declaration, the id expected in the 'error' log, the route absent
public function testAValidDeclarationRegistersTheRouteWithItsGuard(): void
    // ->get('/admin/stats', $h, ['permission' => 'stride_view', 'args' => [...], 'schema' => [...], 'ability' => [...]])
    // assert: the route is in the table, permission_callback a closure (capability → guard), 'ability' NOT among the args handed to register_rest_route (it is OWN-like: consumed, not forwarded)
public function testADeclarationAfterTheHookIsNoticedNotLost(): void
    // mount() FIRST, then abilitiesInitDid = 1, abilitiesInitDoing = false (the other order is the late-mount test below), declared through declareInHookState('after', …) so it registers at once instead of queueing: the route IS in the table; one 'warning' containing 'abilities:after-init'
public function testWithoutTheBridgeMountedTheOptionIsInertAndNoticed(): void
    // no mount(): the route is in the table; through the setUp's _doing_it_wrong recorder ($this->wrongs, as NtdstRestDefaultsTest.php:159-161 and :299): assertCount(1), [0][1] contains 'abilities:unavailable', [0][2] === '5.5.0'; logMessages('api', 'warning') === [] (debug only); a second identical declaration keeps the count at 1 (refuse() de-duplicates); the block is not validated (a bad name passes). Never Functions\expect() on a function the setUp already when()-stubbed — Brain Monkey keeps the alias and the expectation sees nothing
public function testMountingAfterTheAbilitiesHookRefusesLoudly(): void
    // abilitiesInitDid = 1, abilitiesInitDoing = false, THEN mount(): $this->hooked has neither abilities hook; one 'warning' containing 'abilities:mounted-late'; a declaration afterwards → $this->wrongs has two entries, 'mounted-late' then 'unavailable'
public function testAReadVerbIgnoresDestructiveAndIdempotent(): void          // route in the table, no error
public function testANamespaceWhoseSlugIsNotAnAbilityPrefixIsRefused(): void   // ntdst_rest('my_ns/v1'), 'ability:namespace'
public function testADuplicateFullNameRefusesTheSecondDeclaration(): void      // two routes, one name: first in the table, second absent, 'ability:duplicate'
public function testMountHooksBothWalksOnce(): void
    // mount(); mount(); → exactly one closure on 'wp_abilities_api_categories_init' and one on 'wp_abilities_api_init' in $this->hooked
```

- [ ] **Step 2: Run them to see them fail**

Run: `vendor/bin/phpunit --filter NtdstRestAbilityTest`
Expected: FAIL — `unknown option(s): ability` refusals and `Call to undefined method NTDST_Rest::mountAbilities()`.

- [ ] **Step 3: Implement** `$abilities`, `$abilitiesMounted`, `mountAbilities()`, `slug()`, `abilitiesTiming()`, `ability()`, the `OWN` entry, the `refuse()` parameter, the `registerOne()` changes, the `ntdst-core.php` mount line and its `Version: 5.5.0` header as the Interfaces block says. `ability()` stays under one screen: one early return per refusal, no nested validation helper. The read-verb annotation note is the only comment.

- [ ] **Step 4: Run the file** — `vendor/bin/phpunit --filter NtdstRestAbilityTest`: GREEN.

- [ ] **Step 5: `composer gate`** exit 0 — quote `OK (… tests, … assertions)` and `guard` ok. `bash bin/zero-readers.sh`: `mountAbilities` has its reader (quote the line).

- [ ] **Step 6: Commit**

```bash
git add api/Rest.php ntdst-core.php tests/Unit/NtdstRestAbilityTest.php
git commit -m "rest: the ability option — validated through refuse(), recorded, mounted once at boot, never registered inline"
```

---

### Task 3: The walk — two hooks, mounted once at boot

**Files:**
- Modify: `api/Rest.php` (beside Task 2's helpers)
- Test: `tests/Unit/NtdstRestAbilityTest.php` (the walk half)

**Interfaces:**
- Consumes: Task 2's statics, record shape and `mountAbilities()` (whose closures name the two walks below); WordPress 7.1 as read in `/tmp/claude-1000/-home-ntdst-Projects-netdust-plugins/f07572ac-69e7-49a9-aa74-73907cf6b5c6/scratchpad/wp71-abilities/` (`abilities-api.php:290-312` — `wp_register_ability()` refuses outside its hook; `class-wp-abilities-registry.php:85-150` — name `^[a-z0-9-]+\/[a-z0-9-]+$`, duplicate refused, category must already be registered; `class-wp-ability.php:262-332` — `label`, `description`, `category`, `execute_callback`, `permission_callback` required, `input_schema` / `output_schema` / `meta` arrays; `:340-371` — `meta.show_in_rest` and `meta.public` must be bool; `class-wp-ability-category.php:130-140` — a category needs `label` and `description`).
- Produces:
  - `private static function walkCategories(): void` — `rest_get_server();` then, for each DISTINCT `ability['category']` in `self::$abilities`, `wp_register_ability_category($category, ['label' => $category, 'description' => 'Routes declared through ntdst_rest(), as abilities.'])`.
  - `private static function walkAbilities(): void` — `rest_get_server();` then for each record `wp_register_ability("<slug>/<name>", self::abilityArgs($record))`; a record whose `abilityArgs()` returns `null` is skipped.
  - `private static function abilityArgs(array $record): ?array` — the exact array, in this key order: `label`, `description`, `category`, `input_schema` (ONLY when `abilityInputSchema()` returns a non-empty array), `output_schema` (the block's `output_schema`, else the route's `schema` called when callable; when the result is not a non-empty array: one non-fatal `refuse()` through `self::forNamespace($record['namespace'])` with id `abilities:schema`, `$from` `'ability'`, and return `null`), `execute_callback`, `permission_callback` (both from `abilityCallbacks($record)` — Task 4; here two closures that `throw new LogicException('Task 4')`), `meta` = `['annotations' => ['readonly' => $record['readonly'], 'destructive' => <declared, or false on a read>, 'idempotent' => <declared, or true on a read>], 'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true]]`.
  - `private static function abilityInputSchema(string $route, array $args): array` — `[]` when there is nothing; else `['type' => 'object', 'properties' => …, 'required' => …]`. Properties: every named group `(?P<name>…)` in `$route` as `['type' => 'string']` and required, overridden by an `args` entry of the same name; every `args` entry copies the keys `type, description, enum, default, items, properties, format, minimum, maximum, minLength, maxLength` when present (never `sanitize_callback` / `validate_callback` — not JSON), `'required' => true` moves the name into `required`. Omit `required` when empty.
- [ ] **Step 1: Write the failing tests** (same file). A `fire(string $hook): void` helper runs every callback in `$this->hooked[$hook]` in priority order with `abilitiesInitDid` 1 and `abilitiesInitDoing` true while `wp_abilities_api_init` runs; the `rest_get_server` alias additionally records `'rest_get_server'` into `$this->calls` and calls `$this->fireRestApiInit()` when `restApiInitDid === 0`; the two WordPress aliases record `'wp_register_ability_category:'.$slug` / `'wp_register_ability:'.$name` into `$this->calls` too.

```php
public function testAPublicWriteNeverBecomesAnAbility(): void
    // ->post('/x', $h, ['schema' => …, 'ability' => [... destructive/idempotent true]])->public(); mount(); fire both
    // assert: the route is absent (the existing verb rule), no error mentions 'ability:', $this->registered === []
public function testAValidReadDeclarationIsRecordedOnce(): void
    // the GET /admin/stats; mount(); fire both → $this->registered has exactly the one key 'stride-v1/get-stats'
public function testADeclarationInsideTheHookIsRecorded(): void
    // declared 'before' (hooked at rest_api_init 10) so the walk's own rest_get_server() flushes it while abilitiesInitDoing is true: registered
public function testARouteWithoutArgsRecordsNoInputSchema(): void
    // ->get('/admin/stats', …) with no args and no path parameter: the registered args array has no 'input_schema' key
public function testTheWalkFillsTheBufferBeforeRegistering(): void
    // declare 'before' (hooked at rest_api_init 10), mount(), fire categories then abilities: $this->calls begins ['rest_get_server', 'wp_register_ability_category:stride-v1', 'rest_get_server', 'wp_register_ability:stride-v1/mark-attendance']
public function testTheRegisteredArgsAreExactForAReadAndAWrite(): void
    // the GET /admin/stats (args, schema callable) and the POST /admin/attendance from "The option, as a consumer writes it": assertSame on the array minus the two closures; assertInstanceOf(Closure) for both; input_schema for the POST is the object with properties session_id/user_id/status (type, description, default copied; no enum) and required ['session_id','user_id']; meta exactly as the Interfaces block says
public function testAPathParameterBecomesARequiredStringUnlessArgsSaysOtherwise(): void
    // GET /admin/editions/(?P<id>\d+): properties.id === ['type' => 'string'], required ['id']; with args.id = ['type' => 'integer'] → ['type' => 'integer']
public function testAnEmptyBufferRegistersNothing(): void
public function testOneCategoryPerSlugAndTheOverrideIsItsOwn(): void
    // two routes in stride/v1, one with category 'stride': categories registered: stride-v1, stride — each once
public function testASchemaThatResolvesToNothingSkipsThatAbilityOnly(): void
    // schema callable returning [] on one of two records: one 'warning' containing 'abilities:schema'; the other ability registered; both routes in the table
public function testTheOutputSchemaOfTheOptionWinsOverTheRoutesSchema(): void
```

- [ ] **Step 2: Run to see them fail** — `vendor/bin/phpunit --filter NtdstRestAbilityTest`: RED on the undefined method.

- [ ] **Step 3: Implement** the Interfaces block in `api/Rest.php`.

- [ ] **Step 4: Run the file, then the gate** — `vendor/bin/phpunit --filter NtdstRestAbilityTest` GREEN (all of Task 2's too); `composer gate` exit 0, quote the summary line and `guard` ok.

- [ ] **Step 5: Commit**

```bash
git add api/Rest.php tests/Unit/NtdstRestAbilityTest.php
git commit -m "rest: the walk — categories and abilities registered from the buffer after rest_get_server()"
```

---

### Task 4: The dispatch — one request, one `rest_do_request()`

**Files:**
- Modify: `api/Rest.php` (replace Task 3's placeholder closures), `tests/bootstrap.php` (one `require_once __DIR__ . '/Support/wp-rest-doubles.php';` after the harness require), `tests/Unit/NtdstRestTest.php:55-79`, `tests/Unit/CoreShapeCluster3FeatureTest.php:105-117`, `tests/Unit/RelationFieldSearchTest.php:126-139`, `tests/Unit/RelationSearchRouteTest.php:107-123` (delete the guarded `WP_REST_Request` and `WP_REST_Response` blocks, now unreachable; the subclasses `Cluster3PickerRequest` and `RelationSearchRequest` stay and must still compile against the shared double; every `WP_Error` double and every other per-file double stays; no assertion changes — `NtdstRestTest`'s header forbids those and none is needed)
- Four pillars of the one new data flow (ability input → synthetic request → `rest_do_request()`): validate = the route's `args` inside the dispatch (`has_valid_params`) and WordPress's `input_schema` check before it (`class-wp-ability.php:519`); sanitize = the route's `args` `sanitize_callback` inside the dispatch; escape = n/a — the return is data the client renders, nothing is output here; authorize = the ability's `permission_callback`, then the route's `guard()` inside the dispatch (T1). Denial test: row 3's first.
- Create: `tests/Support/wp-rest-doubles.php`, `tests/Unit/NtdstRestAbilityExecuteTest.php`

**Interfaces:**
- Consumes: Task 3's `abilityArgs()` call site; WordPress's `WP_REST_Request::__construct($method = '', $route = '', $attributes = [])`, `set_query_params()`, `set_body_params()`, `rest_do_request(WP_REST_Request): WP_REST_Response`, `WP_REST_Response::is_error()` / `as_error()` / `get_data()`.
- Produces:
  - `private static function abilityRequest(array $record, array $input): WP_REST_Request` — method = the FIRST verb of `self::verbs($record['methods'])`; path = `'/' . trim($record['namespace'], '/') . $route` with every `(?P<name>…)` group replaced by `rawurlencode((string) $input[$name])` (a missing value stays `''`, which the server's regex refuses); the remaining input keys go to `set_query_params()` on a read verb and `set_body_params()` on a write. Named groups without nested parentheses — the regex is the builder's; name its limit in one comment.
  - `private static function abilityCallbacks(array $record): array` — `[$execute, $permission]`: `$permission = static function ($input = null) use ($record) { return ($record['permission'])(self::abilityRequest($record, (array) $input)); }` — the resolved permission (a capability closure, the consumer's callable, or one of the two shorthand function names), NEVER `guard()`; `$execute = static function ($input = null) use ($record) { $response = rest_do_request(self::abilityRequest($record, (array) $input)); return $response->is_error() ? $response->as_error() : $response->get_data(); }`.
  - `tests/Support/wp-rest-doubles.php`: `WP_REST_Request` with `__construct(string|array $methodOrParams = 'GET', string $route = '')` (an array is the legacy params shape the four deleted doubles accepted — the two subclasses call `parent::__construct($own)` with one), `get_method(): string`, `get_route()`, `set_url_params()`, `set_query_params()` / `get_query_params()`, `set_body_params()` / `get_body_params()`, `get_json_params()` (the legacy params array, what `NtdstRestTest.php:501` reads), `get_file_params()`, `set_param()`, `get_param(string $k): mixed` (exactly this signature — the two subclasses override it; body, then query, then url, then params), `get_params()` (merged); `WP_REST_Response` with `__construct(mixed $data = null, int $status = 200)`, `get_data()`, `get_status()`, `is_error()` (status ≥ 400), `as_error()` (a `WP_Error` from `data['code']` / `data['message']` with `['status' => $status]`); `WP_Error` as `NtdstRestTest.php:81-89` today. Each class guarded by `class_exists`. The file carries `defined('ABSPATH') || exit;`.

- [ ] **Step 1: Write the failing tests** — `tests/Unit/NtdstRestAbilityExecuteTest.php`, the setUp of Task 2's file plus `Functions\when('rest_do_request')->alias(function (WP_REST_Request $r) { $this->dispatched[] = $r; return $this->nextResponse; })`, the limiter recorders of `NtdstRestLimitsTest.php:45-50` (`get_transient` / `set_transient` into `$this->transients`), `get_current_user_id` → 0, `current_user_can` → an alias recording the capability asked. Each test declares the POST from "The option, as a consumer writes it" with `rate_limit => 300`, mounts, fires both hooks and reads `$execute = $this->registered['stride-v1/mark-attendance']['execute_callback']`, `$permission = …['permission_callback']`. The denial first:

```php
public function testAnUnauthorizedExecutionIsRefusedBeforeTheHandlerRuns(): void
    // current_user_can → false; $permission(['session_id' => 1, 'user_id' => 2]) === false; $this->dispatched === [] ; the route handler ($h, a recording closure) was never called
public function testThePermissionCallbackChargesNothing(): void
    // current_user_can → true; $permission([...]) === true; $this->transients === []; the capability asked was 'stride_manage'
public function testExecuteIsOneDispatchAndReturnsTheResponsesData(): void
    // nextResponse = new WP_REST_Response(['ok' => true], 200); $execute(['session_id' => 7, 'user_id' => 3, 'status' => 'absent']) === ['ok' => true]; count($this->dispatched) === 1; the request: get_method() 'POST', get_route() '/stride/v1/admin/attendance', get_body_params() === the three keys, get_query_params() === []
public function testAnErrorResponseComesBackAsAWpErrorWithTheRoutesStatus(): void
    // nextResponse = new WP_REST_Response(['code' => 'invalid_data', 'message' => 'Ongeldige gegevens.'], 400): result instanceof WP_Error, get_error_code() 'invalid_data', get_error_data()['status'] === 400
public function testAReadSendsItsInputAsQueryParams(): void
    // GET /admin/stats with args fresh: get_method() 'GET', get_query_params() === ['fresh' => '1'], body []
public function testAPathParameterIsEncodedNotInterpolated(): void
    // GET /admin/editions/(?P<id>\d+): input id '12' → route '/stride/v1/admin/editions/12'; input id '12/../x' → '/stride/v1/admin/editions/12%2F..%2Fx' and 'id' is NOT among the query params
public function testAPublicReadIsAnonymousAndReadonly(): void
    // ->get('/prices', $h, ['schema' => …, 'ability' => [...]])->public(): Functions\when('__return_true')->justReturn(true); meta.annotations.readonly true; $permission([]) === true and no capability was asked
public function testTheShorthandPermissionIsCalledAsAFunction(): void
    // a GET with no permission (INTERNAL): Functions\when('is_user_logged_in')->justReturn(false); $permission([]) === false
```

- [ ] **Step 2: Run to see them fail** — `vendor/bin/phpunit --filter NtdstRestAbilityExecuteTest`: RED on `LogicException: Task 4` (and, before the doubles file exists, on the array-constructor `TypeError`).

- [ ] **Step 3: Implement** the doubles file, the bootstrap require, the deletions, `abilityRequest()` and `abilityCallbacks()`.

- [ ] **Step 4: Run the two files, then the gate** — `vendor/bin/phpunit --filter 'NtdstRestAbility'` GREEN; `composer gate` exit 0 (the three files that lost their doubles still green — quote the suite's `OK (… tests, … assertions)`).

- [ ] **Step 5: Commit, then push** (Ruling 3 below: the Stride proof requires the branch through Composer's VCS repository)

```bash
git add api/Rest.php tests/bootstrap.php tests/Support/wp-rest-doubles.php tests/Unit/NtdstRestAbilityExecuteTest.php tests/Unit/NtdstRestTest.php tests/Unit/CoreShapeCluster3FeatureTest.php tests/Unit/RelationFieldSearchTest.php tests/Unit/RelationSearchRouteTest.php
git commit -m "rest: executing an ability is one rest_do_request(); the permission handed to WordPress is the route's, unguarded"
git push -u origin feature/abilities-bridge
```

---

### Task 5: The first working version — one ability on Stride's own DDEV

**Files:** the Stride worktree only (`~/Sites/worktrees/stride-abilities-bridge`, branch `chore/abilities-bridge` from `origin/staging` — Ruling 4).
- Modify there: `web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php:100` (the `/admin/attendance` POST gains `args`, `schema`, `ability` exactly as "The option, as a consumer writes it"; the handler reads `session_id`, `user_id`, `status` at `:905-909`), `composer.json` + `composer.lock`.
- Create there: `.ddev/config.local.yaml` (untracked), a `schema` method on the controller returning the item schema of the handler's response.

**Interfaces:**
- Consumes: the pushed core branch; `netdust/ntdst-core` resolves through Stride's VCS repository (`composer.json:42-44`); `wordpress/mcp-adapter` 0.7.x from Packagist (type `wordpress-plugin` → `web/app/plugins/mcp-adapter`); the adapter's CLI (`scratchpad/mcp-adapter/cli-usage.md`: `wp mcp-adapter serve --user=<login>`, `wp mcp-adapter list`; the meta-tool `mcp-adapter-discover-abilities`).
- Produces: the evidence lines of R1, quoted in the task report; the branch's first commit.

- [ ] **Step 1: The worktree and its DDEV project** — `cd ~/Sites/stride && git fetch origin && git worktree add ~/Sites/worktrees/stride-abilities-bridge -b chore/abilities-bridge origin/staging`; in the worktree write `.ddev/config.local.yaml` with `name: stride-abilities-bridge`; `git status` must not list it (else `echo .ddev/config.local.yaml >> ~/Sites/stride/.git/info/exclude`). `ddev start` there. The database: `cd ~/Sites/stride && ddev start && ddev export-db --file=<scratchpad>/stride.sql.gz` (read-only for the main checkout; Ruling 5), then in the worktree `ddev import-db --file=<scratchpad>/stride.sql.gz` and `ddev composer install`. `ddev wp user list --role=administrator --field=user_login` names the admin to use; `ddev wp user create mcp-reader mcp-reader@example.test --role=subscriber --user_pass=<random>` creates the denied actor.

- [ ] **Step 2: The core branch and the adapter** — `ddev composer require "netdust/ntdst-core:dev-feature/abilities-bridge as 5.5.0" wordpress/mcp-adapter --update-with-dependencies`; `ddev composer show netdust/ntdst-core` says `dev-feature/abilities-bridge`; `ddev wp plugin activate mcp-adapter`.

- [ ] **Step 3: One ability** — edit `EditionAdminController.php:100` as the option example; add the `schema` method. An `args` block added for the ability is the route's own validation from then on, for every client: it must admit what the existing screen sends (cohort.js posts `''` to clear a mark, the handler at `:915-920` maps it to `unmarked`), so `status` carries no enum. The `schema` describes the RESPONSE as `get_data()` returns it — WordPress validates the whole return against it (`class-wp-ability.php:712-731`). `ddev wp eval 'print_r(array_keys(wp_get_abilities()));'` lists `stride-v1/mark-attendance` and still lists the twelve `stride/…` from the registrars (they go in Task 7). RED first: run the `wp eval` BEFORE the edit and quote the list without it.

- [ ] **Step 4: The three flows, by hand, quoted** —
  `discover`: an `initialize` then a `tools/call` of `mcp-adapter-discover-abilities` as JSON-RPC lines on the STDIN of `ddev wp mcp-adapter serve --user=<admin>` (the request shapes are in `cli-usage.md`); the answer names `stride-v1/mark-attendance` with the input schema's three properties.
  `execute-once` (the limiter; this one is a write by the admin, into the proof's own database): `ddev wp option list --search='_transient_ntdst_rest_%' --fields=option_name,option_value` before; `ddev wp eval --user=<admin> 'var_export(wp_get_ability("stride-v1/mark-attendance")->execute(["session_id" => <a real session id>, "user_id" => <the admin id>, "status" => "present"]));'`; the option list after — exactly one key, its count +1, and the handler's own answer printed.
  `denied-write`: the same `wp eval` with `--user=mcp-reader` → a `WP_Error` with code `ability_invalid_permissions`.
  Report the three as run.

- [ ] **Step 5: Commit on the proof branch** (never merged):

```bash
git add composer.json composer.lock web/app/mu-plugins/stride-core/Modules/Edition/Admin/EditionAdminController.php
git commit -m "chore(abilities-bridge): mark-attendance as the shadow of POST /admin/attendance; core 5.5.0 branch and the MCP Adapter"
```

Then message the reviewer: **checkpoint 1, the first working version** — the four quotes above.

---

### Task 6: INV-11, the guard, the docs, the version

**Files:**
- Modify: `ARCHITECTURE-INVARIANTS.md` (after INV-10, before `## Deliberate exceptions`; and the header paragraph `:9-12` gains "INV-11 is abilities-bridge's"), `bin/guard.sh` (a block after the `REMOVED` check, before `METHOD_PINS`), `docs/philosophy.md` §1 (after the paragraph ending "a fork with extra steps."), `docs/architecture/CODE-MAP.md` (a last section), `README.md:37-46` (one sentence) and `:47` (`### 5.5.0 — abilities` ABOVE `### 5.4.0`), `CHANGELOG.md:7` (`## 5.5.0` above `## 5.4.2`), `composer.json:44` (`"dev-main": "5.5.x-dev"`). The version header is Task 2's.

**Interfaces:**
- Produces: the invariant the auditor runs; the gate line that fails on a second door.

- [ ] **Step 1: RED for the guard** — create `services/Abilities.php` with one line `wp_register_ability('x/y', []);` (and the ABSPATH guard), run `composer guard`: it passes today. That is the RED. Delete the file after Step 3's GREEN.

- [ ] **Step 2: Write INV-11** in the doc's shape (INV-7 at `:351-375` is the model):

```
## INV-11 — An ability is the shadow of a route

A WordPress ability exists in this package and its consumers only as the `ability` option of a route declared through `ntdst_rest()`. Executing it dispatches that route through `rest_do_request()`, so the route's permission, its rate limit and its response shaping run once, where they already run. Core registers no ability of its own.

**Convergence point:** `api/Rest.php` — `registerOne()` records the declaration, `mountAbilities()` hooks `wp_abilities_api_categories_init` and `wp_abilities_api_init` once at boot (its reader is `ntdst-core.php`), and the two walks are the only callers of `wp_register_ability_category()` and `wp_register_ability()`. The ability's `permission_callback` is the route's resolved permission, never `guard()` (INV-7: discovery charges nothing).
**Bypass smell:** `wp_register_ability(` or `wp_register_ability_category(` outside `api/Rest.php`; an `execute_callback` that is not a REST dispatch; a consumer class named `*AbilityRegistrar`; a `permission_callback` that is the limiter guard.
**Mechanical check:** `grep -rn "wp_register_ability" --include=*.php . | grep -vE "^(\./)?api/Rest\.php|(^|/)vendor/|(^|/)tests/" | grep -vE "^[^:]+:[0-9]+: *(\*|//|/\*)"` → EMPTY. `bin/guard.sh` runs the same line, so the gate fails on a second door.
**Status:** established 2026-10 (specs/abilities-bridge). Re-run verbatim at `<the commit>`: EMPTY.
```

Also INV-5's named list of its grep hits (`ARCHITECTURE-INVARIANTS.md:196-198`, where `Rest::$limits` is named) gains one clause: `Rest::$abilities` — the declarations recorded before `wp_abilities_api_init` fires; WordPress refuses a registration outside that hook (`abilities-api.php:290-303`), so it keeps no table of a declaration made before it. `$abilitiesMounted` is a `bool` and is not a hit.

- [ ] **Step 3: The guard block** — in `bin/guard.sh`, R5's grep verbatim into `ABILITY_DOORS=$( … || true)`; non-empty → `echo "An ability is registered outside api/Rest.php (INV-11):"`, the hits, `exit 1`. `composer guard` now FAILS on `services/Abilities.php` (quote it), then delete the file: ok.

- [ ] **Step 4: The prose** — `docs/philosophy.md:46-47`: "lists the three options the class consumes" becomes four; then §1, one sentence after "a fork with extra steps.": *An ability is a route's shadow: `ntdst_rest()`'s `ability` option is the one way a route becomes one, and core registers no ability of its own (INV-11).* `CODE-MAP.md`, a section `## An ability is a route's shadow` of four lines: where the record lives, where the walk mounts, that the permission handed to WordPress is the resolved one and the execute is `rest_do_request()`, and that `bin/guard.sh` holds the grep. README `:37-46`: one sentence — *Since 5.5.0 a route may also be a WordPress ability: `'ability' => [...]`, see `### 5.5.0`.* README `### 5.5.0 — abilities`: "Additive. `^5.4` consumers upgrade without a code change." then the option table (`name`, `label`, `description` required; `destructive`, `idempotent` required on a write; `output_schema`, `category` optional), the full name rule, the three exposure spellings written `true` and why there is no `public` option, what executing does (one `rest_do_request()`, the response's data, a `WP_Error` keeps the status; the route's permission is also the ability's `permission_callback`, so a consumer's own callable runs on two request objects per execution — the ability's synthetic one, then the dispatch's memoized one), that `schema` / `output_schema` describes the RESPONSE as `get_data()` returns it (a list route declares `type: array`, `items: …`, or its paginated object — WordPress validates the whole return), the category rule, `input_schema` from `args` and path parameters, and the three notices `abilities:unavailable` (< 6.9, the route registers), `abilities:after-init`, `abilities:schema`. Close with the line the harness already promises: *On core < 5.5.0 the option is unknown and the whole route is not registered.* CHANGELOG `## 5.5.0`: "Additive." and `### Added` with three bullets (the option, the walk, INV-11 + the guard line).

- [ ] **Step 5: The version** — `composer.json` branch-alias `5.5.x-dev` (the header moved to Task 2).

- [ ] **Step 6: `composer gate`** exit 0 — `PackageBootIntegrityTest::testThePackageNeverClaimsToBeOlderThanWhatItShips` and the README sweeps stay green (quote `OK (…)`); re-run INV-11's grep and paste the (empty) output and the commit into the Status line after committing; re-run INV-5's grep verbatim beside it and add the commit to INV-5's Status line, every hit named.

- [ ] **Step 7: Commit**

```bash
git add ARCHITECTURE-INVARIANTS.md bin/guard.sh docs/philosophy.md docs/architecture/CODE-MAP.md README.md CHANGELOG.md composer.json
git commit -m "release: 5.5.0 — abilities are the shadows of routes; INV-11 in the guard"
```

---

### Task 7: The Stride proof — every ability with a route, moved

**Files:** the Stride worktree.
- Delete: `web/app/mu-plugins/stride-core/Modules/Assistant/ReadAbilityRegistrar.php`, `WriteAbilityRegistrar.php`; their two lines in `plugin-config.php:54-55`.
- Modify: the routes in the table below. Read only: `web/app/plugins/ntdst-assistant/src/AbilityBridge.php` — it discovers abilities dynamically (`:47`, filtered on `show_in_rest`) and carries no names in code, so nothing there changes; the registrar-only abilities simply leave its tool list.

**Interfaces:**
- Consumes: Task 5's worktree and DDEV; the registrars' input schemas (`ReadAbilityRegistrar.php:50-300`, `WriteAbilityRegistrar.php:37-120`) as the `args` each route gains; Decision 14.
- Produces: the proof diff, pushed as `chore/abilities-bridge` (Ruling 6); the findings list.

The mapping. The ability's input IS the route's `args`: a route that declares none gains them, named as the handler reads them (the route's names win over the registrar's); an `args` block is the route's validation for every client from then on, so it admits what the existing screens send. Every moved route gains a `schema` callable describing the RESPONSE as `get_data()` returns it — a list route declares `type: array` with `items`, or its paginated object — because WordPress validates the whole return (`class-wp-ability.php:712-731`); the item schema alone answers `ability_invalid_output`.

| Ability | Route | What the branch does |
|---|---|---|
| `mark-attendance` | `POST /admin/attendance` — done in Task 5 | finding F1: the registrar's multi-user form (`user_ids[]`, `WriteAbilityRegistrar.php:73-77`) has no counterpart on this single-user route (the bulk one is `/admin/attendance/bulk`, `EditionAdminController.php:101`) |
| `search-users` | `GET /admin/users/search` (`AdminAPIController.php:383-395`, raw, `canViewAdmin` = `stride_view` at `:477-480`; params `q`, `page`, `per_page`) | re-declared through `ntdst_rest('stride/v1')` with the same path, handler and permission callable (Ruling 7); `args` q/page/per_page; `ability` — the registrar's `query` is `q` here, `per_page` keeps its name |
| `get-editions` | `GET /admin/editions` (`:60-123`, raw; params `page, per_page, search, status, date_from, date_to, theme, format, tag, view, scope`) | re-declared (Ruling 7); `args` from the handler's params — the registrar's `upcoming` is `scope` (`upcoming` / `all`), its date pair is `date_from` / `date_to`; finding F2: the registrar's `course_id` has no route parameter (`theme` / `format` / `tag` are taxonomy ids), and the route's default `view=agenda` answers session-date rows, so the ability's description says `view=list` for editions |
| `get-edition` | `GET /admin/editions/(?P<id>\d+)` (`:159`, raw) | re-declared (Ruling 7); `ability` — `id` from the path |
| `get-stats` | `GET /admin/stats` (`:53`, raw, param `fresh`) | NOT moved — R6 names it as having no route (finding F3: no `ntdst_rest()` route — drift against core's INV-2; the registrar's `edition_id` / `course_id` scopes have no route parameter either) |
| `get-enrollments` | `GET /admin/editions/(?P<id>\d+)/registrations` (`:172`, raw; the handler reads only `id`, `:1318-1320`) | NOT moved — R6 names it (finding F4: no `ntdst_rest()` route; the registrar's `user_id` and `status` filters have no route) |
| `enroll-user` | `POST /admin/bulk/enroll-in-edition` (`BulkRegistrationHandler.php:113`; the handler at `:793-812` reads `edition_id` + `user_ids[]`, resolves them through `Handlers/Support/BulkRunner.php:164` (`resolveBulkIdsCapped()`) and runs the per-row callback at `:816-838` through `runOver()` (`BulkRunner.php:295`)) | moved: `args` `edition_id` (integer, required) + `user_ids` (array of integers, required) — the input key changes from the registrar's `user_id`; `destructive` false, `idempotent` true (a repeat is a skipped `already_enrolled` row, `:836-838`) |
| `unenroll-user` | `POST /admin/bulk/cancel` (`:79`) runs over `ids` = REGISTRATION ids (`:193`); the registrar took `user_id` + `edition_id` and resolved the registration itself (`WriteAbilityRegistrar.php:187`) | NOT moved — finding F5: shape mismatch |
| `export-editions`, `export-enrollments`, `export-attendance` | `GET /admin/editions/(?P<id>\d+)/export/(?P<type>[a-z]+)` (`EditionAdminController.php:113`) is a per-edition download, the registrars return a signed link | findings F6–F8: shape mismatch, not moved |
| `get-attendance` | none | finding F9 |

- [ ] **Step 1: Delete the registrars and their two service lines**; `ddev wp eval 'print_r(array_keys(wp_get_abilities()));'` now lists only `stride-v1/mark-attendance` — the RED for every row below.

- [ ] **Step 2: Apply the table**, row by row, each row's `wp eval` quoted GREEN. The raw-to-framework re-declarations keep `[$this, 'canViewAdmin']` as the permission callable (the same gate, now through the framework's door).

- [ ] **Step 3: ntdst-assistant** — `ddev wp plugin list --status=active` shows it active; its tool list (built from `wp_get_abilities()` at `AbilityBridge.php:45-50`) now carries the `stride-v1/…` names and none of the deleted ones; one assistant round-trip through a moved read (`get-editions`, `view=list`) as the admin, quoted.

- [ ] **Step 4: `wp-abilities-verify`, runtime mode**, with the substitutions `ntdst-framework` SKILL.md:116 names (static inventory = the `'ability' =>` grep over `web/app/mu-plugins/stride-core`; runtime list = `wp_get_abilities()` over `ddev wp` filtered on `stride-v1/`; the mu-plugin status; report path in the scratchpad). Its check 1 must read Source+Runtime for every moved ability; its adversarial annotation check reads each route's handler. Quote the report.

- [ ] **Step 5: The drift reviewer** — `netdust-wp:drift-reviewer` on the worktree's changed paths; quote its `## Findings:` header and every line; check #16 must be empty.

- [ ] **Step 6: Commit and push the proof branch**

```bash
git add -A web/app/mu-plugins/stride-core web/app/plugins/ntdst-assistant
git commit -m "chore(abilities-bridge): the registrars deleted; every ability with a route is its route's ability option"
git push -u origin chore/abilities-bridge
```

The task report carries the findings list F1–F9 with one line each (each a shape mismatch or a missing `ntdst_rest()` route, never a Stride route added) and the two skill reports; it is the review's input (R6).

---

## Close

1. `composer gate` exit 0 after the last edit (core worktree); `bash bin/zero-readers.sh` quoted.
2. Push `feature/abilities-bridge`; message the reviewer (checkpoint 3). The reviewer runs `/feature-review abilities-bridge` from its own checkout — `security-sentinel` (the threat model), `invariant-auditor` (`ARCHITECTURE-INVARIANTS.md`, INV-11 included), `netdust-wp:ntdst-drift-reviewer` — and reads the Stride branch at `~/Sites/worktrees/stride-abilities-bridge` as the proof (R6); findings come back as fix requests, each closed RED→GREEN through the test that owns it.
3. One fix pass; the review kept as `specs/abilities-bridge/review.md`, committed with it; push again.
4. No `/shakeout`: the package has no user-facing surface; the Stride flows are the task reports' evidence, not committed tests (plan 5 writes the package's integration tier).
5. Hand-over: the merge and the tag `v5.5.0` are Stefan's; the Stride branch is never promoted. After the merge: `git worktree remove ~/Sites/worktrees/stride-abilities-bridge` and `ddev delete -Oy stride-abilities-bridge` (asks first — it deletes the proof database).

## Rulings in force (ledger each)

1. Base is `origin/main` `9a593c5` (v5.4.2) — the header's `5.4.1` is a pre-existing lag, fixed by Task 2 — cost: none.
2. Subagent-driven for every task: rows 1–3 are an auth/capability surface, and the plan outlives one context — cost: a fresh context per task.
3. The core branch is pushed at the end of Task 4, before the Close: Stride resolves `netdust/ntdst-core` through a VCS repository, so the proof needs the branch on origin — cost: none (a feature branch is private until promoted).
4. The Stride proof is cut from `origin/staging`, not production: Stride's `main` is 18 commits behind, on WordPress 7.0 and core `^5.2`; the proof is evidence against the Stride that exists and is never merged — cost: none.
5. Starting Stride's main DDEV project to export its database is a read of that tree, not a write; the proof's project is its own (`stride-abilities-bridge`) — cost: a container running on Stefan's machine until `ddev stop stride`.
6. `chore/abilities-bridge` is pushed so the review can read it; never promoted, never merged — cost: one branch on Stride's origin.
7. Three raw `AdminAPIController` GET routes that back abilities (`/admin/users/search`, `/admin/editions`, `/admin/editions/{id}`) are re-declared through `ntdst_rest('stride/v1')` on the proof branch — the same path, handler and permission callable; Stride's raw `register_rest_route` there is drift against core's INV-2 (one HTTP surface, which the drift reviewer applies to Stride; Stride's own INV-2 is its nonce rule), and the spec's bar ("every ability that has a route, moved") cannot be met through a door the option cannot reach. `get-stats` and `get-enrollments` are NOT re-declared: R6 names them as having no route and settles them as findings (Decision 14), and this plan does not widen that. Stefan approved the ruling on 2026-10-30 as "the four reads" (`/admin/stats`, `/admin/editions`, `/admin/editions/{id}`, `/admin/editions/{id}/registrations`); the plan review (round 1, S3) changed that set: two of the four dropped (`/admin/stats` and `/admin/editions/{id}/registrations` — R6 settles `get-stats` and `get-enrollments` as findings) and `/admin/users/search` added (the route that carries the registrar's own capability and page size for `search-users`) — cost: three declarations on a branch that is never merged. **Stefan may still veto this before Task 7**; without it the proof is `mark-attendance` and `enroll-user`, and the three reads become findings.
8. `destructive` / `idempotent` on a read verb are accepted and ignored, not refused: the verb decides a read's annotations, and refusing a harmless surplus would take a route down over a hint — cost: a reader may believe `'destructive' => true` on a GET means something.
9. `refuse()` gains `bool $log = true` so the inert notice is `_doing_it_wrong` alone, as Decision 3 says ("debug only" is WordPress's own `WP_DEBUG` gate on that function); every other refusal keeps its log line — cost: an eighth parameter on `refuse()`.
10. The late-mount refusal reports through `self::forNamespace('ntdst')->refuse(...)` rather than a second `_doing_it_wrong` + log pair, so the file keeps one refusal home (CODE-MAP: a refusal in two homes drifted once) — cost: a handle named `ntdst` in `$instances` on such a request.
