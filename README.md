# ntdst-core

NTDST Core Framework — DI container, Bootstrap, routing, Data layer, admin form
layer for WordPress. This is the canonical framework repo: `main` is the
ground truth consumed by every adopter project instead of a per-project
vendored copy that drifts. `bin/zero-readers.sh` sweeps six of them for readers
— daan, josworld, stride, todai-client, netdust and ludoluykx.

## What it is

- `core/` — Foundation (Container, Bootstrap, Theme, Pages)
- `support/` — Primitives with no dependencies (ClientIp, Cidr, RateLimiter)
- `api/` — Request flow (Rest, Data, Response, FieldTypes)
- `admin/` — Admin UI (MetaboxGenerator, RelationField)
- `services/` — Built-in services (Logger)
- `ntdst-core.php` — package-root loader; adopters require it via an explicit
  one-line shim, not a directory scan

## Which service to reach for

| You want | Use |
|---|---|
| a command, or any resource route | `ntdst_rest('ns/v1')->get()` / `->post()` |
| a page | `ntdst_pages()->path()` |
| a download response | `ntdst_download()` — never a hand-rolled CSV block |
| a download too big to hold in memory | stream it yourself, with `NTDST_Response::downloadHeaders()` |
| to count anything per caller | `NTDST_RateLimiter::attempt()` / `::exceeded()` / `::reset()` |
| a route readable cross-origin | the `cors` route option — never a hand-rolled `Access-Control-*` header |

## Branch convention

- Security or bug fixes land on `fix/{name-of-fix}`.
- Features land on `feature/{name}`.
- Both merge to `main` with `--no-ff` so the history shows the branch.

## Commands and resource routes

There is one HTTP surface, and it is `ntdst_rest()`. A command is a `->post()`
route like any other: WordPress checks the `wp_rest` nonce, the route's
`permission` decides who may call it, and the browser reaches it with
`wp.apiFetch`. 5.0.0 deleted the separate same-origin dispatcher that used to
own commands — see `### 5.0.0 — BREAKING` for the migration table.

## Versions

### 4.0.0 — adopting it

Read every line before upgrading. Nothing here is shimmed.

**Symbols that left the package.** Calling one is a fatal, deliberately.

| Gone | Replacement |
|---|---|
| `NTDST_SectorRegistry`, `ntdst_sectors()` | none — the system left core |
| a `sectors` key in service `metadata()` | inert; it gates nothing now |

**Behaviour changes a working consumer can notice.**

1. **The nonce-minting route stopped answering for unregistered actions.** It
   used to hand any logged-in caller a nonce for any string. 5.0.0 deletes the
   route outright — mint your own with `wp_create_nonce('wp_rest')`, which is
   what `wp.apiFetch` already sends.
2. **An unregistered action gets no rate bucket and no 429.** It is refused
   with a bare `false` (401), same as an auth denial.
3. **An anonymous caller is refused BEFORE the limiter runs.** Only reachable
   callers are counted now, so per-IP bucket rows stop appearing for traffic
   that was always going to be refused. If you were reading `ntdst_rate_*`
   transients to measure attack volume, they will look quieter.
4. **A declared `metadata()['name']` now really does pin the service slug.**
   It was documented as doing so and did not. If one of your services declares
   a name whose slug differs from the class-derived one, the key of that
   service's config filter **changes**. Check every service that declares a
   `name`. On 5.0.0 that filter is `ntdst/service/{slug}/config`, and the
   per-service enable switch this note used to point at is gone — the core-trim
   migration section says what replaced it.
5. **Bootstrap derives no path from a class name.** A listed class must be
   loaded or autoloadable before `register()` (see the core-trim migration
   section).
6. **A CORS preflight is charged against a rate-limited route.** `OPTIONS` used
   to cost nothing. It now spends one unit from a bucket of its own
   (`ntdst_rest_pf_*`), so a preflight flood can now return 429. It does **not**
   spend the verb budget your real request needs. **Consumers declare nothing**
   — a route that already sets `rate_limit` gets this; a route that sets none
   is still unthrottled.
7. **`composer lint` is phpcs now, not `php -l`.** If you called it in CI,
   `composer syntax` is the old behaviour. See `docs/gate.md`.

**Not a break, but check it:** the plugin header said `2.4.1` for the whole of
v3. If anything of yours read the version, it was reading the wrong one.

### 5.0.0 — BREAKING

`NTDST_Rest` is rewritten, and `api/FieldTypes.php` is the one field-type
registry. The route option surface changed, so `^4.4` does not resolve to this.
Four things to check:

- **Route options** — `'permission' => 'public'`, `cors`, `before_dispatch` and the `surface()` family all moved.
- **Field types** — thirteen type names are retired, and a retired name is a fatal at `register()`.
- **Silent breaks** — the `ntdst/metabox_saved/{model}` payload, the model constructor's arity, and `int`'s sign.
- **Require order** — `api/FieldTypes.php` loads before `api/Data.php`.

**CORS is site-wide, and declared apart from any route.**

```php
ntdst_rest('shop/v1')->cors(['https://app.example.com']);
```

There is no `'cors' => [...]` route option, no per-route policy table, and no
`corsFor()`. There is no allow-list of ours at all: the origins are ADDED to
WordPress's own `allowed_http_origins`, and every allowed/not-allowed question
is put to `is_allowed_http_origin()`. Two lists is one too many — the one
WordPress reads would drift from the one we check.

**A declared origin applies to REST requests only.** That list is site-wide:
`admin-ajax.php`, `admin-post.php` and the customizer all read it through
`send_origin_headers()`, which grants `Access-Control-Allow-Credentials: true`
to every allowed origin **unconditionally** — whatever you passed for
`$credentials` here. An origin declared for your REST API would otherwise be
able to fetch `admin-ajax.php?action=rest-nonce` with a logged-in visitor's
cookies, read the answer cross-origin, and hold that visitor's `wp_rest` nonce.

So the declaration is scoped to `wp_is_serving_rest_request()`; those three
surfaces keep WordPress's defaults, and a resolver is not consulted there
either. WordPress added that function in 6.5. On an older WordPress it is absent
and the declaration widens nothing — CORS stays closed for every consumer.
ntdst-core 5.0 targets WordPress 7.0, and that floor is the reason.

Credentials belong to the declaration that NAMED the origin, not to the site: a
module that asks for them does not grant them to another module's origin, and a
resolver — which names no origin — never grants them at all. `max_age` is shared
and takes the highest asked for; it is a cache hint, not a permission.

**The decision is a pure function again.** `corsDecision(?string $origin, array
$policy): array` and `corsDecisionFor(?string $origin): ?array` return
`['set' => [...], 'remove' => [...]]`; `sendCors()` is a thin emitter over them.
`$policy` carries no origins — only `credentials` and `max_age`; allowed-ness is
asked of WordPress, so a stale local list cannot grant or refuse anything.
`header()` is invisible to a unit test, so a policy observable only over a
socket is a policy nobody can unit-test — that seam is why this is not a
behaviour you have to take on trust.

**`before_dispatch` is gone; `charge()` replaces it.** A guard that must run
before WordPress decodes the body still belongs in your own `rest_pre_dispatch`
filter — you own the decision to refuse, so you own the hook. What you could
not do before was bill that refusal: budget is spent inside the permission
callback, which a short-circuit never reaches, so refusals were free.

```php
add_filter('rest_pre_dispatch', function ($result, $server, $request) {
    if ($result !== null || $this->allows($request)) {
        return $result;
    }

    ntdst_rest('shop/v1')->charge('/orders', 'POST', $request);

    return new WP_Error('forbidden', '…', ['status' => 403]);
}, 10, 3);
```

`charge()` and `bucket()` are public. `charge()` returns `false` once the budget
is gone, and `true` when the route declared no limit — nothing to spend is not
a refusal.

**`->public()` is the one door to anonymous, and `'public'` is not a permission.**

```php
ntdst_rest('shop/v1')
    ->get('/prices', [$c, 'prices'])->public()   // anonymous
    ->get('/orders', [$c, 'index'])              // internal: 'is_user_logged_in'
    ->post('/orders', [$c, 'store'], ['permission' => 'edit_shop_orders']);
```

`['permission' => 'public']` used to reach the same `'__return_true'` that
`->public()` does. It is REFUSED now: the route does not register, one
`_doing_it_wrong` names `->public()`, and one `error` reaches the `api` log.
One decision with two doors is how a route ends up anonymous without anybody
deciding it — and the second door was a value, so any array built from config,
a constant or a merge could open a route. Anonymity is a mark on the
declaration that only `->public()` can make; no option value reaches it.
`'logged_in'` is unchanged, and every other string is a capability asked
exactly as written — `'Public'` and `' public '` are capabilities nobody holds,
not near misses that get normalised back into an opening.

**Asserting your anonymous surface.** The surface registry and its three
helpers are gone; the core-trim migration section names each one and what to write
instead. WordPress already keeps the register every route lands in, and a second
copy of it can only disagree with the original. Ask the server instead:

```php
$routes = rest_get_server()->get_routes('my/v1');

// The routes that named themselves anonymous.
$anonymous = array_keys(array_filter(
    $routes,
    fn (array $handlers): bool => in_array('__return_true', array_column($handlers, 'permission_callback'), true),
));

// And the ones this snippet CANNOT answer for: every handler whose
// permission_callback is not one of the two literal strings.
$unansweredFor = array_keys(array_filter($routes, fn (array $handlers): bool => (bool) array_filter(
    array_column($handlers, 'permission_callback'),
    fn ($callback): bool => $callback !== 'is_user_logged_in' && $callback !== '__return_true',
)));

// assert $anonymous === [the routes you called ->public() on] in a test,
// and settle every $unansweredFor route by reading what it declared.
```

`get_routes()` maps each route to a LIST of handlers — one per method group —
so both filters read all of them, not the first.

The second list is the honest half. A capability route registers a closure, and
so does a rate-limited route — `->public()` or not, because the limiter has to run —
and a closure is opaque: `fn () => true` and a real gate have the same type.
Never settle a route by the TYPE of its callback. Settle it by reading what the
route declared.

**Retired options no longer take your endpoint away.** An option that never
existed still refuses the route. `cors` and `before_dispatch` are *ignored*,
with one `_doing_it_wrong` naming the replacement, and the route registers. An
author who wrote them when they worked keeps their endpoint.

**Migrating from 4.4.x**

| Was | Now |
|---|---|
| `'permission' => 'public'` | `->public()` on the verb — the string is refused and the route does not register |
| `'cors' => ['https://a.test']` per route | `ntdst_rest($ns)->cors(['https://a.test'])` once |
| `'before_dispatch' => fn($r) => …` | your own `rest_pre_dispatch` filter + `->charge()` |
| `corsFor($route)` | gone — one site policy |
| `corsDecisionFor($route, $origin)` | `corsDecisionFor($origin)` |
| `chargePreflight` | gone — `OPTIONS` is unmetered; bill it yourself with `charge()` if you need to |
| `surface()` / `publicSurface()` / `opaqueSurface()` / `forgetSurface()` | `rest_get_server()->get_routes($ns)` — WordPress's own register |

**Field types — one registry, thirteen names retired.**

`api/FieldTypes.php` is the registry. One entry per name says what cleans a
value, what it publishes, what draws it, whether it may sit in a repeater row,
and how it reads back. Every reader asks the registry. Thirteen names retired in
the merge. A retired name is a fatal at `register()`, and the message names the
one to write instead.

| Retired | Write instead |
|---|---|
| `integer` | `int` |
| `signed_int` | `int` |
| `number` | `int` |
| `double` | `float` |
| `decimal` | `float` |
| `boolean` | `bool` |
| `string` | `text` |
| `longtext` | `textarea` |
| `wysiwyg` | `html` |
| `content` | `html` |
| `datetime` | `date` |
| `person` | `relation` |
| `post_relation` | `relation` |

#### The breaks a rename does not carry

Each one says nothing at runtime. A rename gets you past the fatal and leaves
every one of these in place.

- **`ntdst/metabox_saved/{model}` hands you the POSTED values.** On a Data-model
  save the payload is what was submitted — unslashed and uncleaned. The model
  cleaned what it STORED; the hook sees what was typed. A listener that writes
  this payload anywhere is writing raw input. Read the cleaned value back with
  `getMeta()`.
- **The model constructor takes four positional arguments:**
  `(string $post_type, array $schema = [], string $meta_prefix = '', array $scopes = [])`.
  A subclass that calls `parent::__construct()` with the old five drops its
  scopes, and nothing reports it.
- **Require `api/FieldTypes.php` before `api/Data.php`** if you require core's
  files by hand. `ntdst-core.php` already does. A model resolves every declared
  name when it is CONSTRUCTED, so a missing registry is a fatal at construction
  rather than at first use.
- **`int` keeps its sign.** `absint()` left that path and `int` casts now, so
  `-500` stores as `-500` where it used to store `500`. A discount in cents is a
  negative int, which is why this changed. A numeric string past the platform
  maximum saturates at `PHP_INT_MAX`. A float past it is PHP's undefined cast —
  clamp before you store. A non-scalar stores `0`.
- **A bare-string `html` declaration is cleaned on `updateMeta()` now.**
  `'body' => 'html'` binds like any other declaration. That path used to store
  the value raw.
- **A `textarea` field that holds markup must be declared `html`.**
  `sanitize_textarea_field()` keeps newlines, not tags — it strips every one.
  Check any field you declared `textarea` because it was multi-line, and then
  printed unescaped.
- **`required`, `min`, `max` and `validate` run on `create()` and `update()`
  only.** They are the MODEL's rules, not storage rules. A REST write goes
  through `register_post_meta()` and never reaches them. `updateMeta()` and
  `updateMetaBatch()` take the unregistered-key warning only. On a metabox save
  of a Data model those rules see the RAW posted value: the metabox hands the
  model what was typed, and the model cleans inside `update()`.
- **Known, and not fixed in 5.0.0.** The metabox unslashes the posted values,
  and `update_metadata()` unslashes again inside WordPress. A literal backslash
  typed in the editor loses one level. This is 4.x behaviour too, and the fix is
  its own cycle.

#### Rename in the consumer before you bump core

Do it in two releases:

1. Rename every retired name against the core the site runs today. On 4.x and
   3.x each canonical name above already resolves, so this release changes no
   behaviour.
2. Bump core to 5.0.0.

If you bump first, the site fatals at `init` on the first model that still
declares a retired name.

Rename only what the site DECLARES. A retired name inside a
`register_post_meta()`, a `register_rest_route()` `args` schema, or an ability
schema is WordPress's own JSON-Schema vocabulary — `int` and `bool` are not
JSON-Schema words, and renaming those breaks the schema. On stride that is 94 of
the 95 remaining hits.

A site still on 3.x has one exception, and it is `signed_int`. On 3.x, `int` is
`absint()`, so a field that needs a sign must KEEP `signed_int` until the bump.
5.0 refuses the name. Rename that field to `int` in the same commit that bumps
core, never in the release before it.

Check every plugin, not only the theme and the mu-plugins. A nested repo is
still a consumer, and a rename commit in the parent repo does not reach it.
`stride/web/app/plugins/netdust-lti` is its own git repo: it declares `boolean`
at `src/Shared/LTIDataService.php:284` and `integer` at `:433`. Rename both
before stride's core bump.

**What the registry stores differently.**

| Type | What the entry does now |
|---|---|
| `bool` | `wp_validate_boolean()` on a scalar, and nothing else — the old non-WordPress fallback was dead code. WordPress's word makes only the exact string `"false"` false, so `'no'` and `'off'` store as `true`. A non-scalar is `false`. |
| `float` | Refuses `INF` and `NAN`. Neither is JSON-encodable, and one overflowing post emptied a whole REST response. |
| `date` | Stores `Y-m-d`, read and written on one clock. WordPress forces the process timezone to UTC, so `strtotime()` and `date()` agree. A year outside `0000`–`9999` is refused: `date()` writes five digits there, and the next pass reads them as a different date. Junk stores as `''`, never as text. |
| `text`, `textarea`, `select` | Keep NUL bytes, because `sanitize_text_field()` keeps them. Core adds no rule WordPress does not have. |
| `url` | Keeps a protocol-relative URL (`//cdn.example.com/x.png`). That is `esc_url_raw()`'s answer. |
| `relation`, `gallery` | Drop zeros and re-index. A gap-keyed list serializes as a JSON object, and consumers read it as one. A `relation` scalar that is not an id stores `[]`, never `[0]`. |
| `array` | Accepts the JSON string the metabox textarea posts, as well as an array. |
| every type | The sanitizer is return-typed. A `ntdst/{model}/fields` filter that puts a callback returning `null` into one raises a `TypeError` instead of storing junk. |

**What a read gives back.** A read is a cast or a decode. It is never a second
sanitization and never a lookup. The write side already ran, and a value stored
around this model — by an importer, by WP-CLI, by the site's previous plugin —
is not this model's to rewrite on the way out.

| Type | On read |
|---|---|
| `int`, `float`, `bool` | Cast; the sanitizer IS the cast a read owes. `bool` reads WordPress's word, so a foreign `'no'` or `'off'` reads `true`. Find those before you upgrade: `SELECT post_id, meta_key FROM wp_postmeta WHERE meta_value IN ('no','off')`. |
| `json`, `array` | Come back as stored — decode only. Escape at output. |
| `image`, `file` | Return the stored id, with no lookup. Whether the attachment still exists is the WRITE side's question: an id that resolves to no attachment stores as `0`. An attachment deleted after the write is not noticed on read. |
| `relation`, `gallery` | Read through the same id rule that wrote the list, so a read cannot disagree with a write about what an id is. |
| `repeater` | Reads rows and nothing else. A cell comes back as stored. No cell is re-sanitized, and nothing is unserialized. A row whose only content is `'0'` is kept, because `'0'` is an answer. |
| `text` | A legacy array stored under a `text` key reads `''`. The DECLARED type is what a read owes, and `text` owes a string. |

**What reaches `/wp/v2`.** Two facts a 4.x consumer may have assumed.

`format` on `email` and `url` never reaches the REST schema. A scalar registers
`show_in_rest => true`, and WordPress derives the schema from `type` alone. The
`format` in the entry is advisory. The sanitizer enforces the shape — a schema
`format` would validate stored legacy values and read them back as `null`.

`array` is not published, and never was, so dropping it loses no working data.
Its sanitizer keeps a keyed map, `rest_is_array()` refuses one, and
`WP_REST_Meta_Fields` nulls the value. `json` is not published either, nor is a
repeater that declares no `sub_fields`. Each warns once per model.

**A declared `sanitizer` composes with the registry's; it does not replace it.**
The registry runs first, on the raw input. Your callable then runs on the
registry's output, and its return value is what gets stored.

One guarantee follows: an `html` field is always `wp_kses_post()`'d before your
callable sees it, on a REST write too. Core gives no further guarantee — your
callable may return anything, and what it returns is stored. A callable that
throws fails the request.

An override must be IDEMPOTENT, like the entry it composes on.
`register_post_meta()` runs the composed sanitizer again on every write, so one
that appends or re-encodes grows the stored value each time.

A repeater sub-field may not declare a `sanitizer` at all. It is refused at
`register()`, because nothing ever ran it: the row walk cleans each cell by its
DECLARED TYPE and never looks for a callable. A security declaration that
quietly does nothing is worse than none.

**Four types cannot sit in a repeater row: `html`, `relation`, `gallery` and
`repeater`.** A `sub_fields` declaration that names one is refused at
`register()`. The message names the field and the sub-field.

`repeater` is on the list, so a nested repeater is refused too. A table row
cannot draw a repeater control. Before 5.0 the declaration registered, and the
edit screen then white-screened on first render.

**Bridges read the registry through `NTDST_FieldTypes::get()`.** It is the public
read, and the only one.

```php
$entry = NTDST_FieldTypes::get('html');

$entry->control;   // 'html' — the admin input key, a rendering intent
$entry->cell;      // false  — may not sit in a repeater row
$entry->schema;    // ['type' => 'string'] — the REST leaf shape, or null

NTDST_FieldTypes::names();              // the 17, in declaration order
NTDST_FieldTypes::declaredType($decl);  // the type a declaration NAMES
NTDST_FieldTypes::rowKey($name);        // the key a repeater cell is stored under
```

`get()` throws `InvalidArgumentException` for anything outside the 17, and names
the canonical one when the argument is retired. The entry is readonly. There is
no filter and no registration method: a pluggable registry is one a plugin can
widen with a type whose sanitizer is a no-op.

**`callback` is a render directive, not a type.** A field declared
`'type' => 'callback'` draws itself, and your own code owns what it stores. It
has no entry, and both the render side and the save side step past it.

#### Extension points — published, and read from outside this repository

Core publishes these and keeps them even though `bin/zero-readers.sh` finds no
caller in the package or in the swept consumer roots. That is what a published
extension point IS: the reader is somebody else's code. It may be written after
this release, and it may already exist in a consumer at a commit the sweep is
not looking at — the sweep reads each root's working tree, and nothing else.
Each row says who, so the exemption is a statement and not a shrug. An
INTERFACE the script cannot enumerate at all: for `NTDST_Service_Meta` this
table is the only check there is. INV-9 in `ARCHITECTURE-INVARIANTS.md` points
here, and the sweep refuses to exempt a name this table does not carry.

| Symbol | Who reads it | Kind |
|---|---|---|
| `ntdst/model/registering` | a consumer that adjusts a model's arguments before `register_post_type()` | filter |
| `ntdst/model/creating`, `ntdst/model/created` | daan `PressKitService` (`created`); a consumer that reacts to a row | action |
| `ntdst/model/updating`, `ntdst/model/updated` | daan `PressKitService` (`updated`) | action |
| `ntdst/model/deleting`, `ntdst/model/deleted` | a consumer that cleans up beside a row | action |
| `ntdst/metabox_saved/{model}` | a consumer that reacts to an editor save. It hands you the POSTED values — unslashed and uncleaned. Read the stored value back with `getMeta()` | action |
| `ntdst/service_before_boot/{class}`, `ntdst/service_after_boot/{class}` | a consumer that wraps one named service's boot | action |
| `ntdst/service/{slug}/config` | stride `SecurityService`, `PerformanceService` — the ONE per-service extension key | filter |
| `NTDST_Service_Meta` | the optional service shape. Six implementers in bavi and dozens in netdust-legacy, all outside the swept roots. The script cannot enumerate an interface, so this row is its only check | interface |
| `NTDST_Bootstrap::config()` | reads back the merged config a consumer passed to `register()`. Kept by FR-2 as the one read-back of that array | method |
| `ntdst_container()` | the container accessor. ludoluykx's `FluentCRMIntegrationService` calls it twice (`:328`, `:335`); the rest of its callers are the fleet's test tearDowns and consumer bootstraps, and `tests/` is excluded from the sweep by design | function |
| `ntdst_inline()` | the other half of the terminal response pair; `ntdst_download()` is read and this is not. Documented as a pair, and recorded as a deletion candidate for `core-shape` rather than exempted silently | function |
| `ntdst/core_ready` | stride — `stride-core.php` and `ProfileTypePolicy` hang their own wiring on it | action |
| `ntdst/services_registered` | `netdust-mail`, which registers its own service once core's list is in | action |
| `ntdst/model/registered` | josworld — `functions.php` and `YOOthemeSourcesService` | action |
| `ntdst/trusted_proxies` | a site's config, which names the proxies `NTDST_ClientIp::detect()` may believe. No fleet reader today | filter |

#### Core-trim — what left the package

Core keeps what only a framework can own: an API over WordPress, and the
conventions every site shares. A primitive WordPress already ships is not one —
`wp_schedule_event()` and `wp_mail()` are the whole of what two of the services
below wrapped. Neither is a service the fleet reads from ONE place: it belongs
to that place, and it moves there whole. `Mailer` is the case to read the rule
by — five call sites, but all of them in stride's own plugins, so the class went
to `netdust-mail` and not into core's next release. `docs/philosophy.md` §6 is
the admission test; the rows below are what failed it.

Every symbol 5.0.0 removed is written down in one of two tables. A retired field
TYPE name is a rename and sits in the "Field types" table above; everything else
is here, one row per symbol.
`PackageBootIntegrityTest::testEveryRemovedFiveOhSymbolHasAMigrationRow` reads
this table back off the release's own removal list, so a row cannot quietly go
missing.

**Loading core and your services.** Core never assumes Composer. Load
`ntdst-core.php` as the base mu-plugin, then load your service classes any way
you like — `require_once`, Composer PSR-4, or any autoloader you installed — and
list them in `services`. Core derives no path from a class name: a listed class
that PHP cannot already resolve is refused at `register()` with a
`_doing_it_wrong()` naming the class and the sector, plus an error-level log
line, because `_doing_it_wrong()` is `WP_DEBUG`-gated and a missing service on a
live site would otherwise be silent.

**Bootstrap — loading and the service lifecycle (FR-1, FR-2).**

| Was | Now |
|---|---|
| `discoverServices()`, `discoverServicesInPath()` | `require_once` your service files, or autoload them, and list the class names in `services` |
| `getClassNameFromFile()` | nothing. Core never parses PHP source for a class name |
| `isInConditionalConfig()` | nothing; the conditional sector is read directly |
| `services.auto_discover` | not read. The key may stay in your config; it does nothing |
| `services.discovery_paths` | not read. Same |
| `services.handlers` | a dead key — nothing ever read it. daan declares one; delete it |
| option `ntdst_service_{slug}`, filter `ntdst_service_{slug}_enabled` | `metadata()['enabled'] => false`, or a `services.conditional` entry whose condition returns false. There is no third way. **The retired filter FAILED OPEN**, so a site that kept a service off through it will find that service BOOTING after the upgrade — check every one before you bump |
| `isServiceEnabled()` | nothing to call: the two ways above are read by `register()` |
| `getServiceConfig()` | read your own config in the service: `apply_filters("ntdst/service/{$slug}/config", $defaults)` |
| `getServices()` | nothing. WordPress's own registries answer "what is loaded"; a second one drifts |
| `getBootedServices()` | nothing — same answer |
| `hasService()` | `class_exists()` |
| `isBooted()` | `did_action('ntdst/features_ready')` |
| filter `netdust_{slug}_config`, then `ntdst_service_{slug}_config` | `ntdst/service/{slug}/config`. There is no shim: a listener on either retired spelling is never mounted and never called, and nothing says so |
| slug `admin_u_i` (a `_` before EVERY internal capital) | `admin_ui`. `APIRouterService` is `api_router`, not `a_p_i_router`. A slug with no consecutive capitals is unmoved, and an override keyed on a mangled slug is now REFUSED at `register()` instead of ignored |

**While you straddle the two versions, bridge both names.** Read
`ntdst/service/{slug}/config` first. Fall back to `ntdst_service_{slug}_config`,
the spelling the core you still run fires. A consumer that renames its read
before core is bumped stops receiving its owner's overrides, silently, and
nothing says so. stride's theme bridges until its 5.0 bump.

These refusals are new, and each is louder than the silence it replaces:

| Shape | What happens |
|---|---|
| a `services.overrides` key naming no listed service | refused at `register()` with `_doing_it_wrong` |
| a `services.overrides` key naming a listed service that does not boot | silent, on purpose — the key is legal; the service simply did not run |
| a non-array `services.overrides.{key}` value, or a non-string list entry | refused with a notice |
| a listed service that does not load | `_doing_it_wrong()` (WP_DEBUG only) **and** an error-level log line, so a live site is not silent |
| a service whose `metadata()` returns a non-array | counts as declaring nothing — defaults all the way down |
| a `metadata()['name']` whose slug differs from the class-derived one | the declared name really does pin the slug now, so the key of that service's config filter CHANGES. Check every service that declares a `name` |

**Container (FR-6).**

| Was | Now |
|---|---|
| `ntdst_make()`, `NTDST_Container::make()` | `ntdst_get()`. Constructor autowiring is unchanged |
| `NTDST_Container::call()` and `callableReflections` (its reflection cache) | call the thing yourself |
| `NTDST_Container::forget()`, `flush()`, `keys()` | build a fresh container: `new NTDST_Container()`. **22 test files on the fleet call `forget()` in `tearDown()`** — daan 15, josworld 4, todai-client 1, stride 1 (`NtdstContainerTest`, four removed members), netdust 1 — and each becomes a fresh instance per test |

todai-client and netdust hold one of those files each, and neither has an adapt
commit. That is a decision, not an oversight: both run against their own pinned
core, so the file keeps passing until that repo bumps, and it is adapted then.
`specs/core-trim/spec.md` SC-5 carries the same note.

**Logger (FR-5).** Channels, levels, the batched file handler and the
`error_log` handler stay. `ntdst_log($channel)` and the five level methods are
the API.

| Was | Now |
|---|---|
| the `log_entry` post type and the `database` handler | nothing. Log lines are a file, not content |
| `ensureModelRegistered()` | nothing — it existed for that post type |
| filter `ntdst_log_database_enabled` | nothing |
| `recent()`, `clearOld()` | read the log file |
| `addHandler()`, `removeHandler()` | nothing. Two handlers, both built in |
| `setMinLevel()` | the `ntdst_log_level` config value |
| `setBatchingEnabled()` | nothing; the file handler batches |
| `ntdst_log_debug()`, `ntdst_log_info()`, `ntdst_log_error()` | `ntdst_log('channel')->debug()` / `->info()` / `->error()` |
| hooks `ntdst_log`, `ntdst_log_*` (`ntdst_log_data`, `ntdst_log_audit`) | nothing. A log line is not an event bus |

**The query API (FR-4).** The chain is the one way to read rows.

| Was | Now |
|---|---|
| `ntdst_get_formatted_posts()`, `getFormattedPosts()` | `ntdst_data('post_type')->where(…)->withMeta()->get()` |
| `getPostMeta()` | `->withMeta()` on the chain, or `getMeta()` on a row |
| `getPostTerms()` | `->withTerms()`, or `wp_get_object_terms()` |
| `attachTerms()`, `syncTerms()`, `detachTerms()` | `wp_set_object_terms()` — one call, WordPress's own |
| `whereDate()` | `->where()` with a `date_query`, or `WP_Query` args |
| `orWhere()` | a `meta_query` with `'relation' => 'OR'` |

**Model lifecycle hooks (FR-11).** Same arguments, `ntdst/*` names. There is no
shim: a listener on a retired name is silently inert. josworld's integration
test listens on the retired create-after hook
(`tests/Integration/DataLayerRoundTripTest.php:38`), and daan's `PressKitService`
on two of the renamed ones.

| Was | Now |
|---|---|
| `ntdst_model_create_before` | `ntdst/model/creating` |
| `ntdst_model_create_after` | `ntdst/model/created` |
| `ntdst_model_update_before` | `ntdst/model/updating` |
| `ntdst_model_update_after` | `ntdst/model/updated` |
| `ntdst_model_delete_before` | `ntdst/model/deleting` |
| `ntdst_model_delete_after` | `ntdst/model/deleted` |

**Scheduler (FR-7).** 106 lines over two WordPress functions, one reader.

| Was | Now |
|---|---|
| `NTDST_Scheduler`, `ntdst_scheduler()` | `wp_next_scheduled()` + `wp_schedule_event()` + `add_action()` |
| `ntdst_schedule_recurring($hook, $recurrence)` | `if (!wp_next_scheduled($hook)) { wp_schedule_event(time(), $recurrence, $hook); }` |
| `ntdst_clear_recurring($hook)` | `wp_clear_scheduled_hook($hook)` |

**Theme (FR-8).** `on()` and `filter()` stay — they are the chainable
configuration case philosophy §5 names.

| Was | Now |
|---|---|
| `mixin()`, `__call()` and `wireMixins()` | the global helpers directly: `$theme->data()` is `ntdst_data()`, and so are `pages()`, `response()`, `log()` |
| `$theme->mail()` | gone with `Mailer` — see below |
| `Theme::when()` | run the condition yourself. `Pages::when()` is a different thing (a `template_include` filter) and stays |
| `templatePath()` | `ntdst_response()->locate()` |

**Mail (FR-9).** The class moved into stride's `netdust-mail` plugin as
`Netdust\Mail\Mailer`, trimmed to what `MailService` uses.

| Was | Now |
|---|---|
| `NTDST_Mailer` | `new \Netdust\Mail\Mailer()` — in `netdust-mail`, at or after `f48a8bf` |
| `ntdst_mail()` | the same class, or `wp_mail()` for a plain send |
| `ntdst_send_mail()`, `ntdst_notify()`, `ntdst_wrap_email_in_layout()` | not carried. `wp_mail()` |
| `queue()`, `toArray()`, `header()` | not carried |
| cron hook `ntdst_send_queued_mail` | not carried, and **a pending event survives the upgrade with no listener**: queued mail is dropped silently. `wp_clear_scheduled_hook('ntdst_send_queued_mail')` on upgrade |
| filter `ntdst_wrap_all_emails` | inert. The option may still be set; nothing reads it |
| filter `ntdst_mail_template_paths` | **the one hook `netdust-mail` carries**, under its original name, for ntdst-auth |
| filter `ntdst_mail_attachment_bases` | not carried. Nothing fires it |
| filter `ntdst_email_layout_paths` | not carried. Nothing fires it |
| hook `ntdst_mail_before_send` | not carried. A listener is inert |
| hook `ntdst_mail_sent` | not carried. A listener is inert |
| hooks `ntdst_notification`, `ntdst_notification_*` | not carried. A listener is inert |
| `templates/emails/` | not carried; the inline layout heredoc went with it |

**RelationField (FR-10).**

| Was | Now |
|---|---|
| `NTDST_RelationField::metadata()` | nothing. It is an admin component constructed at load, never a service, and a `metadata()` nothing boots misdescribes the wiring |

**The route surface and the model's own sanitizer table.** These left in the
same 5.0.0, from the sibling specs `core-shape` and `field-types`. They are
here because a fatal does not care which spec removed the name.

| Was | Now |
|---|---|
| `NTDST_Rest::surface()`, `->surface()`, `$surface` | `rest_get_server()->get_routes('my/v1')` — WordPress keeps that register |
| `publicSurface()` | the same call, filtered on the route's `permission_callback` being `'__return_true'` |
| `opaqueSurface()`, `forgetSurface()` | nothing. There is no second register to hide from or forget |
| `NtdstRestSurfaceTest` | assert against `rest_get_server()->get_routes()` |
| `getDefaultSanitizer()` | `NTDST_FieldTypes::get($type)->sanitizer` |
| `sanitizeBoolean()`, `sanitizeDate()`, `sanitizeJson()`, `sanitizeRepeater()`, `sanitizeNestedArray()`, `sanitizeAttachmentId()` | the registry entry's sanitizer. One table, one answer |
| `sanitize_field()` (the metabox's own) | the same registry. The metabox and the model clean a value the same way now |
| `MARKER_ONLY_REQUIRED_TYPES` | nothing |
| `render_repeater_media_cell()` | the `match ($control)` arm in `admin/MetaboxGenerator.php` |
| `restSchemaFor()`, `restSubFields()` | `restFields()`. `schemaFor()` asks the publish question at every depth, and it is private |

A rate-limited public route registers `guard()`'s closure as its
`permission_callback`, not the literal string `'__return_true'`, so the
`publicSurface()` recipe above does not list it — see "Asserting your
anonymous surface" above for the honest way to settle a route.

**The command dispatcher (FR-7).** There is ONE HTTP surface now, and it is
`ntdst_rest()`. The command dispatcher ran a second one: `POST /ntdst/v1/action`
with its own `Origin` check, its own per-action nonce minted at a route of its
own, its own allow-list filter and its own `{success,data}` envelope — every
CSRF decision WordPress already makes, made a second time and differently. The
table names each removed symbol. A command is a `->post()` route like any other; the browser reaches
it with `wp.apiFetch`, which sends the `wp_rest` nonce for you (INV-2, INV-4).

| Was | Now |
|---|---|
| `ntdst_actions()`, `NTDST_Actions` | `ntdst_rest('ns/v1')->post('/thing', $cb, ['permission' => 'edit_others_posts', 'rate_limit' => 30])` |
| `add_filter('ntdst/api_data/{action}', $cb)` | the route's own `callback` |
| `add_filter('ntdst/api/public_actions', …)` | `->public()` chained onto the verb: `ntdst_rest('ns/v1')->get('/thing', $cb, ['rate_limit' => 30])->public()`. There is no options value that opens a route |
| an anonymous WRITE action (`'public' => true` on a `POST`) | `->post('/interest', $cb, ['permission' => fn(): bool => true, 'rate_limit' => 30])`. `->public()` is refused on a write verb, so the callable is YOUR gate and the decision stays in your code |
| `POST /ntdst/v1/get_nonce` | `wp_create_nonce('wp_rest')` — `wp.apiFetch` already sends it |
| `assets/js/ntdst-api.js`, `window.ntdstAPI` | `wp.apiFetch` (`wp-api-fetch` is a WordPress-provided script handle) |
| `ntdst_enqueue_api_client()` | nothing — depend on `wp-api-fetch` instead |
| `add_filter('ntdst/api/allowed_origins', $cb)` | `->cors(['https://example.com'])` on the namespace. CORS is a decision of the route surface now, not a filter beside a dispatcher |
| `ntdst_api_floor_cap()` | the route's own `['permission' => '<capability>']`. There is no floor to fall back to once every route states its gate |
| `add_filter('ntdst/api/rate_limit/{action}', $cb)` | `'rate_limit' => N` in the route's options |
| `add_filter('ntdst/api/rate_window/{action}', $cb)` | `'rate_window' => N` in the route's options, beside the limit |
| `NTDST_Response::apiSuccess()`, `apiError()` | return the array or a `WP_Error`; WordPress builds the body |
| `NTDST_Response::apiSuccessResponse()`, `apiErrorResponse()` | `new WP_REST_Response($data, $status)`, or `WP_Error` |

A route's response shape changes with it: the dispatcher wrapped every answer in
`{success:true,data:{…}}`, and a REST route returns the payload itself. A client
reading `response.data.thing` reads `response.thing` now.

Two things do not survive the rename, and both are silent. THE BUDGET: the
dispatcher metered every action at 30 requests per 60 seconds whether or not
the author asked; `ntdst_rest()` meters nothing unless the route says
`'rate_limit' => N`. The rows above carry the old default forward on purpose —
drop it and the endpoint is unmetered, which no diff will tell you. THE
CAPABILITY: an action that took its floor from `register()`'s `cap_type` read
the capability OFF THE TYPE, so a CPT with remapped capabilities narrowed with
it. A hard-coded `['permission' => 'edit_others_posts']` does not. On such a
type pass the type's own slug —
`['permission' => get_post_type_object('artwork')->cap->edit_others_posts]` —
or a callable that reads it.

**Two behavioural changes no rename carries.** Both used to be silent when the
logger was absent, and both are unconditional now (FR-3):

- a Data model's metabox is registered automatically, and no longer skipped;
- `isDataModel()` answers the question instead of returning `false` early.

**Before you deploy.** Load order first: `ntdst-core.php` is the BASE mu-plugin.
An mu-plugin that constructs `NTDST_Theme` while core is a regular plugin fatals
on 5.0.0 — mu-plugins load first, and core is no longer there by luck. And
stride keeps `v3.0.0` until stride's `chore/core-trim` branch lands and its pin
is bumped: its security and performance services still read the retired
config-filter spelling the section above renames.

### 4.4.2

**A `before_dispatch` refusal now ANSWERS 429 once the budget is gone, not just
charges for it.**

4.4.0 made refusals cost budget. Measured on the consumer immediately after:
25 refused requests did exhaust the bucket — the caller's next legitimate POST
correctly returned 429 — but the refusals themselves kept being served, one WP
boot each, forever. The flood was metered and not stopped, which is the wrong
way round: the attacker was unaffected and the victim was their own legitimate
traffic.

A caller past the limit now gets the same 429 here that `guard()` would give
them a moment later.

### 4.4.1

**Filters are mounted by asking the filter table, not by a static bool.**

`applyCors`, `chargePreflight` and `runBeforeDispatch` each guarded their
`add_filter()` with a `private static bool $xHooked`. That flag says "this
process already mounted it", which is not the same claim as "it is mounted".
Anything that rebuilds `$wp_filter` — most obviously `WP_UnitTestCase`, which
snapshots and restores it around every test — drops the callback while the flag
stays true, so it is never re-added and the control silently stops running.

Production never noticed, because a request mounts once and ends. The place it
bites is a **consumer's integration tier**: the guard is present for test one
and gone from test two onward, and the suite quietly reports whatever a missing
guard produces. It was found the first time a consumer moved its own
pre-dispatch guard onto `before_dispatch` and watched ten tests fail for a
reason that had nothing to do with the change.

`has_filter()` asks the only authority that can answer. No API change.

### 4.4.0

**`before_dispatch` — a route option for a consumer's own pre-dispatch guard,
charged like one.**

Some guards have to run before WordPress decodes the request. A JSON depth
bound is the clear case: `has_valid_params()` runs WP's own default-depth
`json_decode()` before any permission callback, so a depth check in the
permission is a check that arrives after the read it exists to prevent. The
only place earlier is `rest_pre_dispatch`, and until now a consumer had to
filter that hook itself.

Two things then went wrong, and neither was the consumer's fault.

**The refusals were free.** This package spends a route's rate budget inside
`guard()`, the permission wrapper. A filter that short-circuits `dispatch()`
means `permission_callback` never runs — so every rejected request cost the
caller nothing. Measured on a real consumer's public, unauthenticated write
route: **100 rejected requests carrying ~100 MB of body moved the bucket by
zero**, and a legitimate POST straight afterwards still returned 201. The same
hole was closed for preflights in 4.1.0 and left open for every other verb.

**And the consumer could not fix it.** `bucket()` is private and the budget key
is built inline in `guard()`, so charging the right bucket meant hand-copying
the key formula — or opening a second bucket, which every consumer's own
architecture rules forbid. The consumer also had to re-implement route
matching, and that copy was wrong twice in one module: case-sensitively, so
`/NS/V1/THING` skipped the guard while WordPress dispatched it; then by prefix,
so the guard answered on paths its CORS policy did not cover and handed WP's
reflect-any-origin-with-credentials default back to the wire.

```php
ntdst_rest('todai/v1')->post('/submissions', $handler, [
    'permission'      => $permission,
    'rate_limit'      => 20,
    'before_dispatch' => fn($request) => $this->boundTheBody($request),
]);
```

Return `null` to allow, a `WP_Error` to refuse. Core owns the hook, the
priority (6 — one after its own preflight charge, so an OPTIONS request is
still billed to the preflight bucket first), the case-insensitive anchored
route match, and the charge.

**The charge is on refusal only, and that is the design.** An allowed request
goes on to `guard()`, which bills it there; billing here too would charge every
legitimate request twice. A refused request never reaches `guard()`, so this is
the only place it can be billed. Exactly one unit either way, into the one
bucket both paths share — the request's own, not the preflight's, because a
pre-dispatch refusal is the request answered early rather than a preflight.

A non-callable `before_dispatch` refuses the route the way a missing
`permission` does. A guard the author believes is running and isn't is worse
than no guard.

### 4.3.0

**`NTDST_Response::downloadHeaders($length, $filename, $type, $disposition)`** —
the file-response header policy, without the body.

`ntdst_download()` takes the content and echoes it, which is right for a vCard
or an invoice and impossible for a large archive. A caller that streams — a
press kit sending a few hundred megabytes chunked from a handle — now borrows
the policy and emits its own bytes. **Core does not learn to stream; the caller
stops re-deriving the headers.**

That re-derivation is not hypothetical. A consumer arrived independently at
Content-Type, Content-Length and both filename forms, and missed
`X-Content-Type-Options: nosniff` — correct in three headers, wrong in the
fourth, on an archive of user-supplied assets.

`fileHeaders()` now delegates to it, so both download paths cannot drift. Not
breaking: nothing existing changes behaviour.

### 4.2.0

**`NTDST_RateLimiter::exceeded($key, $limit)`** — a read that consumes nothing.

`attempt()` decides and spends in one move, which is right for a request
budget: every question IS a request. A **failure counter** is the other shape —
checked far more often than incremented. A login lockout asks on every attempt
and increments only on a real failure, so asking with `attempt()` would make
the check cause the lockout it is checking for.

This is what 4.0.0's `reset()` was missing: clearing a bucket is no use if
reading one still costs a unit. `attempt()` / `exceeded()` / `reset()` is the
complete set for both shapes — spend, ask, forgive. A limit of `<= 0` is
switched off and can never be exceeded, agreeing with `attempt()`.

### 4.1.0

**`cors` is a route option.** WordPress's own `rest_send_cors_headers()` echoes
**any** `Origin` back and sets `Access-Control-Allow-Credentials: true`, so any
site can read a logged-in visitor's authenticated responses. It also never
sends `Access-Control-Allow-Headers`, which is why a cross-origin JSON POST
fails its preflight out of the box.

```php
ntdst_rest('my/v1')->post('/thing', $handler, [
    'permission' => $permission,
    'cors'       => ['https://app.example.com'],   // exact origins
]);

// or, in full
'cors' => [
    'origins'     => ['https://app.example.com'],  // or fn(string $o): bool
    'headers'     => ['Content-Type', 'X-Tenant'], // default: Content-Type, Authorization, X-WP-Nonce
    'credentials' => true,                         // default FALSE
    'max_age'     => 600,
],
```

Byte-exact match on the full `scheme://host[:port]`. Never a substring, never
case-folded. `Origin: null` — a `file://` page or a sandboxed iframe — is never
allowed, even if a policy lists it. A policy containing `'*'` **refuses the
route** rather than failing closed quietly, the same way a missing `permission`
does. On a non-match core's grant is actively removed, which is the entire
point: leaving it is the vulnerability.

**Opt-in, deliberately** (decided 2026-08-20). A route that declares no `cors`
keeps whatever WordPress does today — core does not suppress WP's default
package-wide, because that would silently break anyone relying on it and would
make an `ntdst_rest()` route behave unlike every other REST route on the site.

The residual, stated plainly: **a route with no `cors` policy is exactly as
exposed as any other WordPress REST route.** WP will echo any `Origin` back to
it with `Access-Control-Allow-Credentials: true`. Core does not make that
worse, and does not fix it unless you ask. Asking is one option key.

### 3.0.0

The routing surface was renamed with no aliases and no shims: `ntdst_rest()`
for resource routes, `ntdst_pages()` for pages, and a same-origin command
dispatcher beside them that 5.0.0 then removed (see `### 5.0.0 — BREAKING`). A
caller of a retired symbol gets a fatal, deliberately, rather than silent
inaction.

## Minimalism rule

Only upstream when a feature is asked for. ntdst-core can't be bloated — it's
a minimal WordPress layer. Provide solid, secure code; features enter only
with a named consumer.

The long form is `docs/philosophy.md`: prefer WordPress and wrap it rather
than replace it, keep the conceptual surface small while the reasons stay
written down, enforce only the facts core owns, and delete abstractions whose
consumers have gone. It carries the six-question admission test anything new
has to pass.
