# What belongs in ntdst-core

Written at v4.0.0. Every principle below is cited to code that already obeys
it — this describes the package, it does not aspire past it.

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
`register_rest_route()` and adds
exactly two things WordPress does not have — a route without a callable
`permission` never registers, and the permission runs once per request instead
of twice.

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

The hard case is where WordPress does something **badly**. That is not an
exemption — it is the strongest reason to wrap. See §6.

## 2. Small means conceptual surface area, not line count

A developer should be able to write:

```php
ntdst_rest('my/v1')->post('/thing', $handler, ['permission' => $permission]);
```

without knowing about the rate limiter, WeakMap memoization, pre-dispatch
preflight charging, route regex matching, transient semantics, or the fact that
WordPress invokes `permission_callback` twice per request. Those are internals.
The mental model is one line.

**This is why the docblocks are long and must stay long.** `chargePreflight()`
carries more comment than code. That is the trade that keeps the surface small:
the API is two sentences, and the *reasons* stay recoverable. A future pass
that reads "small" and strips the explanations deletes the only record of why
`$matched` was not widened — and the next author re-introduces the bug that
charges three units for one preflight.

Small API. Large explanation. Both, deliberately.

## 3. Security: core enforces facts it OWNS, and never infers facts the application owns

This is the line, and the package's own history draws it in both directions.

**Facts core owns — enforce them.**

- Whether an action is registered. `NTDST_Actions::isRegisteredAction()` settles
  it before a rate-limit bucket key exists; an unregistered action gets no
  bucket and no nonce.
- Whether a route declared a permission. A route without a callable
  `permission` is refused at registration, loudly, with no implicit
  `__return_true`.
- A capability floor that is mechanically derivable. `register()`'s `cap_type`
  installs a type-derived floor that bites at dispatch, ahead of the handler
  and alongside it, and fails closed on an unresolvable capability
  (`api/Actions.php`).

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

## 4. Safe defaults where mistakes are expensive — including the one that is wrong

Deny is the default. `ntdst/api/public_actions` ships empty, and the only thing
that adds to it is an author writing `'public' => true` on a registration —
`NTDST_Actions::register()` unifies that declaration onto the site's one filter
(`api/Actions.php`), so anonymous reach is always a mark somebody MADE, never a
default and never something a merge can turn on. A REST route with no permission
does not register. An unresolvable capability denies everyone, administrators
included.

The wart this section used to state is fixed. 5.0.0 removed the fail-open DENY
filter `ntdst_service_{slug}_enabled` along with the whole per-service enable
switch (core-trim FR-2): a service is off when its `metadata()` says
`'enabled' => false`, or when its `services.conditional` condition returns
false, and there is no third way. The rule that outlived it stands — anything
new that gates on a filter fails closed.

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

**Decided 2026-08-20 — opt-in.** A route declaring no `cors` keeps WordPress's
default, and core does not suppress `Allow-Origin` package-wide. Three reasons,
and the third is the principle:

- suppressing it would silently break any consumer relying on WP's reflection,
  however ill-advised that reliance is;
- it would make an `ntdst_rest()` route behave unlike every other REST route on
  the same site, which is a surprise in the direction of "the framework did
  something I did not ask for";
- **core makes the common case easier without making the uncommon case
  impossible** (§5), and its converse holds too: core does not silently change
  what it was not asked to change.

State the residual rather than implying it away: an `ntdst_rest()` route with
no `cors` policy is exactly as exposed as any other WordPress REST route. Core
does not make it worse and does not fix it unless asked. The fix is one option
key, and `README.md` says so where a consumer will meet it.

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
