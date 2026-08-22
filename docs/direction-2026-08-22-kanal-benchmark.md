# Direction — ntdst-core + ntdst-baseline, benchmarked against a Kanal-class site

Source: a conversation Stefan had in ChatGPT, 2026-08-22, pasted into the review
pane and filed verbatim below the line. It is the architecture and direction for
both repositories. The plan for 2026-08-23 (`docs/plans/2026-08-23-core-shape-brief.md`)
is measured against it.

**Read this first — what has changed since the benchmark.** The assessment was
made against core **4.1**. Three of its facts are no longer true after 2026-08-21:

| benchmark says | state now | consequence |
|---|---|---|
| `Data_Model` has a "public shape"; REST endpoints are "backed by a model's public shape" | `public_shape` / `publicRows()` removed (A1). A field-level `show_in_rest` declaration is in `main`, without a reader yet (brief D1) | the exposure shape lives in the exposing service (site or baseline), not in the model |
| "public action controls"; the Newsletter chain is `baseline Newsletter → NTDST_Actions → RateLimiter → Mailer` | `Actions.php` is to be removed (A4, brief D2); `NTDST_Rest` carries permission, rate limit, CORS and the surface registry | the chain becomes `→ NTDST_Rest →`. Same shape, one surface |
| RelationField "uses the action system rather than creating a parallel API" | still true in code (`admin/RelationField.php:47`), which makes RelationField a consumer of the thing being removed | RelationField's `relation_search` becomes a `ntdst_rest()` route in the same change |

Everything else holds, and the **strongest recommendation** at the end — do not
build the missing baseline capabilities yet; build one real site and record every
"I need to build infrastructure for X" — is consistent with A9: the search / import
/ export specs stay frozen. They are exactly the capabilities this says to earn
first.

---

I went through the current ntdst-core + ntdst-baseline and used the current Kanal site as the benchmark, rather than asking whether the libraries are generally "good."

My conclusion is slightly different from what I expected:

The foundation is already strong enough to build the architecture of a Kanal-class site.

But the baseline is not yet a complete "Kanal capability kit."

And I think that's actually the correct state: core is close to where it should be; baseline needs to grow around recurring site capabilities before your "mostly configuration" vision is fully realized.

The important part is that the missing pieces aren't foundational problems. They are mostly reusable services that should sit in baseline.

## First: what Kanal actually requires

The current Kanal site isn't just an exhibition website. It has:

institutional/content pages · exhibitions · events/programme · artists · collections · architecture archives/library · projects · news/articles · people/teams · partners · press/media · downloads · visitor information · spaces · groups/schools · venue hire · newsletter · multilingual content · filtering/search · structured data · external/public catalogue data · large media assets

The programme alone includes exhibitions, live events, workshops, talks, guided tours, book launches, children's workshops and other activities, with filtering and past events.

The collection side is even more data-heavy: contemporary artworks, artists' editions, archives, library holdings and architectural collections.

So it's a very good stress test.

### 1. Content modelling / CPTs — 🟢 Already there

This is where I think the current core is quite strong.

NTDST_Data_Model gives you a chainable data layer over WP_Query, with: schema, sanitizers, validators, public shape, scopes, WordPress field mapping — rather than pretending WordPress isn't WordPress.

And the metabox generator consumes the same field vocabulary.

That means something like Exhibition, Artist, Event, News, Project, Collection Item, Person, Venue can become mostly declaration + model configuration.

Kanal verdict: Yes. I'd expect the bespoke backend code for the content model to be relatively small.

### 2. Relationships — 🟢 Already there

This is actually important for Kanal. You need things like Artist ↕ Exhibition ↕ Event; Artwork ↕ Artist; Event ↕ Venue; Project ↕ Person.

RelationField already exists specifically for relationship fields, including autocomplete and reverse relationships, and it uses the action system rather than creating a parallel API.

So "Event belongs to exhibition" should not require inventing a relationship subsystem.

Verdict: Yes — this is already foundation-grade.

### 3. Events / calendar — 🟡 Core primitive exists; baseline capability does not yet

This is one of the first real gaps.

Kanal's programme is a substantial content system: event types, dates, start/end, past/future, filtering, audience, themes, relationships, potentially recurring events.

Core already gives you Scheduler, but that is cron infrastructure, not an event content service. NTDST_Scheduler deliberately provides recurring WP-Cron registration rather than a full event model.

So an AI building Kanal today would still have to write: Event model, date querying, upcoming/past scopes, event filters, calendar representation.

That's fine for core. But if you've built this twice, it belongs in baseline.

Verdict: Not yet "config". I'd call this one of the first baseline capabilities needed for your vision.

### 4. News / editorial content — 🟢 Basically config

WordPress already does most of this. You need News, Articles, Categories, Authors, Featured image, Publish date. Core's data/metabox/response infrastructure covers the custom part. Baseline's SEO/schema layer is already relevant here. It supports article/webpage structured data and CPT-specific schema extension.

Verdict: Yes. No need for a "NewsService" merely to make a news CPT.

### 5. Artists / people — 🟢 Mostly config

Artist is essentially a content entity: name, bio, image, website, socials, related exhibitions, related works. Same for team members, partners, etc. The data + relationship + admin layers are sufficient.

Verdict: Yes. This is exactly the sort of thing the site should define rather than baseline implementing.

### 6. Collections / catalogues — 🟢/🟡 Technically yes; scale-dependent

Kanal's collection is much more than a normal CPT. It has artworks, media, artists, dates, disciplines and relationships, and its architecture collection contains hundreds of archival fonds and a library approaching 65,000 works.

For a normal site collection: NTDST Data is enough. For a genuine 65,000-item catalogue with complex faceting/search: you'd probably eventually need a dedicated search/indexing service. But that is not a reason to expand core.

Verdict: Foundation yes. Full catalogue/search capability: not baseline yet. And I would keep it that way until a real project proves the need.

### 7. Filtering / faceted discovery — 🟡 Core supports it, but baseline doesn't make it declarative yet

Kanal has programme filtering by event type, audience, theme, date/past/current.

Your Data_Model scopes are actually a very good foundation for this because scopes explicitly represent named query fragments that narrow a query rather than change its shape. So an AI can write `upcoming()`, `forAudience()`, `ofType()`, `inTheme()` fairly cleanly. But it still has to write them.

Verdict: Good foundation, not yet capability-as-config.

### 8. Public API / making site data available to other systems and AI — 🟢 Core is already strong here

This is one of the areas where I think your architecture is ahead of the typical WordPress setup. You have: REST, explicit permission requirement, rate limiting, CORS, response handling, public action controls, data public_shape.

The action system is deliberately not a generic data API anymore. Consumers register the actions that understand their own data. That's exactly right. And REST/CORS are route-level composition rather than another API framework.

Kanal example: an AI could expose `/api/v1/events`, `/api/v1/exhibitions`, `/api/v1/artists`, `/api/v1/collections`, with each endpoint backed by a model's public shape.

Verdict: Yes. This is already one of the strongest parts of core.

### 9. External imports — 🟡 Core has the infrastructure, baseline doesn't yet have the capability

Kanal itself may integrate external/partner information, and a site like this commonly needs: external calendar, museum catalogue, ticketing, newsletter system, partner data, event feeds.

Core gives you the pieces: services, container, scheduler, data, actions; HTTP-adjacent application code can be written as a service. But there isn't currently an obvious generic Importer / RemoteSource / Sync / Mapping capability in baseline.

Verdict: This is a real baseline gap for your stated vision. Not core.

### 10. Mailings / newsletter — 🟡

Core has Mailer, so sending mail is already a solved primitive. But sending mail is not newsletter subscription + consent + provider sync + unsubscribe + campaign integration. Kanal explicitly has newsletter signup. That second thing is baseline territory.

Verdict: Mailer: yes. Newsletter capability: not yet.

### 11. Downloads / press kits / documents — 🟢 Core is already unusually good here

NTDST_Response has explicit support for JSON, HTML and file downloads, including PDF, CSV, XML, ICS, VCF, office formats, archives, etc. Kanal has downloadable press kits and images.

Verdict: Yes. This should feel like using a primitive, not developing infrastructure.

### 12. SEO / structured data — 🟢 Baseline already covers this well

This is one of the clearest examples of what baseline should be. It already provides meta, canonical, OG, Twitter, robots, JSON-LD, article, webpage, CPT schema, custom schema, FAQ. And it deliberately avoids shipping fake site-specific schema data. The schema service also has explicit CPT hooks, so the site can add schema for exhibition, artist, event, etc.

Verdict: Yes. This is already baseline doing exactly its job.

### 13. Caching / performance — 🟢 Good baseline foundation

You've now got CacheHeadersService in baseline and a policy abstraction rather than scattering headers around sites. And importantly, core's Data layer deliberately does not invent its own second-level cache. It relies on WordPress's existing query/object/meta/term caching and leaves specific optimisations to consumers. I strongly agree with that decision.

Verdict: Architecture is right.

### 14. Search — 🟡 This is one of the bigger missing capabilities

Kanal has collection browsing and discovery, and a site like this needs meaningful search. Core's data layer can query WordPress. But that's not the same as search, ranking, facets, autocomplete, cross-content-type results, collection search.

For a normal 500-page site, WordPress search may be sufficient. For Kanal's collection/library scale, it isn't something I'd pretend core solves.

Verdict: Not yet a baseline capability. And again, I'd only build it when a real project needs it.

### 15. Multilingual — 🔴 Not a core/baseline problem yet — but Kanal requires an external solution

Kanal is multilingual (Dutch/French/English). I would not put multilingualism into core. And I probably wouldn't build it into baseline unless you have a strong opinion about how your sites should handle it. Use the appropriate WordPress multilingual system for the project. The foundation should remain compatible with it.

Verdict: Kanal needs it; ntdst doesn't need to own it. That's an important distinction.

### 16. Media / image-heavy publishing — 🟢 Foundation yes

Kanal is extremely media-heavy. WordPress already handles media, while your response/template/admin/data layers can build the bespoke pieces. You don't need NTDST_ImageManager simply because Kanal has lots of images.

Verdict: Yes.

### 17. Forms / visitor interaction — 🟡

Core's Actions + REST + permissions/rate limiting can implement forms safely. But baseline doesn't currently appear to give you a declarative form / fields / validation / mail / storage / spam protection / success capability.

For an artist portfolio, contact form. For a museum, newsletter/signup/contact/research requests. For a delicatessen, preorder/contact/newsletter. This is likely to recur.

Verdict: Candidate for baseline.

### 18. Scheduled jobs — 🟢 Core primitive exists

Scheduler is deliberately small and WP-native. It gives you idempotent recurring scheduling and clearing. That's enough infrastructure for: import every night, purge every hour, sync every 15 minutes, send digest daily. The actual jobs belong to baseline/site.

Verdict: Yes.

### 19. Admin editing experience — 🟢 Very strong foundation

This is an underrated part of the stack. The metabox generator + relation fields + data schema means your AI can define the model and generate a usable admin interface rather than hand-writing dozens of metaboxes. That matters enormously for the "configuration" vision.

Verdict: Yes.

### 20. Security — 🟢🟢 This is already the strongest layer

Core has the security-oriented REST/action architecture. Baseline additionally has login throttling, security headers, user enumeration protection, XML-RPC control, file editor control, CSP/HSTS configuration, etc. And it delegates the rate limiting to core rather than duplicating the primitive. This is exactly the division we discussed.

Verdict: Yes. Don't expand this much further.

## So what does the full Kanal test tell me?

| Kanal capability | Today |
|---|---|
| Content model | 🟢 |
| CPTs | 🟢 |
| Custom fields | 🟢 |
| Relationships | 🟢 |
| Artists/people | 🟢 |
| Exhibitions | 🟢 |
| News | 🟢 |
| Events model | 🟡 |
| Calendar/querying | 🟡 |
| Filtering | 🟡 |
| Collections | 🟢 |
| Large catalogue search | 🟡/🔴 |
| Public API | 🟢 |
| CORS | 🟢 |
| Downloads | 🟢 |
| Scheduler | 🟢 |
| SEO | 🟢 |
| JSON-LD | 🟢 |
| Cache headers | 🟢 |
| Newsletter | 🟡 |
| Forms | 🟡 |
| External imports | 🟡 |
| Sync | 🟡 |
| Search | 🟡 |
| Multilingual | external |
| Media | 🟢 |
| Admin UX | 🟢 |
| Security | 🟢🟢 |

## And here's the important answer to your actual question

Could you build Kanal today with these two repositories? Yes.

But it would not yet feel like a configuration job. It would feel more like 70–80% configuration/model definition + 20–30% bespoke service work + frontend.

That's already very good. But your stated goal is closer to 90% composition/configuration + domain model + frontend; 10% genuinely unique backend logic.

You're not quite there yet. And I don't think you should add a bunch to ntdst-core to get there.

## Where I would grow the system

The missing capabilities are remarkably consistent:

**ntdst-core** — keep it mostly where it is. It already gives you the machinery: DI, lifecycle, data, REST, actions, responses, admin fields, relations, scheduler, mail, security. Don't turn it into Kanal-core.

**ntdst-baseline** — this is where I would gradually add: Events, Forms, Newsletter integration, Remote imports, Data synchronisation, Search/discovery, Common public feeds, maybe collection/search infrastructure. But only after proving each one across actual sites.

## The really encouraging part

The hard stuff is already in the right place. Look at the pattern:

```
Event → NTDST_Data_Model → NTDST_Rest → NTDST_Response → NTDST_Scheduler → baseline Event service
```

That's not six systems. It's one foundation being composed. Likewise:

```
Remote API → baseline Importer → NTDST_Data_Model → NTDST_Scheduler → NTDST_Rest → public data
```

And:

```
Newsletter form → baseline Newsletter → NTDST_Actions → NTDST_RateLimiter → NTDST_Mailer
```

That is the architecture you were describing.

## My strongest recommendation

Don't start implementing these missing capabilities yet.

Instead, take one real Kanal-sized site specification and make the AI produce the site using what exists today. Then record every time it has to say "I need to build infrastructure for X." Those are your candidate baseline services.

- If the agent says "I need to create an Event model" — that's fine.
- If it says "I need to build a secure recurring-job system" — that's a failure of the foundation.
- If it says "I need to build a generic REST router" — that's definitely a failure.
- If it says "I need to build an importer abstraction" and you hear that on site #2 and #3, then baseline gets an importer.

That's how you reach the thing you actually want: the next website isn't a software project. It's a site specification that happens to produce software.

And looking at 4.1 core + current baseline, I think you're genuinely much closer to that than I initially thought. The remaining distance is mostly capability accumulation in baseline, not architectural repair.
