# core-trim — ntdst-core 5.0.0: delete what has no reader, stop redoing what WordPress does

**Status:** spec, revision 0 — written 2026-08-23 from the architecture scan of
2026-08-22 (Stefan present; ruled "yes write spec, don't assume ntdst-core will
always be loaded using composer"). Revision 1 (2026-08-23): the two open questions ruled —
`Theme::on()/filter()` stay, `Mailer` moves to `netdust-mail`. Revision 2 (2026-08-23, planning
ground-truth): `ntdst_service_{slug}_config` has 2 readers (stride `SecurityService:60`,
`PerformanceService:39`) — the slug derivation stays for that filter, renamed `ntdst/service/{slug}/config`;
josworld's accessor reads `getPostMetaFromCache()`, absent from core since before this spec — its adapt is
josworld's own migration. Approved for `writing-plans`.
**Target release:** v5.0.0 (breaking, unreleased — same major as `core-shape` and
`field-types`).
**Relation to the other specs:** after `field-types`. Does not touch `Rest`,
`Actions`, `Response`, `Pages`, the `Theme` trims in core-shape FR-12, or the
vocabulary. Where this spec and core-shape both name a file, core-shape's FR wins
and this one adds only what it lists.
**Invariants:** adds INV-9 (one reader or it goes) and INV-10 (core loads nothing
by guessing). Touches none of INV-1…8.

---

## Intent decisions

| # | Question | Ruling | Source |
|---|---|---|---|
| D1 | The governing rule | **Minimal and solid; an API over WordPress; conventions and ease of use; logical for agents; no duct tape.** | Stefan 2026-08-22: "ntdst-core needs to be minimal and very solid, providing an api over wordpress and adding conventions and ease of use for all projects. the code should be logical and easy to understand for agents. no ducktape code here at all" |
| D2 | Core's loading model | **Core never assumes Composer.** A consumer may load core and its own services by `require_once`, by Composer, or by any autoloader; core accepts a class PHP can already resolve and loads nothing by guessing. | Stefan 2026-08-23: "don't assume ntdst-core will always be loaded using composer" |
| D3 | Scope of the scan | **Everything the two live specs do not own.** `docs/philosophy.md` §6 (the admission test) and §7 (deletion is a feature) are the yardstick; a symbol with no reader in daan, josworld, stride, todai or netdust's own code is a candidate. | Stefan 2026-08-22: "can you scan ntdst-core for other architecture issues like these"; 2026-08-23 "yes write spec" |
| D4 | Which consumers count | **daan, josworld, todai, stride** (+ netdust where it reads core). acerta is dead. | Stefan 2026-08-22: "only daan, josworld, todai, stride matter now" |
| D5 | Breakage | **Consumers are adapted in the same work; no shim, alias or deprecation path.** | Stefan 2026-08-22: "we need to adapt consumers and throw error for new ones that invent"; core-shape D5 "I know the fleet will break, that's ok" |

---

## Context — measured 2026-08-22 against `feat/core-shape` @ `0594f09`

Zero-reader sweep: every public method, global helper and hook of `core/`,
`services/`, `admin/` and the query layer, counted in the sites' own code
(`daan-core`, `josworld-core`, `stride-core`, `netdust-lti`, `netdust-mail`,
`todai-core`, each site's `ntdst-baseline` and own theme), vendor, tests and the
vendored core copies excluded.

### How sites actually load services

| Site | `auto_discover` | Services loaded by |
|---|---|---|
| daan | `false` | explicit `require_once` (`daan-core.php:339` "daan-core has no PSR-4 autoloader") |
| josworld | `false` | explicit `require_once` (`josworld-core.php:34–62`) |
| netdust | `false` | explicit `require_once` (`netdust-core.php:12` "does NOT use … auto-discovery / Bootstrap machinery") |
| stride | `false` | Composer PSR-4 (`composer.json` autoload) |
| todai | `false` | Composer PSR-4 |

Bootstrap's discovery scanner (`core/Bootstrap.php:443` glob `*Service.php` →
`require_once` → **regex-parse the PHP source for `namespace`/`class`**,
:507–512) and its path-guessing loader in `registerService()` (:~175) have **0
users**. The D2 path — explicit `require_once` — is what three of five sites
already write.

### Public surface with zero readers

| File | Symbols (0 readers in consumers and in core outside the defining file) |
|---|---|
| `core/Bootstrap.php` | `getServiceConfig()`, `getServices()`, `getBootedServices()`, `hasService()`, `isBooted()`; option `ntdst_service_{slug}` + filter `ntdst_service_{slug}_enabled` (fails open — philosophy §4's stated wart); `services.auto_discover`, `services.discovery_paths` |
| `core/Container.php` | `make()`, `call()` (+ its reflection cache), `forget()`, `flush()`, `keys()`, `ntdst_make()` — tests only. `set/get/has` carry the package: 451 `ntdst_get(` + 32 `ntdst_set(` |
| `services/Logger.php` | `addHandler()`, `removeHandler()`, `setMinLevel()`, `setBatchingEnabled()`, `recent()`, `clearOld()`, `ntdst_log_debug/info/error()`, filter `ntdst_log_database_enabled`, the `database` handler and the `log_entry` post type it registers from the constructor (:74) |
| `services/Mailer.php` | `queue()`, `toArray()`, `header()`, `ntdst_send_mail()`, `ntdst_notify()` + `ntdst_notification{,_*}`, `ntdst_wrap_email_in_layout()` + inline HTML heredoc, filters `ntdst_mail_template_paths`, `ntdst_mail_attachment_bases`, `ntdst_email_layout_paths`, `ntdst_wrap_all_emails`, hooks `ntdst_mail_before_send`, `ntdst_mail_sent`. One consumer in total: stride `netdust-mail` (`ntdst_mail()` ×1, `->attach()` ×1) |
| `services/Scheduler.php` | whole file: 106 lines over `wp_next_scheduled()` + `wp_schedule_event()` + `add_action()`; 1 reader (`stride GateReminderService`, `ntdst_schedule_recurring`), `nextRun/hooks/isScheduled/ntdst_clear_recurring` 0 |
| `core/Theme.php` | `mixin()`, `__call()` (serve only `$theme->data()/pages()/response()/log()/mail()`, :104–118), `when()`, `templatePath()` |
| `api/Data.php` | `getFormattedPosts()` + `ntdst_get_formatted_posts()` (123 lines, a second query API; 5 readers: daan `ProfileService` ×2, stride `AbstractRepository` ×3), statics `getPostMeta()` (1 reader, josworld) / `getPostTerms()` (0), `attachTerms()`, `syncTerms()`, `detachTerms()`, `whereDate()`, `orWhere()` |
| `admin/RelationField.php` | `metadata()` — declares a service Bootstrap never boots; the class is `new`ed at require time (:446) |

### Load-order duct tape

`ntdst-core.php` requires `services/Logger.php` **after** `api/`, so `Data`
(×5), `Rest` (×2), `MetaboxGenerator` (×3), `Mailer` (×2), `Theme` (×1) and
`Logger` itself carry **14 `function_exists('ntdst_log')` guards**. `Theme`
guards `ntdst_data/pages/response/log/mail` the same way; `Data.php:2263` guards
`ntdst_metabox`.

### Two names, two conventions

- `Theme::when()` (:353, run the callback now if the condition holds) and
  `Pages::when()` (:235, a `template_include` filter): one word, two meanings.
- Hooks: `ntdst/…` (Bootstrap lifecycle, `ntdst/model/registered`,
  `ntdst/metabox_saved/*`) and `ntdst_…` (`ntdst_model_{create,update,delete}_{before,after}`,
  `ntdst_mail_*`, `ntdst_notification`, `ntdst_log`, `ntdst_service_*`).
  Bootstrap's own header says actions are `ntdst/*`. Consumer readers of an
  `ntdst_` form: daan `PressKitService` (`ntdst_model_create_*`, 2).

---

## Functional requirements

### Phase 1 — Bootstrap loads nothing by guessing

- **FR-1:** `NTDST_Bootstrap` loses `discoverServices()`, `discoverServicesInPath()`, `getClassNameFromFile()`, `isInConditionalConfig()` and the path-guessing branch of `registerService()`; the config keys `services.auto_discover` and `services.discovery_paths` are not read. `registerService($class)` requires `class_exists($class)` — loaded by the consumer's `require_once`, by Composer, or by any autoloader the consumer installed — and refuses a class PHP cannot resolve with one `_doing_it_wrong` naming the class and the config key it came from. Core installs no autoloader and reads no source file. Establishes INV-10.
  Source: D2 — Stefan 2026-08-23 "don't assume ntdst-core will always be loaded using composer"; `## Context` (0 users of discovery; 3 of 5 sites already require explicitly)
- **FR-2:** The per-service enable switch — option `ntdst_service_{slug}`, filter `ntdst_service_{slug}_enabled`, `isServiceEnabled()`'s option and filter branches — is removed. A service is off when `metadata()['enabled'] === false` or when its `conditional` entry's condition returns false; there is no third way. The fail-open wart named in `docs/philosophy.md` §4 is gone with it. `services.overrides` stays (revision 2: stride's `SecurityService` and `PerformanceService` read it); its filter is renamed `ntdst/service/{slug}/config` and `getServiceSlug()` / `declaredServiceName()` stay as that filter's one reader of a slug. An `overrides` key that matches no registered service's slug is refused with `_doing_it_wrong` at `register()` — today it is silently inert. `getServiceConfig()`, `getServices()`, `getBootedServices()`, `hasService()`, `isBooted()` are removed. `register()`, `bootCore()`, `bootFeatures()`, `config()` and the three lifecycle hooks stay as they are.
  Source: D1, D3 — philosophy §4 ("Anything new that gates on a filter should fail closed"), §7; `## Context` (0 readers)
- **FR-3:** `ntdst-core.php` requires `services/Logger.php` immediately after `core/Container.php`, before `api/`; the 14 `function_exists('ntdst_log')` guards, `Theme`'s five `function_exists` guards around its helpers and `Data.php:2263`'s `ntdst_metabox` guard are deleted. A missing function is a fatal at boot, which is the correct time to learn core is half-loaded.
  Source: D1 — "no ducktape code"; `## Context` (load-order duct tape)

### Phase 2 — one query API, one logger

- **FR-4:** `NTDST_Data_Manager::getFormattedPosts()`, `ntdst_get_formatted_posts()`, `getPostMeta()`, `getPostTerms()` are removed; the chain (`->withMeta()->withTerms()->get()`) is the one way to read rows. daan `ProfileService` and stride `AbstractRepository` are rewritten to the chain. josworld's `ntdst-cached-meta-accessor.php` calls `getPostMetaFromCache()`, which current core no longer defines (revision 2) — josworld's own 5.0.0 migration rewrites it. `attachTerms()`, `syncTerms()`, `detachTerms()`, `whereDate()`, `orWhere()` are removed (0 readers; `wp_set_object_terms()` is one call). `Data`'s public surface after: `register()`, `registerTaxonomy()`, `get()`, `isRegistered()`, `addScope()`, the chain, CRUD, `getSchema()`, `getMetaPrefix()`, `restFields()`, `registerRestMeta()`.
  Source: D1 — "data would be orm, nothing else" (field-types D1); `## Context` (5 readers of the second API, 0 of the term helpers)
- **FR-5:** `NTDST_Logger` keeps channels, levels, the file handler (batched, `WP_CONTENT_DIR/logs`) and the `error_log` handler. It loses the `database` handler, `ensureModelRegistered()` and the `log_entry` post type, `recent()`, `clearOld()`, `addHandler()`, `removeHandler()`, `setMinLevel()`, `setBatchingEnabled()`, the filter `ntdst_log_database_enabled`, the hooks `ntdst_log` / `ntdst_log_*`, and `ntdst_log_debug/info/error()`. `ntdst_log($channel)` and the five level methods are the API.
  Source: D1, D3 — philosophy §6.1 (a named consumer is the minimum; none); `## Context`

### Phase 3 — the surface sweep

- **FR-6:** `NTDST_Container` keeps `set()`, `get()`, `has()`, `ntdst_container()`, `ntdst_set()`, `ntdst_get()` and constructor autowiring. `make()`, `call()`, the callable-reflection cache, `forget()`, `flush()`, `keys()` and `ntdst_make()` are removed. Tests that used `flush()` to re-resolve rebuild the container instead.
  Source: D3 — `## Context` (tests-only readers)
- **FR-7:** `NTDST_Scheduler`, `ntdst_scheduler()`, `ntdst_schedule_recurring()`, `ntdst_clear_recurring()` and `services/Scheduler.php` are removed. stride `GateReminderService` writes the two WordPress lines. README's 5.0.0 section shows them.
  Source: D1 — philosophy §1 ("If WordPress already does something well, core does not do it again"); `## Context` (1 reader)
- **FR-8:** `NTDST_Theme` loses `mixin()`, `__call()`, the `$mixins` property, the five helper registrations at :104–118, `when()` and `templatePath()`. A theme that wrote `$theme->data()` writes `ntdst_data()`. `on()` and `filter()` stay: 21 consumer readers, and they are the chainable-configuration case philosophy §5 names (ruled 2026-08-23). core-shape FR-12's trims apply in addition.
  Source: D1 — "logical and easy to understand for agents" (a `__call` surface cannot be read); `## Context`
- **FR-9:** `services/Mailer.php`, `NTDST_Mailer`, `ntdst_mail()`, `ntdst_send_mail()`, `ntdst_notify()`, `ntdst_wrap_email_in_layout()`, `templates/emails/` and the `ntdst_send_queued_mail` cron hook are removed from core. The class moves into stride's `netdust-mail` plugin (`web/app/plugins/netdust-mail/src/`) as `Netdust\Mail\Mailer`, trimmed there to the members `MailService` uses (`to()`, `cc()`, `bcc()`, `subject()`, `from()`, `message()`, `template()`, `attach()`, `send()`); `queue()`, `toArray()`, `header()`, the notification helpers, the inline layout heredoc, the four `ntdst_mail_*`/`ntdst_email_*` filters and the two `ntdst_mail_*` hooks are not carried. `Theme` no longer registers a `mail` helper (FR-8). core-shape FR-10's `Mailer` sentence (template directories to `locate()`) is void.
  Source: D3 — ruled 2026-08-23 (Stefan: "I follow" on the move); philosophy §6.1 (one consumer); `## Context`
- **FR-10:** `NTDST_RelationField::metadata()` is removed; the class is an admin component constructed at load (`:446`), not a service. `NTDST_MetaboxGenerator::instance()` stays (it holds the registered models) and `Data.php` calls it without a guard (FR-3).
  Source: D1 — a `metadata()` nothing boots misdescribes the wiring

### Phase 4 — names, docs, invariants

- **FR-11:** The six `ntdst_model_{create,update,delete}_{before,after}` actions become `ntdst/model/{creating,created,updating,updated,deleting,deleted}` with the same arguments; daan `PressKitService` is renamed with them. `ntdst_service_{slug}_config` becomes `ntdst/service/{slug}/config` (revision 2); stride's two services are renamed with it. After FR-5/FR-9 no `ntdst_`-prefixed hook remains in core; the `ntdst/*` convention in Bootstrap's header is the only one.
  Source: D1 — one consistent term per concept; `## Context` (two conventions)
- **FR-12:** README's 5.0.0 section gains a "core-trim" migration table (removed symbol → what to write instead, one row each) covering every removal in FR-1…11, and a two-line "How to load core and your services" paragraph stating D2. `docs/philosophy.md` §4 drops the fail-open wart paragraph (it is fixed) and §1 cites FR-1. `ARCHITECTURE-INVARIANTS.md` gains INV-9 — *"A public symbol of core has at least one reader outside its own file, in core or in a D4 consumer; the zero-reader sweep is the mechanical check"* — and INV-10 — *"Core loads nothing by guessing: no directory scan, no source parsing, no path derived from a class name; a class is resolvable before `register()` sees it"* with check `grep -c "glob(\|file_get_contents(\|preg_match('/^\\\\s*namespace" core/Bootstrap.php` = 0.
  Source: the README-migration-table and invariants-status pattern core-shape established; D2

---

## Success criteria

- **SC-1:** `grep -c "glob(\|file_get_contents(\|preg_match('/^\\\\s*namespace\|auto_discover\|discovery_paths\|ntdst_service_" core/Bootstrap.php` = 0; under Brain Monkey, `new NTDST_Bootstrap(['services' => ['core' => ['Nope\\Missing']]])->register()` produces exactly 1 `_doing_it_wrong` naming `Nope\Missing` and registers 0 services; a class loaded by a plain `require_once` in the test (no Composer) registers and boots — 1 assertion each.
- **SC-2:** `grep -c "function_exists('ntdst_" api/*.php core/*.php admin/*.php services/*.php` = 0; `grep -n "require_once.*Logger" ntdst-core.php` appears on a lower line number than `require_once.*api/Data`. *(amended at the Cluster A gate, 2026-08-23, pending Stefan's review at the README read: the literal `grep -c … = 0` is unreachable — ≈11 definition wrappers survive the merge by FR-6 and the Response/Pages helpers; the criterion is `bin/guard.sh`'s shape assertion: no CALL-SITE `function_exists('ntdst_…')` guard remains in `api core admin services support ntdst-core.php`, and `services/Logger.php` is required before every file whose load-time code calls `ntdst_log()` — pinned by `BootstrapLoadsNothingByGuessingTest`.)*
- **SC-3:** `ReflectionClass` public-method counts at the merge commit: `NTDST_Container` = 4 (`__construct`, `set`, `get`, `has`); `NTDST_Logger` = 7 (`__construct`, `flushBatchedLogs`, `flush`, `debug`, `info`, `warning`, `error`, `critical` minus whichever the plan folds — the plan pins the exact list); `NTDST_Bootstrap` = 5 (`__construct`, `register`, `bootCore`, `bootFeatures`, `config`); `NTDST_Theme` has no `__call`; `services/Scheduler.php` does not exist; `grep -c "function getFormattedPosts\|function getPostTerms\|function attachTerms\|function syncTerms\|function detachTerms\|function whereDate\|function orWhere" api/Data.php` = 0.
- **SC-4:** `grep -rn "do_action('ntdst_\|apply_filters('ntdst_\|do_action(\"ntdst_\|apply_filters(\"ntdst_" api core admin services` = 0 lines; `grep -c "ntdst/model/" api/Data.php` ≥ 8 (registering, registered, 6 CRUD).
- **SC-5:** `composer gate` exits 0 in core and, after their adapt commits, each consumer's own suite exits 0: daan (ProfileService, PressKitService), stride (AbstractRepository, GateReminderService, SecurityService, PerformanceService, netdust-mail), todai (nothing to adapt) — 4 green suites, 0 files changed outside the named consumers' files. josworld is its own migration (revision 2). *(amended at the Cluster D gate, 2026-08-23, pending Stefan's review at the README read: "todai (nothing to adapt)" was false — todai-client and netdust each carry one Container test file calling `forget()`; both are documented-not-adapted in README's Container row, like josworld under revision 2.)*
- **SC-6:** On daan's DDEV after the path-repo update: the site boots with 0 PHP notices in `debug.log` over 1 front-page + 1 admin-edit request, and `wp eval 'var_dump(ntdst_get(\daan\services\musician\ProfileService::class) instanceof \daan\services\musician\ProfileService);'` prints `bool(true)` — the explicit-`require_once` loading path (no Composer autoload for daan-core) still boots every service.
- **SC-7:** README's core-trim migration table has ≥ 30 rows; INV-9's sweep script (committed as `bin/zero-readers.sh`) prints 0 symbols at the merge commit; INV-10's check = 0.
- **SC-8:** Lines removed from `core/ services/ api/Data.php` ≥ 900 (`git diff --stat` at the merge commit), 0 new public symbols added (ReflectionClass diff against the pre-merge commit).

---

## Security-relevant surfaces

The plan owes a `## Threat model`.

- [x] **Service loading** — today Bootstrap `require_once`s every `*Service.php` under a configured path and regex-parses source; a writable discovery path is code execution. FR-1 removes the scan. The plan names the remaining trust boundary: the consumer's own `require_once` list, which is the consumer's code.
- [x] **The fail-open enable filter** — removed (FR-2); the plan records that a consumer relying on `ntdst_service_{slug}` options to keep a service OFF will find it booting, and FR-12's README row says so.
- [x] **Logger** — the `database` handler wrote `$_SERVER['REQUEST_URI']`, client IP and context into post meta; removing it removes a PII sink. The file handler's webroot location note in Logger's header stays.
- [x] **Mailer `queue()`** — serialised a whole mail (recipients, body, attachment paths) into cron args in the options table; removed either way (FR-9).
- [ ] None of the above

## User-facing surfaces

The plan owes `## Acceptance flows`.

- [x] **Every consumer site boots** — any call into a removed symbol fatals at load; FR-12's table and SC-5/SC-6 are the proof per site.
- [x] **stride reminders** (`GateReminderService`) keep firing on their schedule after FR-7.
- [x] **stride mail** (`netdust-mail`) keeps sending with attachments after FR-9.
- [ ] None of the above

---

## Assumptions

- A consumer's services are resolvable before `NTDST_Bootstrap::register()` runs, by whatever loader the consumer chose (D2). Verified for all five sites in `## Context`.
- `Theme::on()/filter()`'s 21 readers are all in josworld and daan themes; counted 2026-08-22, re-counted in the plan.
- stride's `SecurityService` and `PerformanceService` are the only readers of the config-override filter (counted 2026-08-23).
- 5.0.0 is unreleased; no shim is owed (core-shape assumption, restated).
- Core's suite stays Brain Monkey unit-only; SC-6 is the shake-out's.

---

## Out of scope

- `Rest`, `Actions`, `Response`, `Pages`, core-shape's `Theme` trims, the vocabulary (`field-types`).
- Splitting `NTDST_Data_Model` into chain / CRUD / registration files — still parked.
- `support/RateLimiter.php`, `ClientIp.php`, `Cidr.php` — read by `Rest` and `Logger`; not audited here.
- `MetaboxGenerator` beyond FR-10 (field-types owns its renderers and save path).
- A service-discovery replacement of any kind (D2: the consumer loads; core resolves).
- netdust's `netdust-core.php` projection helper — superseded by core-shape's `register_post_meta` path in its own migration.
