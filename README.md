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
- `services/` — Built-in services (Logger, Mailer, Scheduler)
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
5. **A namespaced service's file path no longer depends on the active theme.**
   Bootstrap used to strip `basename(get_stylesheet_directory())` from the
   namespace path. It now tries the path as spelled and then with the leading
   segment dropped. Existing theme layouts resolve exactly as before. The one
   difference: if BOTH candidates exist on disk, the unstripped one now wins.
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

`NTDST_Rest` is rewritten. The route option surface changed, so `^4.4` does not
resolve to this.

**CORS is site-wide, and declared apart from any route.**

```php
ntdst_rest('shop/v1')->cors(['https://app.example.com']);
```

There is no `'cors' => [...]` route option, no per-route policy table, and no
`corsFor()`. One allow-list, merged across namespaces.

**The decision is a pure function again.** `corsDecision(?string $origin, array
$policy): array` and `corsDecisionFor(?string $origin): ?array` return
`['set' => [...], 'remove' => [...]]`; `sendCors()` is a thin emitter over them.
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

**Retired options no longer take your endpoint away.** An option that never
existed still refuses the route. `cors` and `before_dispatch` are *ignored*,
with one `_doing_it_wrong` naming the replacement, and the route registers. An
author who wrote them when they worked keeps their endpoint.

**Migrating from 4.4.x**

| Was | Now |
|---|---|
| `'cors' => ['https://a.test']` per route | `ntdst_rest($ns)->cors(['https://a.test'])` once |
| `'before_dispatch' => fn($r) => …` | your own `rest_pre_dispatch` filter + `->charge()` |
| `corsFor($route)` | gone — one site policy |
| `corsDecisionFor($route, $origin)` | `corsDecisionFor($origin)` |
| `chargePreflight` | gone — `OPTIONS` is unmetered; bill it yourself with `charge()` if you need to |

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
