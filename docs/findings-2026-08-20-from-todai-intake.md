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

## 1b. Structural — the sector system does not belong in core · Stefan's ruling 2026-08-20

> "SectorRegistry.php is actually not for ntdst-core, maybe it could be a baseline service
> but remove it."

`core/SectorRegistry.php` is 527 lines defining **sectors** — "independent platforms
(gallery, artist, musician, theater)" with per-sector enable options, tier options and
discovery paths. That is product domain, not framework. Core's own minimalism rule
("features enter only with a named consumer") argues against it staying.

**Coupling to remove** — it is not a leaf, `Bootstrap` hard-depends on it:

| Where | What |
|---|---|
| `ntdst-core.php:31` | `require_once core/SectorRegistry.php` |
| `ntdst-core.php:58` | `ntdst_set(NTDST_SectorRegistry::class, …)` container binding |
| `core/Bootstrap.php:96` | `private readonly NTDST_SectorRegistry $sectors` |
| `core/Bootstrap.php:116` | assigned in the constructor via `ntdst_sectors()` |
| `core/Bootstrap.php:146` | `discoverSectorServices()` called **unconditionally** on every boot |
| `core/Bootstrap.php:502` | the method itself |
| `core/Bootstrap.php:532` | delegates to `SectorRegistry::checkRequirements()` |

**The evidence that settles it.** A consumer already works around this coupling by
fabricating the class. `~/Sites/bavi/app/content/mu-plugins/ntdst-coreloader.php:28-46`
declares a fake `NTDST_SectorRegistry` with five stub methods, under the comment:

> "Stub sector registry — Bootstrap requires `ntdst_sectors()` but the full sector system is
> not implemented yet."

A site that does not use sectors must hand-write a five-method framework class to boot at
all. That is the coupling stating its own case.

**This SUBSUMES half of F5.** `discoverSectorServices()` is where the
`get_stylesheet_directory()` fallback lives (`Bootstrap.php:504`), so removing the sector
system deletes that half outright rather than fixing it. The `:206` theme-slug strip is
separate and survives — see F5.

**SETTLED 2026-08-20 — there are ZERO functional consumers.** Stefan: "none of my projects
need it today, maybe stride, but that's the only one", then "stride is not using". Verified
across the fleet by searching for `ntdst_sectors(` and `NTDST_SectorRegistry`, excluding
vendored copies of core itself. Every remaining reference is a test of core's own class, or
a workaround for its coupling:

| Repo | Reference | Action |
|---|---|---|
| `bavi` | `ntdst-coreloader.php:28-46` — a hand-written five-method STUB, so the site can boot without the system | **Delete the stub.** Removing the coupling is what makes it unnecessary |
| `daan` | `tests/Integration/NtdstCoreLoadTest.php:103,126` — pins the class-to-file map and the container binding | Update the fixture |
| `stride` | `tests/Unit/NtdstSectorRegistryTest.php` — 11 unit tests of core's own registry | **Delete with the class.** No `ntdst_sectors()` call exists anywhere in stride's own code |

**So: DELETE it, do not relocate it to baseline.** The earlier "maybe a baseline service"
framing was Stefan thinking aloud and he closed it himself. Moving a 527-line system with no
callers into another package relocates dead code and gives baseline a domain concern it does
not want either. If sectors are ever needed again, they come back as a consumer-side module
with a named consumer — which is the rule that should have kept them out of core.

**BC-break framing, softened accordingly.** A public class and a global function still leave
the package, so it wants a major (v4.0.0) — but no deprecation window is needed for real
callers, because there are none. Only the three references above need touching, and two of
them are test fixtures.

**What this buys, beyond deleting 527 lines:** `Bootstrap` loses a readonly constructor
dependency and an unconditional per-boot call, F5's `:504` half disappears with it, and
consumers stop having to fabricate a framework class to boot.

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

### F5. Bootstrap is theme-coupled for mu-plugin consumers · one call site after §1b
`core/Bootstrap.php:206` strips `basename(get_stylesheet_directory())` from a namespace
path, and `:504` falls back to `get_stylesheet_directory() . '/services'` for sector
discovery — which runs **unconditionally, even when `auto_discover` is false**.

**The `:504` half disappears with the sector system (§1b)** — it lives inside
`discoverSectorServices()`. Only the `:206` namespace-path strip needs fixing on its own.

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

**Named consumers already exist** (core's minimalism rule requires them) — call sites across three repos — five in core and baseline after the §1b correction, plus todai's:

| Repo | Sites |
|---|---|
| ntdst-core | `core/Bootstrap.php:643` (`ntdst_service_{$slug}`), `services/Mailer.php:579` (`ntdst_wrap_all_emails`). **Corrected 2026-08-20:** `core/SectorRegistry.php:298,323` were counted here and are withdrawn — that file leaves core per §1b, so its option reads go with it (to baseline, if the system is relocated rather than deleted). |
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
