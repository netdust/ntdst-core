# core-trim — implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** ntdst-core 5.0.0 where Bootstrap loads nothing by guessing, every
public symbol has a reader, there is one query API and one logger, `Scheduler`
and `Mailer` are gone from core, and the hook vocabulary has one spelling.

**Architecture:** Four clusters, all deletion-shaped. Cluster A makes Bootstrap
accept only classes PHP can already resolve (D2 — no Composer assumption, no
directory scan, no source parsing), drops the fail-open enable switch, and fixes
the loader order so 20 `function_exists` guards go. Cluster B removes the second
query API and the Logger's database half, and renames the six model hooks.
Cluster C trims Container and Theme to what is read, deletes `Scheduler`, and
deletes `Mailer` from core. Cluster D adapts stride and daan in their own trees,
writes README's migration table, and records INV-9/INV-10.

**Tech Stack:** PHP 8.2+, WordPress 7.0.4 (`~/Sites/daan/web/wp` is the source of
truth for every WP claim), PHPUnit 10 + Brain Monkey (`composer gate`), daan's
DDEV through the existing composer `path` repository branch
(`chore/core-path-repo`, core-shape T01) for the observables.

**Spec:** `specs/core-trim/spec.md` (revision 2) ·
**Invariants:** `ARCHITECTURE-INVARIANTS.md` (INV-9, INV-10 added by T13) ·
**Sibling specs:** `specs/core-shape/` (owns Rest/Actions/Response/Pages/Theme
FR-12), `specs/field-types/` (owns the vocabulary and `MetaboxGenerator`).

**Repos:** CORE = `~/Sites/ntdst-core` (T01–T10, T13). STRIDE = `~/Sites/stride`
(T11, branch `chore/core-trim` off `staging`, never merged by this plan). DAAN =
`~/Sites/daan` (T12, the existing `chore/core-path-repo` branch, never merged).

## Global Constraints

- Target release **v5.0.0**, breaking, unreleased; no alias, shim or forwarder for anything removed (spec D5).
- **Core never assumes Composer** (D2). A task that adds `spl_autoload_register`, `glob()`, `file_get_contents()` of a PHP file, or a path derived from a class name to `core/` is wrong by construction.
- **A public symbol needs a reader** (INV-9, T13). A task that adds a public method, global function or hook nobody in this plan calls is wrong by construction.
- **Where core-shape names a file, core-shape's FR wins**; this plan adds only what its own FRs list (spec header).
- Every CORE task closes with `cd ~/Sites/ntdst-core && composer gate` exit 0 (syntax · phpunit · `bin/guard.sh` · `composer audit`). The task that removes a symbol extends `bin/guard.sh` and `tests/Unit/PackageBootIntegrityTest.php::removedSymbolProvider()` in the same commit.
- Consumer trees are touched only on the named branches; `git add` uses pathspecs, never `-A` (the netdust-agent Stop hook commits anything staged).
- Sites are consulted to **count** readers, never to design (D3).

---

## Stakes

Stakes: high — Cluster A changes how every consumer site's services are loaded
and removes a switch that could keep a service off; a wrong refusal takes a site
down at boot, a wrong acceptance loads code nobody listed. The other clusters
are deletions whose failure is a fatal at load, visible on the first request.

Per-cluster refinement:
- **Cluster A (Bootstrap):** high — code loading and the enable posture.
- **Cluster B (query API, logger, hooks):** standard — a missed rename leaves a daan listener silently inert; tested by name.
- **Cluster C (surface sweep):** standard — removals; stride's mail and reminders are the two behaviours that must survive the move.
- **Cluster D (consumers, docs, invariants):** standard — adapt commits on never-merged branches; README is the fleet's migration record.

---

## Architecture invariants touched

From `ARCHITECTURE-INVARIANTS.md`:

- **INV-1…INV-8** — not touched. T04 removes `Data` methods but keeps `registerRestMeta()` / `restFields()` / `getSchema()` exactly as INV-1 names them; T03's loader reorder keeps `api/FieldTypes.php` before `api/Data.php` (INV-8).
- **INV-9 (new, T13)** — *a public symbol of core has at least one reader outside its own file, in core or in a D4 consumer.* Established by Clusters B and C. Mechanical check: `bin/zero-readers.sh` prints 0 lines.
- **INV-10 (new, T13)** — *core loads nothing by guessing.* Established by T01. Mechanical check: `grep -c "glob(\|file_get_contents(\|preg_match('/^\\\\s*namespace\|spl_autoload_register" core/Bootstrap.php` = 0.

T13 writes both invariants and runs both checks (SC-7).

---

## Spec-premise ground-truth

> Correction (Cluster B T04, 2026-08-23): the reader counts below swept the FLEET only, never core itself — `admin/RelationField.php:133` called `ntdst_get_formatted_posts()` and `services/Logger.php::clearOld()` called `whereDate()`; the provider-row sweep caught both. Treat each "Confirmed" count as fleet-only; grep `api core admin services support` before deleting.

| Premise | Verdict |
|---|---|
| "No site uses `auto_discover`" | **Confirmed.** All five `theme-config.php` / `plugin-config.php` set `'auto_discover' => false`; daan-core, josworld-core and netdust-core load services by explicit `require_once` and say so in their headers; stride and todai use Composer PSR-4. |
| "`ntdst_service_{slug}` option and `_enabled` filter have no reader" | **Confirmed.** 0 hits in consumer code. Readers exist only in `tests/Unit/BootstrapServiceSlugTest.php`. |
| "The config-override filter has no reader" | **Refuted (spec revision 2).** stride `SecurityService:60` and `PerformanceService:39` `apply_filters('ntdst_service_{slug}_config', …)`; stride `theme-config.php:114` declares `overrides.security` and `overrides.performance`. The slug derivation stays for this filter; T02 renames it. |
| "Logger's database handler is off by default" | **Confirmed.** `services/Logger.php:129` gates it on `WP_DEBUG` through `ntdst_log_database_enabled`; the `log_entry` post type is registered unconditionally from the constructor (`:74`). |
| "`Mailer` has one consumer" | **Confirmed.** stride `netdust-mail/src/MailService.php:256–271`: `ntdst_mail()->to()->subject()->message($body, true)`, optional `cc()`/`bcc()`, `attach()` per attachment, `send()`. `from()`/`template()` are not called by it; they are kept because `netdust-mail`'s own templates resolve through its repository, not core's. |
| "`Scheduler` has one consumer" | **Confirmed.** stride `GateReminderService.php:78` `ntdst_schedule_recurring('stride_gate_reminders', 'daily', [$this, 'run'])`. |
| "`Theme::mixin()` serves only the five helpers" | **Confirmed.** `core/Theme.php:95–113` `wireMixins()`; 0 consumer calls to `$theme->data()/pages()/response()/log()/mail()`; core-shape T12's test asserts `$theme->pages()` resolves — T09 re-points that assertion. |
| "`getFormattedPosts()` has 5 readers" | **Confirmed.** daan `ProfileService.php:367` (`include_meta => true`, one post by id); stride `AbstractRepository.php:148,193,196` are docblock mentions, `:222` already uses the chain. So the live readers are daan ×1 and stride docblocks ×3. |
| "josworld reads `getPostMeta()`" | **Refuted.** It calls `NTDST_Data_Manager::getPostMetaFromCache()` (`ntdst-cached-meta-accessor.php:55`), which current core does not define. Spec revision 2: josworld's own migration. |
| "`ntdst_model_*` hooks have one consumer" | **Confirmed.** daan `PressKitService.php:197–198` listens on `ntdst_model_create_after` and `ntdst_model_update_after` with 3 args. |
| "Logger is required after `api/`" | **Confirmed.** `ntdst-core.php:53` (`services/Logger.php`) follows `:37–47` (`api/`, `admin/`). The guards are at `Rest.php:401,1125`, `Data.php:249,268,769,2083,2115`, `MetaboxGenerator.php:315,1924,2092`, `Mailer.php:175,365`, `Theme.php:112`, `Logger.php:486`, plus `Theme.php:97–111` (×5) and `Data.php:2263`. |
| "`Container::make/call/forget/flush/keys` are tests-only" | **Confirmed.** 0 consumer hits; core hits only in `core/Container.php` itself; tests: `grep -l` in T07 lists them. |

---

## First working version

**Task:** T01 — after it, Bootstrap refuses a class nobody loaded and boots a
class loaded by a plain `require_once`:

```bash
cd ~/Sites/daan && git checkout chore/core-path-repo && ddev composer update netdust/ntdst-core
ddev exec wp eval 'var_dump(ntdst_get(\daan\services\musician\ProfileService::class) instanceof \daan\services\musician\ProfileService);'
# → bool(true)   — daan-core has no Composer autoload; its services arrive by require_once
ddev exec wp eval 'new NTDST_Bootstrap(["services" => ["core" => ["Nope\\Missing"]]])->register();' 2>&1 | grep -c "Nope\\\\Missing"
# → 1            — the refusal names the class, nothing is scanned for it
```

Before T01 the second command prints `0`: the class is looked for under
`discovery_paths` and dropped with a debug line.

---

## Constitution check

- **ntdst-core is THE base** — nothing moves *into* a site except `Mailer`, which moves to the one plugin that uses it (spec FR-9, ruled).
- **Wrap, never replace** (`docs/philosophy.md` §1) — `Scheduler` (two WordPress lines), the discovery scanner (the consumer's own `require_once` or Composer), the database log handler (a file) each end with WordPress's or PHP's own mechanism.
- **One name per concept** — `when()` ×2 → 1; hooks `ntdst_` + `ntdst/` → `ntdst/`.
- **Simplicity** — net line count is negative by ≥ 900 (SC-8): Bootstrap (−250), Logger (−200), Mailer (−602), Scheduler (−106), Container (−120), Theme (−90), Data (−230) against a `_doing_it_wrong` in Bootstrap (+10), `bin/zero-readers.sh` (+40), README rows (+40).
- **Sites count, never design** (D3) — stride and daan appear as adapt commits on never-merged branches.

## Phases & review clusters

| Cluster | Phase | Tasks | Stakes | Review tier |
|---|---|---|---|---|
| A — Bootstrap loads nothing by guessing | 1 | T01–T03 | high | FULL |
| B — one query API, one logger, one hook spelling | 2 | T04–T06 | standard | STANDARD |
| C — the surface sweep | 3 | T07–T10 | standard | STANDARD |
| D — consumers, docs, invariants | 4 | T11–T13 | standard | STANDARD |

Order A → B → C → D. A first because it is the only cluster that changes a
behaviour consumers depend on at boot. C's T10 (Mailer out) precedes D's T11
(Mailer into netdust-mail) so the stride branch is written against a core that
no longer ships it. D's T13 runs last because `bin/zero-readers.sh` must see the
final surface.

---

## Interfaces

Names every task must use. An implementer sees only its own task; this block is
how neighbouring tasks agree.

```php
// core/Bootstrap.php — after Cluster A
final class NTDST_Bootstrap {
    public function __construct(array $config);
    public function register(): self;      // T01: each class in services.core/admin/conditional must class_exists(); else _doing_it_wrong('NTDST_Bootstrap::register', "Service class {$class} (services.{$key}) is not loaded — require it or autoload it before register()", '5.0.0') and skip
    public function bootCore(): self;
    public function bootFeatures(): self;
    public function config(?string $key = null, mixed $default = null): mixed;
}
// T02: a service is OFF only when metadata()['enabled'] === false or its conditional's condition is false.
// T02: config overrides reach a service through ONE filter:
//   apply_filters("ntdst/service/{$slug}/config", array $defaults): array     — slug from metadata()['name'] or the class-name derivation (unchanged)
//   an overrides key matching no registered service slug → _doing_it_wrong('NTDST_Bootstrap::register', "services.overrides.{$key} matches no registered service", '5.0.0')

// core/Container.php — after T07: exactly these public members
final class NTDST_Container { public function set(string $id, mixed $concrete = null): self; public function get(string $id): mixed; public function has(string $id): bool; }
function ntdst_container(): NTDST_Container; function ntdst_set(string $id, mixed $concrete = null): NTDST_Container; function ntdst_get(string $id): mixed;

// services/Logger.php — after T05: exactly these public members
final class NTDST_Logger {
    public function __construct(string $channel = 'app');
    public static function flushBatchedLogs(): void;   // shutdown hook
    public function flush(): void;                     // forwards to flushBatchedLogs()
    public function debug(string $m, array $c = []): void; public function info(...); public function warning(...); public function error(...); public function critical(...);
}
function ntdst_log(string $channel = 'app'): NTDST_Logger;

// api/Data.php — after T04: the chain + CRUD + register() + these, nothing else public on the model
//   getSchema(), getMetaPrefix(), restFields(), registerRestMeta()
// T06 hook names (same arguments as today's ntdst_model_* at Data.php:758,778,816,848,1140,1149):
//   ntdst/model/creating ($post_type, $data) · ntdst/model/created ($post_type, $post_id, $data)
//   ntdst/model/updating ($post_type, $id, $data) · ntdst/model/updated ($post_type, $id, $data)
//   ntdst/model/deleting ($post_type, $id) · ntdst/model/deleted ($post_type, $id)

// core/Theme.php — after T09: no __call, no mixin(), no when(), no templatePath(); on()/filter() unchanged

// stride netdust-mail — after T11
namespace Netdust\Mail;
final class Mailer { // src/Mailer.php, PSR-4 autoloaded by the plugin's own composer.json
    public function to(string|array $to): self; public function cc(string|array $cc): self; public function bcc(string|array $bcc): self;
    public function subject(string $s): self; public function from(string $email, string $name = ''): self;
    public function message(string $body, bool $isHtml = true): self; public function template(string $name, array $data = []): self;
    public function attach(string $path): self; public function send(): bool;   // wp_mail() underneath, logs through ntdst_log('mail')
}
// MailService::send() builds `new Mailer()` instead of `ntdst_mail()`.

// bin/zero-readers.sh — T13: prints one line per public symbol of api/ core/ admin/ services/ support/ with 0 readers
//   outside its defining file across core + the D4 consumer roots listed in the script; exit 0 always (INV-9's check is "0 lines").
```

---

## Threat model

Named assets → attacks → mitigations. Reviewers converge on these; a task that
weakens a numbered mitigation is rejected at its gate.

1. **Code loading on every consumer site** — today Bootstrap `require_once`s every `*Service.php` under `discovery_paths` and regex-parses the source; a writable discovery directory is code execution, and a class name in config is turned into a file path and required. *Attack:* a dropped `EvilService.php` in a theme directory, or a config value that resolves to an unexpected file. *Mitigation (T01):* core reads no file and derives no path; `register()` only `class_exists()`-checks names the consumer listed and refuses the rest loudly. The remaining trust boundary is the consumer's own `require_once` list and Composer map, which are the consumer's code. Test: a config naming an unloaded class produces exactly one `_doing_it_wrong` and 0 file reads (`Functions\expect('file_get_contents')->never()`).
2. **A service meant to be OFF boots** — the `ntdst_service_{slug}` option and `_enabled` filter are removed (T02); a consumer that kept a service off through either will find it booting. *Mitigation:* 0 readers were found (ground-truth table); README's row for the removal says what to write instead (`metadata()['enabled'] => false`, or a `conditional` entry). The removal also deletes the fail-open filter philosophy §4 records.
3. **An override silently lost** — renaming `ntdst_service_{slug}_config` → `ntdst/service/{slug}/config` (T02) turns an un-renamed consumer `apply_filters()` into a no-op: stride's `hide_wp_version` etc. would quietly stop applying. *Mitigation:* T02 refuses an `overrides` key that matches no registered service slug (loud at `register()`), T11 renames both stride services, and AF-5 proves the override reaches `SecurityService` on a real request (generator tag absent).
4. **A listener silently inert** — renaming `ntdst_model_*` (T06) makes daan `PressKitService`'s two `add_action` calls inert until renamed. *Mitigation:* T06's test pins the six new names by `Functions\expect('do_action')`; T12 renames daan's listeners; AF-6 proves the prune still fires.
5. **Logger PII sink** — the database handler wrote `REQUEST_URI`, client IP and the context array into post meta on every error. *Mitigation (T05):* removed; the file handler keeps Logger's webroot-location note. No new sink.
6. **Mail payload in the options table** — `Mailer::queue()` serialised recipients, body and attachment paths into cron args. *Mitigation (T10):* removed with the class; `Netdust\Mail\Mailer` (T11) has no `queue()`.
7. **Four sites fatal on update** — every removed symbol is a fatal on first call. *Mitigation:* README's core-trim table (T13, ≥ 30 rows); `PackageBootIntegrityTest` scans shipped files and README for each removed name; stride pins `v3.0.0` and daan `master` pins the VCS lock, so neither takes this until its own migration.

**Explicitly out of scope:** `Rest`/`Actions`/`Response`/`Pages` (core-shape); `support/RateLimiter`, `ClientIp`, `Cidr` (not audited); josworld's migration.

## Acceptance flows

Driven at shake-out on daan's DDEV (`chore/core-path-repo`, re-mirrored) and
stride's DDEV (`chore/core-trim`), through real requests and `wp` commands —
never direct PHP calls to the unit under test. Edges: empty, denied, re-entry,
concurrent, boundary, mid-flow failure.

| # | Flow | Edge | Expected |
|---|---|---|---|
| AF-1 | daan front page + `/wp-admin/post.php?post=<gig>&action=edit` after `ddev composer update` | happy path | 200 both; `debug.log` gains 0 notices/warnings (SC-6) |
| AF-2 | daan `wp eval` resolving `ProfileService` (First working version) | happy path | `bool(true)` — explicit-`require_once` loading still boots |
| AF-3 | daan `wp eval` registering a config with an unloaded class | **denied** | one `_doing_it_wrong` line naming the class and `services.core` in `debug.log`; request completes |
| AF-4 | daan config with `services.discovery_paths` still present | **boundary** | ignored; 0 notices (the key is simply unread, README says so) |
| AF-5 | stride `curl -sI https://stride.ddev.site/ \| grep -i generator` with `overrides.security.hide_wp_version = true` | happy path | 0 lines — the override reached `SecurityService` through `ntdst/service/security/config` |
| AF-5b | stride `theme-config.php` temporarily adds `overrides.typo => []` | **denied** | `_doing_it_wrong` naming `services.overrides.typo`; site still boots |
| AF-6 | daan: create a `press_kit` post through the admin | happy path | `PressKitService::pruneEmptyCollections()` runs (log line at `info`) — the renamed `ntdst/model/created` listener fires |
| AF-7 | daan: save a post with a Logger error forced (`wp eval 'ntdst_log()->error("probe")'`) | happy path | one line in `web/app/logs/app-<date>.log`; `wp post list --post_type=log_entry` → 0 rows, post type unregistered |
| AF-8 | stride `wp cron event list --hook=stride_gate_reminders` | happy path | one `daily` event; `wp cron event run stride_gate_reminders` exits 0 (T11's two WordPress lines) |
| AF-9 | stride: send a template mail with one attachment through `MailService` (`wp eval`), Mailhog inbox | happy path | 1 message, 1 attachment, HTML body |
| AF-10 | same with `to` empty | **empty** | `send()` returns false, one `error` log line, no exception escapes |
| AF-11 | stride: `ntdst_mail()` called from a scratch mu-plugin | **denied/fatal** | `Call to undefined function ntdst_mail()` — the symbol left core, README row names `Netdust\Mail\Mailer` |
| AF-12 | daan theme calls `$theme->pages()` from a scratch mu-plugin | **denied/fatal** | `Call to undefined method NTDST_Theme::pages()` — `__call` is gone; README row says `ntdst_pages()` |
| AF-13 | daan `wp eval` with `WP_DEBUG` off registering an unloaded class | **boundary** | still refused (the refusal is not debug-gated); `_doing_it_wrong` reaches `debug.log` only when logging is on — the service is absent either way |
| AF-14 | two `register()` calls on one Bootstrap | **re-entry** | second returns early (`servicesRegistered`), 0 duplicate `_doing_it_wrong` |

---

## Loop budget

Loop budget: ~16 iterations — 13 tasks plus three expected review-fix rounds.
Not an unattended run: Cluster A is high-stakes and gated. If driven by `/loop`,
cap at one cluster per wake and stop at every `── REVIEW GATE ──`.

---

## Sequencing note

- This plan lands **after `field-types`** on `feat/core-shape`. `ntdst-core.php`'s loader order (T03) keeps `api/FieldTypes.php` before `api/Data.php`.
- **core-shape T09** lists `services/Mailer.php` (template directories to `locate()`). If T10 of this plan lands first, T09 skips that file; if T09 lands first, T10 deletes the file and its test line. Either order is fine; the implementer of whichever runs second reads the other's commit.
- **core-shape T12**'s `ThemeTrimTest` asserts `$theme->pages()` resolves through the mixin. T09 of this plan replaces that assertion with "`NTDST_Theme` has no `__call`". If T12 has not run yet when T09 runs, T09 writes the assertion T12 would have and T12's implementer reads this note.
- T11 (stride) and T12 (daan) are consumer adapts on never-merged branches; their suites are ground-truthed in the task (stride is the legacy Codeception stack — the task says how to run it, the implementer verifies against the repo before trusting it).
- `bin/guard.sh` and `PackageBootIntegrityTest` are extended **in the same task** that removes a symbol (T01, T02, T04, T05, T07, T08, T09, T10), never after.
