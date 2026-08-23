# What belongs in ntdst-core

Written at v4.0.0, reconciled with v5.0.0 (2026-08-23, `specs/core-shape`).
Every principle below is cited to code that already obeys it — this describes
the package, it does not aspire past it.

> ntdst-core is a small set of well-engineered primitives for WordPress
> development. It wraps WordPress where WordPress is awkward, provides safe
> defaults where mistakes are expensive, and offers fluent APIs where
> composition genuinely improves the code.
>
> It does not replace WordPress, define application architecture, or contain
> product-domain functionality.

## 1. Prefer WordPress. Wrap it, never replace it

If WordPress already does something well, core does not do it again.

`api/FieldTypes.php` says so where the vocabulary is declared: *"Where WordPress
has the word, the entry is WordPress's function — this table maps names to them,
it does not re-implement them."* Seventeen type names, most of them bound
straight to `sanitize_text_field()`, `sanitize_textarea_field()`,
`wp_kses_post()`, `sanitize_email()`, `esc_url_raw()`, `absint()` or
`wp_validate_boolean()` — core owns the NAME, WordPress owns the answer.

`int` and `float` are the exception that proves the rule: they are plain casts,
because WordPress has no word for a signed integer or for a finite float. The
`int` entry says why in its own comment: *"Signed on purpose (FR-5): absint()
stripped the sign, and a discount in cents is a negative int. A non-scalar is
not a number."* Where WordPress has no word, core writes the smallest thing that
answers, and the entry says so.

`api/Data.php` holds the same line in its own first line: *"NTDST Data Layer — a
chain API over WP_Query, and the meta registration a declared model owes
WordPress."* It is not a database abstraction. `NTDST_Rest` wraps
`register_rest_route()` and adds exactly two things WordPress does not have — a
route that names no permission registers as `is_user_logged_in` rather than
open, and the permission runs once per request instead of twice. WordPress's own
default is the second one's mirror: WP fires `_doing_it_wrong()` for a missing
`permission_callback` and registers the route anyway, leaving it public. Core
lands on WordPress's own floor instead, and reserves the loud refusal for the
case that is a threat and not a posture — a write verb whose gate nobody
named (§4).

The rule has a corollary that matters more than the rule: **anything WordPress
understands passes straight through.** `NTDST_Rest::OWN` lists the three options
the class consumes, under the comment *"Options this class consumes; everything
else goes to WP verbatim."* — and the file header states it once more:
*"Everything else is passed straight through to WordPress."* (`api/Rest.php`).
So `registerOne()` forwards `args`, `schema`, `show_in_index` and `allow_batch`
untouched. A wrapper that hides the thing it wraps is not a wrapper; it is a
fork with extra steps.

Loading is the same rule applied to the class loader. Core installs no
autoloader, scans no directory and reads no PHP source: a listed service class
is one PHP can already resolve, by the consumer's own `require_once`, by
Composer, or by any autoloader the consumer installed (core-trim FR-1, and
INV-10 in `ARCHITECTURE-INVARIANTS.md`). Guessing a file path from a class name
was core re-answering a question the consumer had already answered, and a
writable directory on the old discovery list was code execution.

v5.0.0 is the rule applied three more times, each time by DELETING core's
answer and asking WordPress's:

- **A page route is a rewrite rule.** `ntdst_pages()->path()` calls
  `add_rewrite_rule()` and names its placeholders on `query_vars`, so WordPress
  parses the URL and the callback runs on `template_redirect`. Core used to
  match the URL itself on `template_include`, which meant clearing the `is_404`
  WordPress had just set and suppressing the canonical redirect. Neither exists
  now: a URL WordPress parsed is a 200 already (INV-6).
- **The template loader picks from WordPress's own candidate list.** It is
  mounted on the `{$type}_template` filters and iterates the hierarchy WordPress
  hands it, over the registered directories. Core spells no template name of its
  own, and a callback returns a PATH rather than rendering and exiting.
- **The field vocabulary is one table, and most of it is WordPress.**
  `api/FieldTypes.php` is the single registry every reader asks, and the
  declaration that publishes a field is WordPress's own `show_in_rest` key,
  driving WordPress's own `register_post_meta()` — no second exposure layer
  beside it (INV-1, INV-8).

The hard case is where WordPress does something **badly**. That is not an
exemption — it is the strongest reason to wrap. See §6.

## 2. Small means conceptual surface area, not line count

A developer should be able to write:

```php
ntdst_rest('my/v1')->post('/thing', $handler, ['permission' => $permission]);
```

without knowing about the rate limiter, WeakMap memoization, bucket keying,
transient semantics, or the fact that WordPress invokes `permission_callback`
twice per request. Those are internals. The mental model is one line.

**This is why the docblocks are long and must stay long.** `guard()` carries
more comment than code, and so does the CORS decision. That is the trade that
keeps the surface small: the API is two sentences, and the *reasons* stay
recoverable. A future pass that reads "small" and strips the explanations
deletes the only record of why the permission callable runs BEFORE the limiter
is charged — and the next author swaps them, and every refused caller starts
spending the budget of the one who was allowed.

Small API. Large explanation. Both, deliberately.

## 3. Security: core enforces facts it OWNS, and never infers facts the application owns

This is the line, and the package's own history draws it in both directions.

**Facts core owns — enforce them.**

- Whether a route exists. A declaration WordPress refused is a route that is
  not there — `ntdst_rest()` refuses an unknown option, and an unregistered
  path answers 404 rather than reaching a handler by accident.
- What a route's permission MEANS. A `permission` string is a CAPABILITY, never
  a callable name: `['permission' => 'edit_others_posts']` becomes
  `current_user_can('edit_others_posts')`, and a route that states no
  permission registers as `is_user_logged_in` — WordPress's own floor, never
  anonymous. Anonymous has no spelling in the options array at all; it is
  reached only by chaining `->public()`, and only on a read verb (`api/Rest.php`).
- A capability read off the TYPE where core owns the route.
  `NTDST_RelationField::handleRelationSearch()` asks
  `get_post_type_object($post_type)` for the capability rather than hard-coding
  one, so a CPT that remaps its capabilities narrows the gate with it, and an
  unresolvable capability denies everyone. On a CONSUMER's route the string is
  the consumer's: 5.0.0 removed the dispatcher's `cap_type` floor, so
  `['permission' => 'edit_others_posts']` is asked exactly as written. A remapped
  type passes its own slug —
  `get_post_type_object('artwork')->cap->edit_others_posts` — or a callable that
  reads it.

**Facts the application owns — refuse to guess them.**

Core once shipped a gate stack that re-derived WordPress's visibility semantics
so an anonymous caller could safely name their own query: `canQueryPostType()`,
`filterQueryablePostTypes()`, `canQueryUnpublishedMedia()`,
`nonViewableMediaParentIds()`. Five consecutive generations of security review
went into defending it. All four were **deleted**, along with the
caller-parameterised surface that needed them, because "is this particular
user's relationship to this particular row legitimate" is application
knowledge. Core cannot know it and should not pretend.

Note what happened to the one query that had a real consumer. It did not just
die — it MOVED, to `NTDST_RelationField::handleRelationSearch()`, where the
question finally had an answer: not "may this anonymous caller query the type
they named", but "is this type a declared relation TARGET, and may this
authenticated caller edit others' posts of it". The fix for an unanswerable
question is usually to move it somewhere that owns the facts, not to guard it
harder.

Same test, opposite outcomes. That is what makes it a usable test.

## 4. Safe defaults where mistakes are expensive

Deny is the default, and anonymous reach is a mark somebody MADE. There is no
value a consumer can put in an options array that opens a route: `->public()`
is the only gesture in the language that does it, it is chained onto ONE
declaration, and `api/Rest.php` refuses it on any verb but GET, HEAD and
OPTIONS — "anyone may write" is the threat itself, not an exception to it. A
route that states no permission is not open either: it registers as
`is_user_logged_in`, which on a site with open registration is a floor and not
a gate, so a read worth gating states its capability. A site that really does
take anonymous writes — stride's `QuestionnaireHandler` is the fleet's one case
— passes its own callable and owns that decision in its own code. An
unresolvable capability denies everyone, administrators included.

A service is off when `metadata()` returns `'enabled' => false`, or when its
`services.conditional` condition returns false. There is no third way. Anything
new that gates on a filter fails closed. 5.0.0 removed the fail-open
`ntdst_service_{slug}_enabled` filter and the whole per-service enable switch
with it (core-trim FR-2).

## 5. Chainable for building, not for everything

Chaining is for configuration and composition:

```php
$model->where(...)->orderBy(...)->limit(...)->get();
ntdst_rest('foo/v1')->get(...)->post(...)->delete(...);
```

It is not for wrapping WordPress operations in a fluent façade.
`ntdst()->something()->doWordPressThing()` is Laravel-in-WordPress. The
WordPress API stays visible, and dropping down to `WP_Query`, `$wpdb`,
`register_rest_route()` or a raw hook must always remain possible. **Core makes
the common case easier without making the uncommon case impossible.**

## 6. The admission test

Before anything enters core, all six:

1. **Is it genuinely repeated?** Not "a project might need this" — several
   independent consumers keep writing it. A named consumer is the minimum.
2. **Is WordPress's own API inadequate?** If WordPress solves it cleanly, stop.
   If WordPress solves it *wrongly*, that is the strongest case to wrap it.
3. **Does it have one obvious responsibility?** If explaining it takes a page
   of architecture, it is not a primitive.
4. **Does it have a safe default?** Especially for anything security-shaped.
5. **Can the denial path be tested?** A primitive nothing can observe failing
   is not safe-by-default, it is untested-by-default. Core's only CSRF gate
   went its whole life to v4.0.0 with zero coverage: mutating it away left 219
   tests green, and an independent review — not the suite — is what caught it.
   It is covered now, by ten tests and five mutations.
6. **Does adding it make core more coherent?** The question is never "could
   this be useful". It is "would WordPress development be materially worse
   across several consumers without it".

Criterion 2 has a live answer, and it is the worked example: **CORS**.
WordPress's `rest_send_cors_headers()` reflects any `Origin` *and* sets
`Access-Control-Allow-Credentials: true`, so any origin can read authenticated
responses.

How it scored — with the evidence, not the impression:

1. **Repeated** — two independent consumers, three incidents. todai twice (a
   case-sensitive route scope that took its own correction offline; a
   content-type gate that 415'd every preflight) and vad-website, hand-rolling
   it in a production mu-plugin. That implementation is careful — exact
   allow-list, core's filter removed — and still carries four defects, no
   `Vary: Origin` among them. One competent developer, four defects, is the
   argument for a primitive.
2. **WordPress inadequate** — actively wrong, not merely absent.
3. **One responsibility** — which origins may read this route.
4. **Safe default** — deny; credentials off unless asked.
5. **Denial path testable** — this was the BLOCKER, and it was solved by design
   rather than waived. The decision became a pure function returning the
   headers to set and remove, with emission as a thin wrapper: the seam
   `NTDST_Response::fileHeaders()` already used. Every branch is then
   assertable at the unit tier.
6. **More coherent** — it removes the reason to hand-roll.

Note what it is NOT for. Stride's LTI module is cross-origin and does not use
it: LTI is reached by rewrite rules rather than REST, and its problem is iframe
embedding plus blocked third-party cookies, which CORS does not govern.
"Consumer X is cross-origin" is not the test. "Consumer X needs THIS mechanism"
is.

**Decided 2026-08-20 — opt-in.** A namespace declaring no origins keeps
WordPress's default, and core does not suppress `Allow-Origin` package-wide.
Three reasons, and the third is the principle:

- suppressing it would silently break any consumer relying on WP's reflection,
  however ill-advised that reliance is;
- it would make an `ntdst_rest()` route behave unlike every other REST route on
  the same site, which is a surprise in the direction of "the framework did
  something I did not ask for";
- **core makes the common case easier without making the uncommon case
  impossible** (§5), and its converse holds too: core does not silently change
  what it was not asked to change.

State the residual rather than implying it away: an `ntdst_rest()` route in a
namespace that declared no origins is exactly as exposed as any other WordPress
REST route. Core does not make it worse and does not fix it unless asked. The
fix is one call, and `README.md` says so where a consumer will meet it.

**Revised at 5.0.0 — the list is WordPress's, and the declaration is
site-wide.** Core's own allow-list is gone; README's *CORS is site-wide*
section carries the mechanism. Two lists is one too many — §1's rule, applied
to the primitive this section admitted — and §6.5 still holds, because the
decision stayed a pure function (`corsDecision()`). One thing the admission did
NOT foresee: `allowed_http_origins` is read by `admin-ajax.php`, `admin-post.php`
and the customizer too, and `send_origin_headers()` grants credentials to an
allowed origin unconditionally. So the declaration is scoped to
`wp_is_serving_rest_request()` — a REST origin must not be able to fetch
`admin-ajax.php?action=rest-nonce` on a logged-in visitor's cookies. Converging
on WordPress's table is right, and it still costs a reading of who else reads
it.

### Parked, admitted in principle

A candidate that passes §6.2–§6.6 but not §6.1 is written down, not built: the
design, the trigger that would name a consumer, and the rules a spec must carry.
`docs/parked/` holds them. Read it before proposing an addition to core — the
next person should not re-derive the design, and nobody should build the wrong
neighbour of it.

- `docs/parked/rest-query.md` — a declared field becomes a filter on WordPress's
  own collection (`?venue_city=Ghent`), the same shape as `show_in_rest`. Parked
  2026-08-23: no consumer yet. The thing it rules out is a "queryable collection"
  class inside core — WordPress's collection already is one (§3, core-shape D1).

## 7. Deletion is a feature

The strongest v4 changes removed things. The sector system — 527 lines, a
`Bootstrap` constructor dependency and an unconditional per-boot call — left
because it had **zero** functional consumers, while one site had hand-written a
fake five-method registry just to boot past it. `NTDST_Data_Model`'s bespoke
cache left because measurement showed WordPress already cached all four object
types and the custom layer bought nothing.

An abstraction whose consumers have gone is not neutral. It is a thing that can
be wrong.

**The failure mode to resist now:** "we found an edge case, therefore core needs
another abstraction." Usually the edge case belongs to the consumer that found
it.
