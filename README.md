# ntdst-core

NTDST Core Framework — DI container, Bootstrap, routing, Data layer, admin form
layer for WordPress. This is the canonical framework repo: `main` is the
ground truth consumed by every adopter project (daan, josworld, and later
Stride) instead of a per-project vendored copy that drifts.

## What it is

- `core/` — Foundation (Container, Bootstrap, Theme, Pages)
- `support/` — Primitives with no dependencies (ClientIp, Cidr, RateLimiter)
- `api/` — Request flow (Actions, Rest, Data, Response)
- `admin/` — Admin UI (MetaboxGenerator, RelationField)
- `services/` — Built-in services (Logger)
- `ntdst-core.php` — package-root loader; adopters require it via an explicit
  one-line shim, not a directory scan

## Which service to reach for

| You want | Use |
|---|---|
| a command, dispatched same-origin | `ntdst_actions()->register()` |
| a resource route | `ntdst_rest('ns/v1')->get()` / `->post()` |
| file bytes | `add_filter('ntdst/api_download/{action}', …)` |
| a page | `ntdst_pages()->path()` |
| a download response | `ntdst_download()` — never a hand-rolled CSV block |
| a download too big to hold in memory | stream it yourself, with `NTDST_Response::downloadHeaders()` |
| to count anything per caller | `NTDST_RateLimiter::attempt()` / `::exceeded()` / `::reset()` |
| a route readable cross-origin | the `cors` route option — never a hand-rolled `Access-Control-*` header |

## Branch convention

- Security or bug fixes land on `fix/{name-of-fix}`.
- Features land on `feature/{name}`.
- Both merge to `main` with `--no-ff` so the history shows the branch.

## Request dispatch filters

Handlers register on same-origin dispatch filters; `NTDST_Actions` owns the
auth gate so a handler never hand-rolls nonce/capability checks.

An action nothing has registered is refused before the gate does any work: it
gets no rate bucket, and `/get_nonce` will not mint a nonce for it. Registered
means listed in `ntdst/api/public_actions`, or having a handler on the dispatch
filter below.

- `ntdst/api_data/{action}` — POST `/action`. Handler returns
  `array|WP_Error`; the dispatcher emits the JSON envelope. Gate: rate limit,
  Origin/CSRF check, per-action nonce, and anonymous callers may reach only
  actions listed in `ntdst/api/public_actions`.
- `ntdst/api_download/{action}` — GET `/download` (since v2.3.0). Handler
  emits a file via `ntdst_response()->download()` / `->inline()` and exits;
  the dispatcher never reads a filename or path from the request. Same gate as
  `/action` **except** no Origin check: a browser `<a href>` download is a
  top-level navigation that carries no `Origin` header, so the per-action
  nonce in the URL is this surface's CSRF gate. A download action is never
  public unless listed in `ntdst/api/public_actions`; a handler that returns
  instead of emitting yields a 500 rather than a blank body.

## Versions

### 4.0.0 — adopting it

Read every line before upgrading. Nothing here is shimmed.

**Symbols that left the package.** Calling one is a fatal, deliberately.

| Gone | Replacement |
|---|---|
| `NTDST_SectorRegistry`, `ntdst_sectors()` | none — the system left core |
| a `sectors` key in service `metadata()` | inert; it gates nothing now |

**Behaviour changes a working consumer can notice.**

1. **`/get_nonce` no longer mints a nonce for an unregistered action.** It used
   to hand any logged-in caller a nonce for any string. Registered means listed
   in `ntdst/api/public_actions`, or having a handler on `ntdst/api_data/` or
   `ntdst/api_download/`. If you used `/get_nonce` as a generic nonce factory
   for your own AJAX, it now returns 401. Mint those with `wp_create_nonce()`
   yourself.
2. **An unregistered action gets no rate bucket and no 429.** It is refused
   with a bare `false` (401), same as an auth denial.
3. **An anonymous caller is refused BEFORE the limiter runs.** Only reachable
   callers are counted now, so per-IP bucket rows stop appearing for traffic
   that was always going to be refused. If you were reading `ntdst_rate_*`
   transients to measure attack volume, they will look quieter.
4. **A declared `metadata()['name']` now really does pin the service slug.**
   It was documented as doing so and did not. If one of your services declares
   a name whose slug differs from the class-derived one, its
   `ntdst_service_{slug}_enabled` filter and `ntdst_service_{slug}` option
   **change key**. Check every service that declares a `name`, and remember the
   `_enabled` filter is a DENY filter that fails OPEN — a stale key means a
   service you meant to switch off boots.
5. **Bootstrap derives no path from a class name.** A listed class must be
   loaded or autoloadable before `register()` (see the core-trim migration
   table).
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

**Asserting your anonymous surface.** `surface()`, `publicSurface()`,
`opaqueSurface()` and `forgetSurface()` are gone. WordPress already keeps the
register every route lands in, and a second copy of it can only disagree with
the original. Ask the server instead:

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

#### Core-trim — what left the package

Core keeps what only a framework can own. A primitive WordPress already ships,
or a service with one consumer on the fleet, belongs to that consumer.

| Was | Now |
| --- | --- |
| `ntdst_mail()` | `new \Netdust\Mail\Mailer()` — the class moved into stride's `netdust-mail` plugin (netdust-mail ≥ the T11 commit). `queue()`, `toArray()`, `header()`, `ntdst_send_mail()`, `ntdst_notify()`, `ntdst_wrap_email_in_layout()` and the `ntdst_mail_*` / `ntdst_email_*` / `ntdst_wrap_all_emails` hooks are not carried; call `wp_mail()` for a plain send. |

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

The routing surface was renamed with no aliases and no shims: `NTDST_Actions` +
`ntdst_actions()->register()` for commands, `ntdst_rest()` for resource routes,
`ntdst_pages()` for pages. A caller of a retired symbol gets a fatal,
deliberately, rather than silent inaction.

## Minimalism rule

Only upstream when a feature is asked for. ntdst-core can't be bloated — it's
a minimal WordPress layer. Provide solid, secure code; features enter only
with a named consumer.

The long form is `docs/philosophy.md`: prefer WordPress and wrap it rather
than replace it, keep the conceptual surface small while the reasons stay
written down, enforce only the facts core owns, and delete abstractions whose
consumers have gone. It carries the six-question admission test anything new
has to pass.
