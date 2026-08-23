<?php

declare(strict_types=1);

/**
 * Relation Field Service
 * Provides API endpoints for post searching and reverse relationship metaboxes
 *
 * @package NTDST Core
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

// NOT a service, and it never was one. This class is on no service list: it
// mounts itself from the bottom of this file (`add_action('after_setup_theme',
// …)` @ 20), which is where its hooks have always come from. Until v5.0.0 it
// also implemented NTDST_Service_Meta and declared a metadata() saying
// `enabled => true`, `admin_only => true`, `priority => 5` — keys Bootstrap
// reads off classes it BOOTS, and Bootstrap never boots this one. So the
// declaration was a claim nobody read: a consumer who set `enabled => false`
// through it would have switched off nothing, and a reader tracing the picker's
// load order would have looked for it on a list it is not on (FR-10).
final class NTDST_RelationField
{
    /**
     * The most post types one search may name.
     *
     * `post_type[]` is caller-controlled and WordPress parses a JSON body on
     * ANY method, so `max_input_vars` bounds nothing here: an anonymous GET can
     * carry `{"post_type":[100000 strings]}`. The gate walks the list entry by
     * entry — a `get_post_type_object()` and a `current_user_can()` each — and
     * the rate limiter charges only callers the permission ADMITS, so an
     * unbounded list is unbounded work bought for free.
     *
     * The bound is stated TWICE on purpose. `maxItems` on the arg is the cheap
     * refusal: `has_valid_params()` runs before the permission, so an abusive
     * list is a 400 with no framework work behind it. WordPress validates
     * `items` before `maxItems` though, so the schema alone still walks the
     * list once — hence the same cap inside mayPickFromAll(), read off the
     * REQUESTED list before any normalising, so nothing shrinks it first.
     *
     * Twenty is a picker pointed at a wide model, with room. It is not a
     * plausible hand-written search.
     */
    private const MAX_REQUESTED_TYPES = 20;

    /**
     * Takes nothing. This used to demand a theme object it never dereferenced
     * — a dependency that did no work, but forced the bootstrap below to
     * resolve one out of the container purely to satisfy this signature, and to
     * gate the picker's existence on a class the picker does not use.
     */
    public function __construct()
    {
        $this->init();
    }

    private function init(): void
    {
        // The autocomplete is a READ of the site's own rows by an editor, so it
        // is ONE resource route on the framework's single HTTP surface — never a
        // command on a dispatcher of its own. It used to borrow the router's
        // public `search_posts`, which is how a PUBLIC, caller-parameterised
        // query surface came to exist for the sake of one ADMIN picker, and why
        // five generations of security review were spent gating it.
        //
        // THE PERMISSION IS A CLOSURE, and it has to be. A route that names none
        // registers as `is_user_logged_in`, and on a site with open registration
        // that is "anyone" — every account enumerating every row of every
        // relation target, published or not. A capability STRING cannot express
        // it either, because the capability is per REQUESTED type: it is read
        // off each type object, and EVERY requested type must pass.
        //
        // THE BUDGET is 60 requests per 60 seconds, and the framework spends it
        // only for callers this permission admits. An autocomplete fires on
        // keystrokes, so the ceiling is a keyboard's worth of typing, and the
        // refusal carries `retry_after` — a limit with no back-off signal is a
        // picker that dies silently mid-word.
        ntdst_rest('ntdst/v1')->get('/relation/search', [$this, 'handleRelationSearch'], [
            'permission' => fn(\WP_REST_Request $request): bool
                => $this->mayPickFromAll((array) $request->get_param('post_type')),
            'rate_limit'  => 60,
            'rate_window' => 60,
            // ONE LINE EACH, and that is load-bearing: `string` is also a
            // RETIRED field-type name, and the pin that keeps it out of shipped
            // code exempts a line only when the line ITSELF shows it is a REST
            // `args` schema — a `type` beside a `required`, a `sanitize_callback`
            // or an `items`. Split across lines, the bare `'type' => 'string'`
            // is indistinguishable from the retired declaration and fires.
            'args'        => [
                'search'    => ['type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field'],
                'post_type' => ['type' => 'array', 'maxItems' => self::MAX_REQUESTED_TYPES, 'items' => ['type' => 'string']],
            ],
        ]);

        // Register reverse relationship metaboxes
        add_action('add_meta_boxes', [$this, 'registerReverseRelationshipMetaboxes'], 20);
    }

    /**
     * Serve GET /ntdst/v1/relation/search.
     *
     * The GATE is not here: it is the route's permission, so a refused caller
     * never reaches this method and no query is ever built for one. Read
     * mayPickFromAll() for it. The two conditions it enforces are the whole
     * gate, and they are these:
     *
     *  1. THE TYPE MUST BE A DECLARED RELATION TARGET. The allow-list is
     *     DERIVED from the registered schemas — exactly the `post_type` values
     *     named by `relation`-typed fields — so a type nobody points a relation
     *     field at is unreachable here and nobody has to remember to exclude
     *     it. This replaces `canQueryPostType()`, which tried to answer "may
     *     this actor query this arbitrary type" by re-deriving WordPress's
     *     visibility semantics from the registry, and whose own docblock had to
     *     list the flags it did NOT consult.
     *
     *  2. THE CALLER MUST HOLD THE TARGET TYPE'S `edit_others_posts`. Read off
     *     the type rather than hard-coded, so a CPT that remaps its capabilities
     *     narrows this with it (T33's rule). `edit_posts` is deliberately NOT
     *     enough: it means "may edit MY OWN posts", Contributor and Author both
     *     hold it, and this returns EVERY row of the type — which is precisely
     *     the T23/T32 finding. Empty or non-string capabilities deny.
     *
     * With an anonymous caller impossible, T24's attachment widening no longer
     * needs `canQueryUnpublishedMedia()`, `nonViewableMediaParentIds()` (T30's
     * uncached full-attachment scan) or T31's fail-open `post_parent__not_in`
     * sibling: every caller who reaches this point may already edit others'
     * posts of the type, which is the same claim those three gates were
     * computing the hard way.
     *
     * @return array|WP_Error
     */
    public function handleRelationSearch(\WP_REST_Request $request)
    {
        $search = trim(sanitize_text_field((string) $request->get_param('search')));
        if ($search === '') {
            // The STATUS is the third argument or WordPress answers 500. A
            // WP_Error returned from a REST callback carries no HTTP meaning of
            // its own, and the deleted dispatcher's 400 default went with it —
            // so an empty term (reachable on the first keystroke: `search=<b>`
            // survives the wire and the arg's sanitize_callback reduces it to
            // `''`) would be logged and alerted as a server fault.
            return new \WP_Error('empty_search', 'Search term required', ['status' => 400]);
        }

        // The SAME normalization the permission ran, so the list WordPress is
        // asked for is the list that was gated — a second shaping here is how
        // one of them stops matching the other.
        $allowed = $this->normalizeTypes((array) $request->get_param('post_type'));

        // Unreachable through the route: the permission refuses an empty list
        // before this runs. Kept because WP_Query with no `post_type` searches
        // `post`, so the fail-open direction of this branch answers rows nobody
        // asked for and nobody gated.
        if ($allowed === []) {
            return new \WP_Error(
                'forbidden_post_type',
                'You are not allowed to search these post types.',
                // A refusal, not a fault: 403, for the same reason as above.
                ['status' => 403],
            );
        }

        // The four defaults below `posts_per_page` are the ones
        // `ntdst_get_formatted_posts()` applied to this call before core-trim
        // FR-4 removed it — the Data layer's second query API, a global that
        // returned rows without naming a model, and therefore without the
        // schema that says what the rows mean. It also built a permalink, an
        // excerpt and two thumbnail URLs for twenty rows on every keystroke,
        // none of which ever reached the screen. They are restated INLINE so
        // this literal is the whole query: one place to read what WordPress is
        // asked, and the picker's result set does not change with the removal.
        $args = [
            's'                   => $search,
            'post_type'           => $allowed,
            'posts_per_page'      => 20,
            'post_status'         => 'publish',
            'orderby'             => 'date',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
        ];

        // Attachments are stored `post_status = 'inherit'`, never `publish`,
        // and the default above is `publish` — so without this a relation
        // field scoped to `attachment` renders a picker that can never return
        // a result. Added alongside `publish` rather than replacing it, so a
        // mixed search still finds the published rows of other types. It
        // OVERWRITES the default, which is why it is written after it.
        if (in_array('attachment', $allowed, true)) {
            $args['post_status'] = ['publish', 'inherit'];
        }

        $query = new \WP_Query($args);

        // The picker needs an id and a label and nothing else — metabox-fields.js
        // reads `id` and `title` off each row and discards the rest.

        return ['results' => array_map(
            static fn($post): array => [
                'id'    => (int) $post->ID,
                'title' => $post->post_title,
            ],
            $query->posts,
        )];
    }

    /**
     * Every post type named as the target of a `relation` field, across all
     * registered models. The allow-list is derived, never maintained.
     *
     * Unlike `getModelsWithRelations()` this does NOT restrict itself to
     * `public` types: relation fields legitimately target non-public ones
     * (`musician_profile` is `public => false` and is a declared target), and
     * an editor must keep being able to pick them.
     *
     * @return string[]
     */
    private function relationTargetTypes(): array
    {
        $manager = ntdst_data();
        $targets = [];

        foreach (get_post_types([], 'names') as $post_type) {
            if (!$manager->isRegistered($post_type)) {
                continue;
            }

            foreach ($manager->get($post_type)->getSchema() as $field_config) {
                if (!is_array($field_config) || ($field_config['type'] ?? '') !== 'relation') {
                    continue;
                }

                $target = $field_config['post_type'] ?? '';
                if (is_string($target) && $target !== '') {
                    $targets[] = $target;
                }
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * May the current caller pick from EVERY requested type?
     *
     * The route's permission, and the only way in. Four refusals, one per way
     * a gate like this fails open:
     *
     *  0. TOO MANY REQUESTED. The bound is read off the raw list before any
     *     other line runs, so an abusive list costs one `count()`. See
     *     MAX_REQUESTED_TYPES.
     *  1. NOTHING REQUESTED. A loop over an empty list answers true unless the
     *     emptiness is checked first, so a caller who simply omits `post_type`
     *     would be admitted to a handler asked to search nothing — or, one hand
     *     later, everything.
     *  2. ANY refusal refuses the REQUEST. Admitting when any requested type
     *     passes hands back the refused type's rows in the same response, and
     *     the happy path never shows it.
     *  3. A type the caller may edit but nobody points a relation field at is
     *     still refused: the picker is not a general query surface over the
     *     site.
     *
     * @param array<int, mixed> $requested Untrusted, straight off the request.
     */
    private function mayPickFromAll(array $requested): bool
    {
        // FIRST, and on the RAW list: the cap has to be read before anything
        // walks or reshapes the request, or the work it refuses is already
        // done. See MAX_REQUESTED_TYPES for why the arg's `maxItems` does not
        // cover this on its own.
        if (count($requested) > self::MAX_REQUESTED_TYPES) {
            return false;
        }

        $types = $this->normalizeTypes($requested);

        if ($types === []) {
            return false;
        }

        $targets = $this->relationTargetTypes();

        foreach ($types as $type) {
            if (!in_array($type, $targets, true) || !$this->mayPickFrom($type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The requested types as a clean, unique list of non-empty strings.
     *
     * One shaping, used by the permission and by the handler, so the list that
     * was gated is the list WordPress is handed. A non-string entry is dropped
     * by BOTH — never gated by one and queried by the other.
     *
     * @param  array<int, mixed> $requested
     * @return list<string>
     */
    private function normalizeTypes(array $requested): array
    {
        $types = [];

        foreach ($requested as $type) {
            if (!is_string($type)) {
                continue;
            }

            $type = sanitize_text_field($type);

            if ($type !== '') {
                $types[] = $type;
            }
        }

        return array_values(array_unique($types));
    }

    /**
     * May the current caller pick from this ONE type?
     *
     * The capability is read off the TYPE, so a CPT that remaps its map narrows
     * this with it. Fail-closed on an unregistered type or a missing/non-string
     * capability — a gate must never widen because a value was absent.
     */
    private function mayPickFrom(string $post_type): bool
    {
        $object = get_post_type_object($post_type);
        if (!$object instanceof \WP_Post_Type) {
            return false;
        }

        $edit_others = $object->cap->edit_others_posts ?? '';

        return is_string($edit_others)
            && $edit_others !== ''
            && current_user_can($edit_others);
    }

    /**
     * Register reverse relationship metaboxes
     * Shows "Featured in Exhibitions" on Artist/Artwork pages, etc.
     */
    public function registerReverseRelationshipMetaboxes(): void
    {
        // Get all registered models with relation fields
        $models_with_relations = $this->getModelsWithRelations();

        foreach ($models_with_relations as $source_post_type => $relation_fields) {
            foreach ($relation_fields as $field_name => $field_config) {
                $target_post_type = $field_config['post_type'] ?? null;

                if (!$target_post_type || !post_type_exists($target_post_type)) {
                    continue;
                }

                // Add metabox to target post type showing where it's referenced
                $metabox_title = $field_config['reverse_label'] ?? sprintf(
                    'Featured in %s',
                    ucwords(str_replace('_', ' ', $source_post_type)) . 's',
                );

                add_meta_box(
                    "ntdst_reverse_{$source_post_type}_{$field_name}",
                    $metabox_title,
                    [$this, 'renderReverseRelationshipMetabox'],
                    $target_post_type,
                    'side',
                    'default',
                    [
                        'source_post_type' => $source_post_type,
                        'field_name' => $field_name,
                        'target_post_type' => $target_post_type,
                    ],
                );
            }
        }
    }

    /**
     * Get all registered models with relation fields.
     *
     * Uses NTDST_Data_Manager::isRegistered() so iterating over public
     * post types doesn't auto-create phantom model entries for built-in
     * types (post, page, sfwd-courses, etc.) that have no NTDST schema.
     */
    private function getModelsWithRelations(): array
    {
        $models = [];
        $data_manager = ntdst_data();

        // Every public type, filtered to the ones with an NTDST schema.
        $candidates = get_post_types(['public' => true], 'names');

        foreach ($candidates as $post_type) {
            if (!$data_manager->isRegistered($post_type)) {
                continue;
            }

            try {
                $model = $data_manager->get($post_type);
                $schema = $model->getSchema();
                if (empty($schema)) {
                    continue;
                }

                foreach ($schema as $field_name => $field_config) {
                    if (is_array($field_config) && ($field_config['type'] ?? '') === 'relation') {
                        $models[$post_type][$field_name] = $field_config;
                    }
                }
            } catch (\Throwable $e) {
                continue;
            }
        }

        return $models;
    }

    /**
     * Render reverse relationship metabox
     */
    public function renderReverseRelationshipMetabox(\WP_Post $post, array $metabox): void
    {
        $source_post_type = $metabox['args']['source_post_type'];
        $field_name = $metabox['args']['field_name'];

        // Find all posts of source_post_type that reference this post
        $referring_posts = $this->findReferringPosts($post->ID, $source_post_type, $field_name);

        if (empty($referring_posts)) {
            echo '<p class="ntdst-reverse-relations-empty" style="padding: 12px; color: #646970; font-style: italic; margin: 0;">Not featured in any ' . esc_html($source_post_type) . 's yet.</p>';
            return;
        }

        echo '<ul class="ntdst-reverse-relations-list" style="margin: 0; padding: 0;">';
        foreach ($referring_posts as $referring_post) {
            $edit_url = get_edit_post_link($referring_post->ID);
            echo '<li style="margin: 0; padding: 8px 12px; border-bottom: 1px solid #f0f0f1;">';
            echo '<a href="' . esc_url($edit_url) . '">' . esc_html($referring_post->post_title) . '</a>';
            echo '</li>';
        }
        echo '</ul>';

        echo '<style>
            .ntdst-reverse-relations-list li:last-child { border-bottom: none; }
        </style>';
    }

    /**
     * Find posts that reference the given post ID in a relation field.
     *
     * TWO DEFECTS FIXED HERE (josworld, 2026-08-03) — both made this method
     * return an empty set, which renders as an empty reverse metabox and is
     * indistinguishable from "this item genuinely has no referrers".
     *
     * 1. META PREFIX (affected every ntdst-core copy). The lookup queried
     *    `pm.meta_key = $field_name` with the RAW field name. A model
     *    registered with a `meta_prefix` (a first-class register() arg —
     *    Data.php:52,71) stores that field as `<prefix><field>`, so the
     *    query matched nothing. Verified live: a `case` model with
     *    meta_prefix `_jw_` and a `team` relation stores
     *    `_jw_team => a:2:{i:0;i:11;i:1;i:22;}`, while this method looked
     *    for meta_key `team`. Both spellings are now tried — the same
     *    tolerance Data.php's own read path already has
     *    (`$meta[$metaKey] ?? $meta[$field]`, Data.php:1477).
     *
     * 2. STORAGE FORMAT (a regression in the newer copies). The previous
     *    revision of this docblock asserted "Data.php stores relation
     *    values as JSON arrays ([6, 7]), not PHP serialized" and replaced a
     *    working serialized-pattern LIKE with JSON-shaped ones. That
     *    premise is false: relation values go through update_post_meta() on
     *    a PHP array, so WordPress serializes them via maybe_serialize().
     *    Data.php never json_encode()s a relation value — grep confirms it.
     *    The older ntdst-core revision (still deployed on acerta,
     *    ludoluykx, netdust, rossi) had this right with `%i:<id>;%`.
     *
     * Both formats are now accepted: serialized (what WordPress actually
     * writes) AND JSON (in case any caller ever hand-writes that shape).
     * The SQL only NARROWS the candidate set; correctness comes from the
     * exact in_array() check below, so a loose LIKE costs a few extra rows,
     * never a false positive.
     */
    private function findReferringPosts(int $post_id, string $post_type, string $field_name): array
    {
        global $wpdb;

        $meta_keys = array_unique([$field_name, $this->prefixedMetaKey($post_type, $field_name)]);
        $key_placeholders = implode(', ', array_fill(0, count($meta_keys), '%s'));

        // Candidate narrowing only — see the exact verification below.
        // Serialized: a:2:{i:0;i:11;i:1;i:22;} -> `i:11;`
        // JSON:       [11,22]                  -> `[11,`, `,11,`, `,11]`, `[11]`
        $params = array_merge(
            [$post_type],
            $meta_keys,
            [
                '%i:' . $post_id . ';%',    // PHP-serialized member (WP's real format)
                '[' . $post_id . ']',       // JSON single-element
                '[' . $post_id . ',%',      // JSON first
                '%,' . $post_id . ',%',     // JSON middle
                '%,' . $post_id . ']',      // JSON last
            ],
        );

        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $key_placeholders is built from array_fill('%s'), never from input.
        $sql = $wpdb->prepare(
            "SELECT DISTINCT p.* FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = %s
            AND p.post_status = 'publish'
            AND pm.meta_key IN ({$key_placeholders})
            AND (
                pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
                OR pm.meta_value LIKE %s
            )
            ORDER BY p.post_title ASC",
            $params,
        );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $candidates = $wpdb->get_results($sql) ?: [];

        // Exact verification — guards against a LIKE false positive (ID 6
        // matching inside ID 60) and against unexpected meta shapes.
        $matches = [];
        foreach ($candidates as $candidate) {
            $ids = [];
            foreach ($meta_keys as $meta_key) {
                $raw = get_post_meta($candidate->ID, $meta_key, true);
                if ($raw === '' || $raw === null) {
                    continue;
                }
                $ids = is_array($raw) ? $raw : (is_string($raw) ? (json_decode($raw, true) ?: []) : []);
                if ($ids !== []) {
                    break;
                }
            }

            if (in_array($post_id, array_map('intval', (array) $ids), true)) {
                $matches[] = $candidate;
            }
        }

        return $matches;
    }

    /**
     * The meta_key a model actually writes for one of its fields.
     *
     * A model registered with a `meta_prefix` (Data.php:52,71) stores
     * `<prefix><field>`; one registered without stores the bare field name.
     * Resolved through the registered model rather than a hardcoded
     * convention, so this stays correct if the prefixing rule ever changes.
     *
     * Falls back to the bare field name when the model is not registered
     * (or the Data API is unavailable) — the caller tries both spellings
     * anyway, so a wrong guess here costs one redundant meta_key in an IN(),
     * never a missed match.
     */
    private function prefixedMetaKey(string $post_type, string $field_name): string
    {
        if (!function_exists('ntdst_data')) {
            return $field_name;
        }

        $data = ntdst_data();
        if (!$data->isRegistered($post_type)) {
            return $field_name;
        }

        return $data->get($post_type)->getMetaPrefix() . $field_name;
    }
}

// Auto-initialize. UNCONDITIONAL, and that is the change T11 makes.
//
// The old wrapper — `class_exists('NTDST_Theme')` + `ntdst_get()` — never
// denied the picker. `class_exists` is always true (`ntdst-coreloader.php:29`
// requires Theme), and `ntdst_get()` AUTOWIRES an unregistered class, filling
// `array $config = []` (`Theme.php:79`) from its default. So on a Theme-less
// boot it did not skip — it CONSTRUCTED a phantom `NTDST_Theme([])`, which
// registers itself as the container's canonical Theme (`Theme.php:85`) and
// hooks `after_setup_theme` + both `*_enqueue_scripts` @ 9999
// (`Theme.php:120-127`): a site's Theme, as a side effect of building an admin
// picker. That is what came off with the parameter — do not reinstate it.
//
// The hook and priority are deliberately UNCHANGED (`after_setup_theme` @ 20):
// only the guard is removed, so registration order stays where it was.
add_action('after_setup_theme', function () {
    new NTDST_RelationField();
}, 20);
