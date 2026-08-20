# Findings against ntdst-core v3.0.0 — from the todai-client intake port

**Provenance.** 2026-08-20, porting `todai-client` from ntdst-core v2.4.1 to v3.0.0 and
relocating its public form-intake endpoint onto `ntdst_rest()`. Every item below was found
by a FULL review panel (`reviewer`, `security-sentinel`, `code-simplicity-reviewer`,
`invariant-auditor`, `ntdst-drift-reviewer`) over two rounds, or by driving the live route
with curl. **Line numbers are v3.0.0 / `3fa448e` and will rot — the behaviour is named so
you can re-find it.**

This is intake material, not a spec. Stefan's ruling 2026-08-20: **defects first as one
release; the options/settings service gets its own spec** (§3), because it changes how every
consumer reads config.

Nothing here was fixed in ntdst-core. `todai-client`'s INV-1 forbids editing package code in
a consumer tree, so all of it was deferred to this repo deliberately.

---

## 1. The one that matters — live on 11 sites

### F1. The rate-limit bucket is keyed on attacker-chosen input · Class D, stakes high

`api/Actions.php` — `rateBucketKey($action)` folds `$action` into the transient key, and
`checkRateLimit()` runs from `permission_callback`, i.e. **before** the action is validated.
Varying one request parameter yields a fresh bucket every time, so the site's only API
throttle is defeated and each request writes 2 `wp_options` rows with only a daily cron to
reap them.

Verbatim at v2.3.2 **and still verbatim at v3.0.0** — re-checked during the port, not
assumed. It does not reach todai (which registers no Actions route), which is exactly why it
survived: the consumer that found it is not the consumer that suffers it.

**Smaller than it looks.** `Actions.php` already owns the "is this a real action" test in two
forms: the `$public_actions` list / `ntdst/api/public_actions` filter, and
`has_filter("ntdst/api_data/{$action}")`. The fix is to establish registration **before**
building the key, then key on the registered identity rather than the raw parameter.

Do not fix by hashing harder. An unregistered action should not get a bucket at all.

---

## 2. Small defects — one release together

### F2. v3.0.0 ships the wrong version in its plugin header · one line
`ntdst-core.php` header says `Version: 2.4.1` while the code self-identifies as `3.0.0`
(`api/Rest.php`, the `_doing_it_wrong()` call). WP reports the wrong version and **no
consumer can check what it actually has** — which matters more than it sounds, because the
whole v3 rename is a hard break with no `class_alias` shims.

### F3. `NTDST_RateLimiter` has no reset · small, and it unblocks a convergence
`support/RateLimiter.php` is 164 lines with exactly **one** public method, `attempt()`.
There is no way to clear a bucket. That is why `ntdst-baseline`'s login lockout cannot
converge onto the shared primitive: `SecurityService::loginSuccessful()` does
`delete_transient(...)` — **reset-on-success**, a verb the limiter does not expose.

Ground-truthed, because the first version of this finding was wrong and the corrected one
matters: a *denied* attempt already consumes nothing and extends no TTL in **both**
implementations, so "non-extending TTL" is NOT the difference. Reset-on-success is.
A failure counter that clears on success is a different primitive from a request budget that
only decays with its window — `reset(string $key): void` is the whole gap.

### F4. The limiter never charges for an `OPTIONS` request · design call, small diff
`api/Rest.php` — `guard()` spends budget only when the request method is in the route's
registered verb list, and a CORS preflight's `OPTIONS` never matches `POST`.

Measured on todai, on a clean bucket: **40 consecutive preflights left the bucket unset**,
and **5 preflights carrying a 1.1 MB JSON body returned 200 each for zero budget** — while
the identical body as a POST was charged correctly (20 allowed, 429 at 21, counter 20).

A preflight is not cheap: WP's `rest_handle_options_request()` sets a matched route, so
`rest_send_allow_header()` invokes the permission, which for todai decoded the body and read
an option twice. Same shape for anything refused at `rest_pre_dispatch` — no budget, but the
`rest_pre_serve_request` filters still run.

Two candidate shapes: charge in the pre-dispatch path, or make `$matched` cover the
preflight. **Do not solve it by requiring a content type on OPTIONS** — todai tried the
adjacent version of that and broke every preflight (§4).

### F5. Bootstrap is theme-coupled for mu-plugin consumers · two call sites
`core/Bootstrap.php:206` strips `basename(get_stylesheet_directory())` from a namespace
path, and `:504` falls back to `get_stylesheet_directory() . '/services'` for sector
discovery — which runs **unconditionally, even when `auto_discover` is false**.

A consumer that deliberately is not a theme must set `discovery_paths` purely to stop its
mu-plugin scanning whatever theme happens to be active. See
`todai-client/web/app/mu-plugins/todai-core/config/plugin-config.php`, where it took six
lines of comment to explain why a key that looks optional is mandatory.

### F6. `RateLimiter`'s docblock documents a consumer that no longer exists · prose
It describes "the todai intake keys (ip + form_key) under `todai_intake_rate_`, with its own
`todai/intake/*` filters". That design was deleted on 2026-08-20 — the intake now declares
`rate_limit` / `rate_window` as route options and keys nothing itself. This is the docblock
a new consumer reads to learn the calling convention.

### F7. Service metadata `name` cannot pin a service slug · small, touches boot order
`core/Bootstrap.php` — `registerService()` calls `isServiceEnabled()`, which warms the slug
cache from the **class name** before the metadata-aware call runs. A declared `name` never
affects the `ntdst_service_{slug}_enabled` filter or its option. Evidence that this surprises
real consumers: todai wrote a five-line comment explaining it in `services/Ping.php`.

---

## 3. Deferred to its own spec — an options/settings service

**Stefan 2026-08-20: "an options/settings service is a good idea."** Recorded here so the
motivation is not reconstructed from scratch; it needs a design stage, not a defect fix.

**Named consumers already exist** (core's minimalism rule requires them) — seven call sites
across three repos:

| Repo | Sites |
|---|---|
| ntdst-core | `core/Bootstrap.php:643` (`ntdst_service_{$slug}`), `core/SectorRegistry.php:298,323` (enable/tier options), `services/Mailer.php:579` (`ntdst_wrap_all_emails`) |
| ntdst-baseline | 2 |
| todai-client | `FormKeyRegistry`, entirely — a public-endpoint allow-list stored in one option |

**The strongest argument is a bug written twice in one session.** `update_option()` returns
`false` both for a failed write and for an unchanged value. todai's `FormKeyRegistry::add()`
reported "Form key added." for a write that never landed; the fix for that then introduced
the mirror-image bug in `deactivate()`, which reported a persistence failure for an
already-inactive key. Same trap, two methods, one class, one afternoon — the second caught
only by review. A primitive should absorb that once instead of every consumer rediscovering
it.

Alongside it: todai's `all()` returns raw `get_option()` output while three callers index a
shape that nothing validates, so a malformed row throws on **every request that reaches a
public route**.

Candidate surface, for the spec to argue rather than inherit: typed read with a validated
shape; write that distinguishes *unchanged* from *failed*; the `ntdst_` prefix convention
every consumer currently hand-rolls; explicit autoload control.

---

## 4. Traps worth carrying — paid for once already

- **A route-scope check must be case-INSENSITIVE.** WP matches routes with
  `preg_match('@^…$@i')`. todai scoped two filters with `str_starts_with()` on the raw route,
  so `/todai/v1/SUBMISSIONS` reached the handler with every guard declining to run — and the
  CORS correction with them, restoring WP core's reflect-any-origin-with-`credentials: true`.
  If `NTDST_Rest` ever grows route-scoped helpers, they inherit this.
- **A non-2xx preflight is as broken as a missing `Allow-Origin`.** A content-type gate that
  does not exempt `OPTIONS` refuses every preflight with 415 while emitting perfect CORS
  headers. Testing the CORS method in isolation cannot see a status code; only driving the
  route catches it.
- **Mutate before trusting a green.** Two todai tests asserted regressions they could not
  observe: one called `rest_do_request()`, which never fires `rest_post_dispatch`, so
  disabling `NTDST_Rest::memoize()` left it green; the other never pinned a filter
  registration, so deleting the `add_filter` left the suite green. Both were found by
  mutating the thing the test claimed to protect. `NTDST_Rest::memoize()`'s own boxed-null
  fix is the kind of thing that needs this.
- **`ntdst_download()` is strictly better than a hand-rolled CSV block** and consumers do not
  know it exists. todai shipped `nocache_headers()` + `Content-Type` +
  `Content-Disposition` + `fopen('php://output')` and lost `X-Content-Type-Options: nosniff`
  on an attacker-authored body. A CODE-MAP entry saying "file downloads go through
  `ntdst_download()`" would have caught it at authoring time.

---

## 5. Release order

`ntdst-core` → tag → `ntdst-baseline` (its `conflict: netdust/ntdst-core <2.4` needs
revisiting, and F3 lets its login lockout stop being a Deliberate Exception) → consumers.

`todai-client` consumes `^3.0` and its `feat/intake-to-core` branch is mid-spec; it has an
open task (**T09c**) that is F4 seen from the consumer side. Whatever shape F4 takes here
decides that task.

## 6. Verification

```bash
composer gate           # in this repo, before and after
git ls-remote --tags origin
```

Per-item evidence and the two review rounds that produced it:
`~/Sites/todai-client/specs/intake-to-core/tasks.md`, under the module-move-a review gate.
