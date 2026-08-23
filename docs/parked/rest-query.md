# Parked — `rest_query`: a declared field becomes a filter on WordPress's own collection

**Status:** parked 2026-08-23 by Stefan ("park the rest_query idea until a consumer
needs it, but document it well so agents see it when a consumer needs it").
**Admission test so far:** §6.2–§6.6 pass; **§6.1 fails — no named consumer.** The
day one appears, this is a one-task spec, not a design discussion.
**Touches:** `api/Data.php` `NTDST_Data_Manager::register()` only. No new class, no
new public method on the model, no change to the chain.

---

## The trigger — how you know a consumer needs it

A site wants anonymous (or any REST) callers to **filter** a post type by a
declared meta field over WordPress's own collection endpoint:

```
GET /wp-json/wp/v2/gigs?venue_city=Ghent
GET /wp-json/wp/v2/gigs?upcoming=1
```

and the field is already declared on the model with `show_in_rest => true`. If
the site is writing a `rest_{type}_collection_params` + `rest_{type}_query` pair
by hand, or a custom `ntdst_rest()` route whose only job is "the list, but
filtered by one meta key", that is the consumer. daan's upcoming/past gigs are the
closest existing shape (today a custom route that filters itself — core-shape D1).

**Not the trigger:** a route that joins, aggregates, paginates differently, or
decides per-caller visibility. That is application logic and stays a route
(`philosophy.md` §3, core-shape D1).

## The shape — declare in `Data`, WordPress does the querying

Same pattern as `show_in_rest` (INV-1): one key on the field description, read
once in `register()`, handed to WordPress. Nothing shapes a response; nothing
decides who may ask — WordPress's controller and its `permission_callback` do.

```php
'fields' => [
    'venue_city' => ['type' => 'text', 'show_in_rest' => true, 'rest_query' => true],
]
```

`register()` then, for each field declaring `rest_query => true`:

```php
// the parameter, with the field's own REST schema (NTDST_FieldTypes::get($type)->schema)
add_filter("rest_{$type}_collection_params", static function (array $params) use ($field, $schema): array {
    $params[$field] = ['description' => "Filter by {$field}", ...$schema];
    return $params;
});

// the query: WordPress's meta_query, keyed on the prefixed meta key
add_filter("rest_{$type}_query", static function (array $args, WP_REST_Request $request) use ($field, $metaKey): array {
    if ($request->has_param($field)) {
        $args['meta_query'][] = ['key' => $metaKey, 'value' => $request->get_param($field), 'compare' => '='];
    }
    return $args;
}, 10, 2);
```

Roughly 30 lines, one loop, inside the existing `if (isset($config['label']))`
branch next to `registerRestMeta()`.

## Rules that come with it (decide at spec time, not in code review)

1. **`rest_query` requires `show_in_rest`.** A field nobody may read is not a
   field anybody may filter on — filtering is a read (an attacker enumerates a
   hidden value by filtering for it). Refuse at `register()`, loud, like the
   other declaration rules in `NTDST_FieldTypes::assertDeclarations()`.
2. **Scalars only.** `text`, `select`, `int`, `float`, `bool`, `date`, `email`,
   `url`. A `repeater`, `json`, `array`, `relation`, `gallery` is refused — a
   `LIKE` over serialized rows is not a filter, it is a slow accident.
3. **`compare` is `=`**, and for `date` / `int` / `float` a second key may later
   add `>=` / `<=` (`'rest_query' => ['compare' => '>=']`). Do not design that
   until the consumer that needs ranges exists.
4. **Value sanitization is WordPress's.** The collection param carries the
   field's REST schema, so `rest_validate_value_from_schema()` rejects a wrong
   type before `rest_{type}_query` runs. The meta value compared is the stored
   one, which the entry's sanitizer already cleaned on the way in.
5. **Performance is the site's.** A `meta_query` is an unindexed `LIKE`/`=` on
   `wp_postmeta`. README says so at the key; a site with a large type adds an
   index or a lookup table itself. Core does not add a table (INV-5).

## Threat rows the spec must carry

- **Enumeration through filtering** — mitigated by rule 1 (only `show_in_rest`
  fields, which are already public per INV-1).
- **Query cost** — a caller filters on a high-cardinality key across a large
  type; mitigated by `per_page` (WordPress caps at 100) and rule 5's README line.
- **Type confusion** — `?venue_city[]=x` against a `text` schema; WordPress's
  schema validation answers 400 before the query filter runs. Test it.

## Tests the task owes (Brain Monkey)

- a declared `rest_query` field adds exactly one `rest_{type}_collection_params`
  filter whose param carries the registry's schema, and one `rest_{type}_query`
  filter that appends one `meta_query` clause keyed on the prefixed key;
- a field with `rest_query` but without `show_in_rest` throws at `register()`
  naming the field;
- a `repeater` with `rest_query` throws naming the type;
- a model with 0 `rest_query` fields adds 0 filters.

## Why it is not in core today

`philosophy.md` §6.1: *"Not 'a project might need this' — several independent
consumers keep writing it. A named consumer is the minimum."* None has.
The design is recorded so the next person does not re-derive it, and so nobody
builds the other thing — a "Queryable Collection" class inside core deciding what
callers may ask for. That layer was deleted in v4 (`philosophy.md` §3) and
core-shape D1 closed the door on it: the collection WordPress already serves
**is** the queryable collection.

## Where this is pointed to from

- `docs/philosophy.md` §6 — "Parked, admitted in principle".
- netdust-wp `ntdst-framework` skill, `## Data` + `lessons.md` — the consumer-side
  agent's entry point when a site asks for a filterable field.
