<?php

/**
 * NTDST Data Layer — a chain API over WP_Query, and the meta registration a
 * declared model owes WordPress.
 *
 * The field VOCABULARY is not here. One table names every type and says what
 * sanitizes it, what it publishes as and what draws it, and that table lives in
 * api/FieldTypes.php — this file asks NTDST_FieldTypes::get() for an entry like
 * every other reader does (INV-8).
 *
 * THE LAYER HOLDS NO PERFORMANCE OPINION (T04). It sets no query flags of its
 * own, primes no caches, and keeps no cache of its own — WordPress already
 * caches post queries (`post-queries`, salted on `$last_changed`), post
 * objects, post meta and object terms, and invalidates all four on write. A
 * second cache on top of those was a second thing to be wrong, and measurement
 * showed it bought nothing this site ever observed: `resolveCacheTime()`
 * returned 0 on every `WP_DEBUG` environment, so the whole bespoke cache was
 * inert wherever a developer had ever looked at it.
 *
 * A consumer that needs a specific optimisation asks WordPress for it directly
 * — `update_post_thumbnail_cache()`, `wp_cache_*`, a `no_found_rows` arg passed
 * through this API — with a measurement attached, at the call site that can
 * justify it.
 */

defined('ABSPATH') || exit;

class NTDST_Data_Model
{
    /**
     * Friendly Data-API keys → wp_posts column they write to.
     *
     * The first four map to a `post_` prefix; everything else passes through.
     * Keys outside this list AND outside the schema are treated as unregistered
     * (logged via ntdst_log('data')->warning() and dropped).
     */
    public const WP_COLUMNS = [
        'title'                 => 'post_title',
        'content'               => 'post_content',
        'excerpt'               => 'post_excerpt',
        'post_status'           => 'post_status',
        'post_author'           => 'post_author',
        'post_parent'           => 'post_parent',
        'post_date'             => 'post_date',
        'post_date_gmt'         => 'post_date_gmt',
        'post_name'             => 'post_name',
        'menu_order'            => 'menu_order',
        'comment_status'        => 'comment_status',
        'ping_status'           => 'ping_status',
        'post_password'         => 'post_password',
        'post_content_filtered' => 'post_content_filtered',
        'to_ping'               => 'to_ping',
        'pinged'                => 'pinged',
    ];

    protected string $post_type;
    protected array $schema;
    protected array $query_args = [];
    protected array $sanitizers = [];
    protected array $validators = [];
    protected string $meta_prefix = '';

    /**
     * Reusable, named query fragments declared by this model — each a
     * `fn(NTDST_Data_Model $q, ...$args)` that NARROWS the builder (applies
     * CONSTRAINTS, never shape). Resolved model-first-then-global by scope().
     *
     * @var array<string, callable>
     */
    protected array $scopes = [];

    public function __construct(
        string $post_type,
        array $schema = [],
        string $meta_prefix = '',
        array $scopes = [],
    ) {
        $this->post_type = $post_type;
        $this->schema = $schema;
        $this->meta_prefix = $meta_prefix;
        $this->scopes = $scopes;

        // The declaration rules are the VOCABULARY's, and both callers ask it —
        // this constructor and NTDST_MetaboxGenerator::register(). A copy of a
        // rule is a second vocabulary (INV-8), and while the metabox kept none
        // at all, the declaration that fatally refused to register here was
        // accepted by a plain post type and surfaced to an editor instead.
        NTDST_FieldTypes::assertDeclarations($schema, "Model '{$post_type}'");

        $this->bindFields();
    }

    /**
     * Get the meta prefix for this model
     */
    public function getMetaPrefix(): string
    {
        return $this->meta_prefix;
    }

    /**
     * Get the schema configuration
     */
    public function getSchema(): array
    {
        return $this->schema;
    }

    /**
     * The one reading of the declaration: WordPress's key with WordPress's default,
     * OPT IN and strictly `=== true`, so `'yes'`, `1` and a bare type string all
     * leave the field private. It lives alone because the exposure rule rests on it.
     *
     * Private, and so is every reader of it: `schemaFor()` is reached only
     * through `registerRestMeta()`. A second PUBLIC way to ask what a field
     * publishes is a second exposure a consumer can assemble beside the
     * convergence point (INV-1).
     */
    private function declaresRest(mixed $config): bool
    {
        return is_array($config) && ($config['show_in_rest'] ?? false) === true;
    }

    /**
     * The fields a model declares may leave it, by WordPress's own key.
     *
     * `show_in_rest => true` on a field description, with WordPress's meaning:
     * OPT IN. A field nobody named does not leave. That is the same default
     * `register_post_meta()` uses, so this reads as it does everywhere else in
     * WordPress and can drive that registration without translation.
     *
     * A CEILING, not a shape — which of these an exposure actually emits is the
     * exposure's decision. This says only what is never on the table.
     *
     * @return list<string>
     */
    public function restFields(): array
    {
        $fields = [];

        foreach ($this->schema as $field => $config) {
            if ($this->declaresRest($config)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    /**
     * Hand every publishable declared field to WordPress's own meta registry.
     *
     * `$user_id` is WordPress's subject — map_meta_cap() names the user the write is
     * judged FOR, so current_user_can() would answer about the wrong person.
     * `$allowed` is ignored on purpose: honouring it would let WordPress's
     * protected-key heuristic decide who may write this model's meta. An array or
     * object value carries its whole closed schema, which register_post_meta()
     * requires. A declared field's sanitizer must be IDEMPOTENT — update_metadata()
     * applies it again to the value this registration already cleaned.
     *
     * A field with no publishable shape unpublishes only itself and says so, once
     * per model. There is no catch around the loop: a type name is resolved when
     * the model is CONSTRUCTED, so a typo is already a fatal at register() naming
     * the field, and nothing left here can throw at `init`.
     */
    public function registerRestMeta(string $postType): void
    {
        /** @var array<string, true> $warnedModels one warning per model per process */
        static $warnedModels = [];

        $refused = [];

        foreach ($this->restFields() as $field) {
            $refusal = null;
            $schema = $this->schemaFor($this->schema[$field] ?? null, $refusal, "Field '{$field}'");

            if ($schema === null) {
                $refused[] = sprintf(
                    '`%s` (%s%s)',
                    $field,
                    ($refusal['path'] ?? '') === '' ? '' : sprintf('sub-field `%s`: ', $refusal['path']),
                    (string) ($refusal['why'] ?? 'no publishable shape'),
                );

                continue;
            }

            $type = (string) $schema['type'];

            register_post_meta($postType, $this->prefixMetaKey($field), [
                'type' => $type,
                'single' => true,
                'sanitize_callback' => fn($value) => $this->sanitizeField($field, $value),
                // Untyped and cast on purpose: map_meta_cap() hands $object_id
                // and $user_id through from its own $args, where either is a
                // numeric string as often as an int, and a typed parameter
                // would fatal on one. A non-scalar is refused outright rather
                // than cast — (int) new stdClass is a coin flip that lands on
                // 1, a real user id. A cast that lands on 0 denies, which is
                // the direction to fail in.
                'auth_callback' => static fn($allowed, $meta_key, $post_id, $user_id): bool
                    => is_scalar($user_id)
                    && is_scalar($post_id)
                    && user_can((int) $user_id, 'edit_post', (int) $post_id),
                'show_in_rest' => in_array($type, ['array', 'object'], true)
                    ? ['schema' => $schema]
                    : true,
            ]);
        }

        if ($refused === [] || isset($warnedModels[$this->post_type])) {
            return;
        }

        $warnedModels[$this->post_type] = true;

        ntdst_log('data')->warning(
            sprintf(
                'Model "%s" declares REST field(s) that have no publishable shape, so they '
                . 'reach no /wp/v2 response: %s. Give a repeater sub_fields and declare every '
                . 'one of them, declare a keyed value as a repeater instead, or drop '
                . '`show_in_rest` from the field.',
                $this->post_type,
                implode('; ', $refused),
            ),
            ['model' => $this->post_type, 'fields' => $refused],
        );
    }

    /**
     * One config, one published shape — or null, meaning "this may not leave".
     *
     * ONE method for every depth, because the rule is the same at every depth and
     * the recursion IS the guard: a sub-field asks this about itself, and a null
     * anywhere below propagates all the way up. That is what makes a repeater
     * all-or-nothing. WordPress validates a stored row against the schema it was
     * given (class-wp-rest-meta-fields.php prepare_value), so a repeater published
     * without one of its keys reads back null, refuses a write carrying that key,
     * and drops it on a write that does not. Half a repeater is not half published;
     * it is broken.
     *
     * The STRUCTURAL rule is all this method holds. Each leaf's shape is the
     * vocabulary's — `NTDST_FieldTypes::get($type)->schema`, one table, asked
     * here and nowhere else (INV-8) — and a `null` there means the type is not
     * publishable at all: `json` (a blob names no sub-fields) and `array` (a
     * keyed map of typed values, whose stored rows read back null against any
     * leaf a closed schema could give them).
     *
     * Unpublishable therefore has three shapes: a leaf the vocabulary gives no
     * schema, a repeater with any undeclared sub-field at any depth, and a
     * repeater with no `sub_fields` at all — the last one is the partial case with
     * every key undeclared, and its empty closed object nulls its own stored rows.
     *
     * @param array{path: string, why: string}|null $refusal Set when null is returned.
     * @param string $where names the field (and the sub-field, at depth) if the
     *        vocabulary refuses the type — a message with no subject in it is a
     *        bug report nobody can act on.
     * @return array<string, mixed>|null
     */
    private function schemaFor(mixed $config, ?array &$refusal = null, string $where = 'A declared field'): ?array
    {
        $refusal = null;

        if (!$this->declaresRest($config)) {
            $refusal = ['path' => '', 'why' => 'it never declared `show_in_rest => true`'];

            return null;
        }

        $type = NTDST_FieldTypes::declaredType($config);

        // Ask the vocabulary what this name means, through the same resolver the
        // constructor used, so a name outside the 17 throws with the FIELD in the
        // message. (Construction already refused it; this cannot be reached on a
        // model that exists.)
        $entry = $this->typeFor($type, $where);

        if ($type === 'repeater') {
            $sub_fields = is_array($config['sub_fields'] ?? null) ? $config['sub_fields'] : [];

            // Absent, empty, or not a list at all: three ways to arrive with no
            // vocabulary, one verdict. `properties => []` with
            // `additionalProperties => false` names nothing and admits nothing, and
            // WordPress measures the stored rows against exactly that — they read
            // back null and the next write wipes them.
            if ($sub_fields === []) {
                $refusal = [
                    'path' => '',
                    'why'  => 'it declares no `sub_fields`, so it names nothing to publish',
                ];

                return null;
            }

            $properties = [];

            foreach ($sub_fields as $sub => $sub_config) {
                $inner = null;
                $sub_schema = $this->schemaFor($sub_config, $inner, $where . " sub-field '{$sub}'");

                if ($sub_schema === null) {
                    $refusal = [
                        'path' => ($inner['path'] ?? '') === ''
                            ? (string) $sub
                            : (string) $sub . '.' . $inner['path'],
                        'why'  => (string) ($inner['why'] ?? ''),
                    ];

                    return null;
                }

                // Keyed the way the cell is STORED (NTDST_FieldTypes::rowKey),
                // never by the raw declared name: WordPress measures a stored row
                // against this closed object, so a `salePrice` property against a
                // stored `saleprice` cell nulls every row of the field and refuses
                // the write that carries it — the partial-repeater failure, caused
                // by a capital letter in a declaration.
                $properties[NTDST_FieldTypes::rowKey((string) $sub)] = $sub_schema;
            }

            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'additionalProperties' => false,
                ],
            ];
        }

        // The leaf shape is the vocabulary's, never a second table's. `null`
        // there is a verdict, not a gap: nothing inside such a value was ever
        // named, so WordPress would measure every stored row against a leaf that
        // does not admit it and read it back as null. Refused instead, out loud.
        if ($entry->schema === null) {
            $refusal = [
                'path' => '',
                'why'  => sprintf(
                    '`%s` has no publishable leaf shape — it holds keyed, typed values that no '
                    . 'closed schema names, so every stored value would read back null',
                    $type,
                ),
            ];

            return null;
        }

        return $entry->schema;
    }

    /**
     * Bind every declared field to the vocabulary: its sanitizer and its
     * validation rules, in ONE walk, with the type name resolved once for both.
     *
     * What the vocabulary REFUSES is asked before this runs — the constructor
     * calls NTDST_FieldTypes::assertDeclarations(), which owns the four
     * declaration rules and answers for the metabox's register() too. So a
     * retired name, an invention, a cell-less type in a row and a sub-field
     * `sanitizer` have already failed at `init`, naming the field.
     *
     * The whole declaration is kept as the field's config — the sanitizer reads
     * `sub_fields`, `min`, `max` and the rest out of it.
     */
    private function bindFields(): void
    {
        foreach ($this->schema as $field => $config) {
            $field = (string) $field;
            $type = NTDST_FieldTypes::declaredType($config);

            $this->sanitizers[$field] = $this->sanitizerFor($field, $config, $type);

            if (!is_array($config)) {
                continue;
            }

            $this->validators[$field] = [
                'required' => $config['required'] ?? false,
                'min'      => $config['min'] ?? null,
                'max'      => $config['max'] ?? null,
                'validate' => $config['validate'] ?? null,
            ];
        }
    }

    /**
     * The sanitizer for one declared field: the registry's, then the field's own.
     *
     * A declared `sanitizer` COMPOSES on top of the registry's output — the
     * registry ALWAYS runs, and an override cannot replace it. The registry
     * always sees the raw input; the override's output is the consumer's. A
     * consumer, or a `ntdst/{model}/fields` filter, cannot switch wp_kses_post()
     * off on a `html` field and post markup through REST; what it then does with
     * the cleaned value is its own code, and this makes no claim that the answer
     * can only get stricter.
     *
     * An override must be IDEMPOTENT, like the entry it composes on:
     * register_post_meta() runs this callback again on the value it has already
     * cleaned, so one that appends or re-encodes grows the stored value on every
     * write. It runs on the way IN only — a read casts, it does not re-sanitize.
     */
    private function sanitizerFor(string $field, mixed $config, string $type): \Closure
    {
        $settings = is_array($config) ? $config : [];
        $sanitize = $this->typeFor($type, "Field '{$field}'")->sanitize;
        $override = $settings['sanitizer'] ?? null;

        if (!is_callable($override)) {
            return static fn($value) => $sanitize($value, $settings);
        }

        return static fn($value) => $override($sanitize($value, $settings));
    }

    /**
     * The vocabulary's entry for a name, with the declaration that asked for it.
     *
     * The registry's message says what to write instead; this says WHERE, and
     * on a site that fatal is the whole bug report.
     */
    private function typeFor(string $type, string $where): NTDST_FieldType
    {
        try {
            return NTDST_FieldTypes::get($type);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($where . ': ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * One value, cleaned as the field it is written to — the model's ONE answer
     * to "how does a key coming in get cleaned", asked by every write path.
     *
     * An undeclared key still gets sanitize_text_field(): not this model's field,
     * so not this model's type, but never stored exactly as it was posted.
     */
    protected function sanitizeField(string $field, $value)
    {
        if (!isset($this->sanitizers[$field])) {
            return sanitize_text_field($value);
        }

        // Always a Closure: bindFields() binds every declared field through
        // sanitizerFor() at construction, so there is no "not callable" case to
        // fall back from any more.
        return ($this->sanitizers[$field])($value);
    }

    /**
     * Sanitize all data based on schema
     */
    protected function sanitizeData(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (isset(self::WP_COLUMNS[$key])) {
                $sanitized[$key] = $this->sanitizeWpColumn($key, $value);
            } else {
                // Custom field - use schema sanitizer
                $sanitized[$key] = $this->sanitizeField($key, $value);
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize a wp_posts column value. wp_insert_post calls sanitize_post()
     * itself, so this only needs to cover keys that benefit from typed coercion
     * or stricter cleaning than WP's catch-all sanitizer.
     */
    protected function sanitizeWpColumn(string $key, $value)
    {
        return match ($key) {
            'title'                 => sanitize_text_field($value),
            'content'               => wp_kses_post($value),
            'excerpt'               => sanitize_textarea_field($value),
            'post_content_filtered' => wp_kses_post($value),
            'post_status'           => in_array($value, ['publish', 'draft', 'pending', 'private', 'trash', 'auto-draft', 'future'], true)
                ? $value
                : 'draft',
            'post_author', 'post_parent', 'menu_order' => (int) $value,
            'post_name'             => sanitize_title($value),
            'comment_status', 'ping_status' => in_array($value, ['open', 'closed', ''], true) ? $value : '',
            default                 => $value,
        };
    }

    /**
     * Warn when a caller passes keys that are neither WordPress columns nor
     * registered schema fields, and return the data WITHOUT them.
     *
     * The warning is the contract signal; what the caller does with the return
     * is the caller's. create()/update() take the filtered array and drop the
     * keys, so a typo surfaces as a clear "unregistered key" warning rather than
     * a confusing "required field missing" validation error. updateMeta() and
     * updateMetaBatch() take the WARNING only: a caller that names one meta key
     * means to write it, so it is stored — through sanitize_text_field(), the
     * same cleaning create() would have given it.
     */
    protected function warnUnregisteredKeys(array $data, string $operation): array
    {
        $allowed = array_flip(array_keys($this->schema)) + self::WP_COLUMNS;
        $unknown = array_diff_key($data, $allowed);

        if (empty($unknown)) {
            return $data;
        }

        ntdst_log('data')->warning(
            sprintf('Unregistered key(s) passed to %s on %s', $operation, $this->post_type),
            [
                'post_type' => $this->post_type,
                'keys'      => array_keys($unknown),
            ],
        );

        return array_diff_key($data, $unknown);
    }

    /**
     * Validate data based on schema rules
     *
     * @param array $data Data to validate
     * @param bool $isUpdate Whether this is an update operation. Relaxes the required
     *        check for OMITTED fields only — a required field that IS supplied must
     *        still be non-empty, on update as on create.
     * @return true|WP_Error True if valid, WP_Error if validation fails
     */
    protected function validateData(array $data, bool $isUpdate = false)
    {
        $errors = [];

        foreach ($this->validators as $field => $rules) {
            $has_value = array_key_exists($field, $data);
            $value = $has_value ? $data[$field] : null;
            $is_empty = $value === null || $value === '' || (is_array($value) && $value === []);

            // Check required. Two distinct refusals, and the distinction is the
            // whole point:
            //
            //   OMITTED on update  -> ALLOWED. Omission means "keep the existing
            //     value"; partial updates across the fleet depend on it. This is
            //     why the guard cannot simply be `$rules['required'] && $is_empty`.
            //   SUPPLIED as empty  -> REFUSED, on create AND update. The caller
            //     named the field and handed it '' | null | [], which is an
            //     instruction to blank a field the schema says may never be empty.
            //     `!$isUpdate` used to disable the guard for the WHOLE update path,
            //     so that instruction was obeyed silently — data loss on exactly
            //     the fields declared un-emptyable.
            //
            // `$has_value` (array_key_exists) is what separates the two: it is
            // true for an explicit null, which `isset()` would have missed.
            $missing_on_create  = !$has_value && !$isUpdate;
            $explicitly_emptied = $has_value && $is_empty;

            if ($rules['required'] && ($missing_on_create || $explicitly_emptied)) {
                $errors[$field][] = sprintf('%s is required', $field);
                continue;
            }

            // Skip validations for fields that are not part of a partial update.
            if (!$has_value) {
                continue;
            }

            // Optional empty values are allowed, but explicit false/0/'0' still validate below.
            if ($is_empty && !$rules['required']) {
                continue;
            }

            // Check min (for strings, numbers, arrays)
            if ($rules['min'] !== null) {
                if (is_string($value) && strlen($value) < $rules['min']) {
                    $errors[$field][] = sprintf('%s must be at least %d characters', $field, $rules['min']);
                } elseif (is_numeric($value) && $value < $rules['min']) {
                    $errors[$field][] = sprintf('%s must be at least %s', $field, $rules['min']);
                } elseif (is_array($value) && count($value) < $rules['min']) {
                    $errors[$field][] = sprintf('%s must have at least %d items', $field, $rules['min']);
                }
            }

            // Check max (for strings, numbers, arrays)
            if ($rules['max'] !== null) {
                if (is_string($value) && strlen($value) > $rules['max']) {
                    $errors[$field][] = sprintf('%s must be no more than %d characters', $field, $rules['max']);
                } elseif (is_numeric($value) && $value > $rules['max']) {
                    $errors[$field][] = sprintf('%s must be no more than %s', $field, $rules['max']);
                } elseif (is_array($value) && count($value) > $rules['max']) {
                    $errors[$field][] = sprintf('%s must have no more than %d items', $field, $rules['max']);
                }
            }

            // Custom validation callback
            if ($rules['validate'] && is_callable($rules['validate'])) {
                $result = call_user_func($rules['validate'], $value);
                if ($result !== true) {
                    $errors[$field][] = is_string($result) ? $result : sprintf('%s validation failed', $field);
                }
            }
        }

        if (!empty($errors)) {
            $error_messages = [];
            foreach ($errors as $field => $messages) {
                $error_messages[] = implode(', ', $messages);
            }
            return new WP_Error('validation_failed', implode('; ', $error_messages), ['errors' => $errors]);
        }

        return true;
    }

    /**
     * Create a new post
     *
     * @param array $data Post and meta data
     * @return object|WP_Error Post object or error
     */
    public function create(array $data)
    {
        $data = $this->warnUnregisteredKeys($data, 'create');

        // Validate input
        $validation = $this->validateData($data);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Sanitize input
        $data = $this->sanitizeData($data);

        // Fire before hook
        do_action('ntdst/model/creating', $this->post_type, $data);

        $post_id = wp_insert_post(array_merge(
            $this->extractPostData($data),
            ['post_type' => $this->post_type, 'post_status' => $data['post_status'] ?? 'publish'],
        ), true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Save meta data. Roll back the post if any meta write genuinely fails.
        foreach ($this->extractMetaData($data) as $key => $value) {
            if (!$this->updateMetaValue($post_id, $key, $value)) {
                wp_delete_post($post_id, true);
                return new WP_Error('meta_update_failed', sprintf('Failed to update meta field %s', $key), ['status' => 500]);
            }
        }

        // Fire after hook
        do_action('ntdst/model/created', $this->post_type, $post_id, $data);

        // Return the newly created post with meta/fields
        // 'any': create() must return the row it just wrote, whatever status
        // it was written with. Hydrating through the publish-only default would
        // make create(['post_status' => 'draft']) write the row and then return
        // WP_Error — the caller concludes nothing was written, and the row
        // orphans. Found by mutation before it shipped.
        return $this->find($post_id, 'any');
    }

    /**
     * Update an existing post
     *
     * @param int $id Post ID
     * @param array $data Data to update
     * @return object|WP_Error Post object or error
     */
    public function update(int $id, array $data)
    {
        // Check if post exists
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        $data = $this->warnUnregisteredKeys($data, 'update');

        // Validate input - isUpdate=true skips required field validation for missing fields
        $validation = $this->validateData($data, true);
        if (is_wp_error($validation)) {
            return $validation;
        }

        // Sanitize input
        $data = $this->sanitizeData($data);

        // Fire before hook
        do_action('ntdst/model/updating', $this->post_type, $id, $data);

        $post_data = $this->extractPostData($data);
        $previous_post_data = [];
        foreach (array_keys($post_data) as $post_field) {
            $previous_post_data[$post_field] = $existing->{$post_field} ?? null;
        }

        // Update post data
        if ($post_data) {
            $result = wp_update_post($post_data + ['ID' => $id], true);
            if (is_wp_error($result)) {
                return $result;
            }
        }

        $meta_data = $this->extractMetaData($data);
        $previous_meta = [];
        foreach ($meta_data as $key => $value) {
            $previous_meta[$key] = [
                'exists' => metadata_exists('post', $id, $key),
                'value' => get_post_meta($id, $key, true),
            ];

            if (!$this->updateMetaValue($id, $key, $value)) {
                $this->restorePostData($id, $previous_post_data);
                $this->restoreMetaData($id, $previous_meta);
                return new WP_Error('meta_update_failed', sprintf('Failed to update meta field %s', $key), ['status' => 500]);
            }
        }

        // Fire after hook
        do_action('ntdst/model/updated', $this->post_type, $id, $data);

        // Return fresh data. `wp_update_post()` / `update_post_meta()` already
        // cleaned core's post and post_meta caches, so this re-read is the
        // database's current answer without the layer arranging anything.
        // 'any' — same reason as create(): update() returns what it wrote.
        return $this->find($id, 'any');
    }

    /**
     * Find a post by ID
     *
     * Always the database's current answer: the layer keeps no cache of its
     * own, and core invalidates its post/post_meta entries on every write.
     * (T04 removed the `$skipCache` parameter with the cache it referred to.)
     *
     * @param int $id Post ID
     * @return object|WP_Error Post object or error
     */
    /**
     * Read one row by id.
     *
     * `$status` defaults to the SAME publish-only rule the query methods use.
     * It used to be status-blind while `get()` was publish-only — two read
     * paths on one ORM with opposite defaults, which is what taught consumers
     * the layer gated for them. It doesn't, consistently, and that
     * misunderstanding produced live disclosures in two site services.
     *
     * Pass an explicit `$status` (`['publish','draft']`, or `'any'`) when you
     * genuinely want unpublished rows — an admin screen does; a public read
     * does not. The layer does not decide that for you, it just stops
     * pretending it has.
     *
     * @param int          $id
     * @param string|array $status Post statuses to accept. Default: publish only.
     */
    public function find(int $id, $status = 'publish')
    {
        // `find($id, true)` used to mean "skip the cache". T04 deleted that
        // cache, and this parameter took its position — so a leftover call
        // would silently mean "accept the status `true`", which matches
        // nothing and denies every row. Fail-closed but invisible is the worst
        // shape available, so it fails LOUDLY instead.
        if (is_bool($status)) {
            throw new InvalidArgumentException(
                'find()\'s second argument is now a post status, not the removed '
                . '$skipCache flag. Pass a status (e.g. "any", "publish", '
                . '["publish","draft"]) or drop the argument.',
            );
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        if ($status !== 'any') {
            $accepted = (array) $status;
            if (!in_array($post->post_status, $accepted, true)) {
                // Same error as a missing row: a caller who may not see this
                // status learns nothing about whether it exists.
                return new WP_Error('not_found', 'Post not found', ['status' => 404]);
            }
        }

        $post->meta = $this->readPostMeta($id);
        $post->fields = $this->formatMeta($post->meta);

        return $post;
    }

    /**
     * Get meta value(s) for a post - convenience method with automatic error handling
     *
     * `$status` is the SAME argument, with the SAME default, as `find()`. That
     * is the whole point of it existing: this method used to hard-code `'any'`,
     * so `find($id)` refused an unpublished row while `getMeta($id, 'x')`
     * served its meta — one model, one row, two read paths, opposite answers.
     * A service that gated with the first and read with the second had a
     * bypass, and nothing about the call site looked wrong.
     *
     * That is the defect `find()`'s own docblock describes and claims to have
     * fixed ("two read paths on one ORM with opposite defaults... produced live
     * disclosures in two site services"). The fix landed one method up and
     * reintroduced itself one method down.
     *
     * One rule for the layer, so it can be held in the head: THE DEFAULT ANSWER
     * IS THE SAFE ONE. An admin screen that wants an unpublished row says so.
     *
     * @param int          $id
     * @param string|null  $key     Meta key (null = return all fields)
     * @param mixed        $default Returned when the row is absent, of another
     *                              type, NOT VISIBLE at $status, or the key is
     *                              unset
     * @param string|array $status  Post statuses to accept. Default: publish only.
     * @return mixed Meta value, all fields, or $default
     */
    public function getMeta(int $id, ?string $key = null, $default = null, $status = 'publish')
    {
        $post = $this->find($id, $status);

        // Return default on error
        if (is_wp_error($post)) {
            return $default;
        }

        $meta = $post->fields ?? [];

        // Return all meta if no key specified
        if ($key === null) {
            return $meta;
        }

        // Return specific key with default fallback
        return $meta[$key] ?? $default;
    }

    /**
     * Update a prefixed meta key and verify the write. WordPress returns false for both
     * failures and unchanged values, so confirm the stored value before treating false as an error.
     */
    protected function updateMetaValue(int $id, string $metaKey, $value): bool
    {
        $result = update_post_meta($id, $metaKey, $value);

        if ($result !== false) {
            return true;
        }

        return $this->valuesMatch(get_post_meta($id, $metaKey, true), $value);
    }

    /**
     * Restore post-table fields after a partial update failure.
     */
    protected function restorePostData(int $id, array $previousPostData): void
    {
        if (empty($previousPostData)) {
            return;
        }

        wp_update_post($previousPostData + ['ID' => $id], true);
    }

    /**
     * Restore meta fields after a partial update failure.
     */
    protected function restoreMetaData(int $id, array $previousMeta): void
    {
        foreach ($previousMeta as $key => $snapshot) {
            $exists = is_array($snapshot) ? ($snapshot['exists'] ?? true) : true;
            $value = is_array($snapshot) && array_key_exists('value', $snapshot) ? $snapshot['value'] : $snapshot;

            if ($exists) {
                update_post_meta($id, $key, $value);
            } else {
                delete_post_meta($id, $key);
            }
        }
    }

    /**
     * Compare stored and intended values after WordPress maybe_unserialize handling.
     */
    protected function valuesMatch($stored, $expected): bool
    {
        if ($stored === $expected) {
            return true;
        }

        // WP stores all meta as strings; the schema may sanitize to int/bool/etc.
        // Treat scalar values as matching when their string representations are equal,
        // so update_post_meta's "no-op" return-false doesn't trigger a batch rollback.
        if (is_scalar($stored) && is_scalar($expected) && (string) $stored === (string) $expected) {
            return true;
        }

        return maybe_serialize($stored) === maybe_serialize($expected);
    }

    /**
     * Update meta value for a post - convenience method with automatic error handling
     *
     * @param int $id Post ID
     * @param string $key Meta key (unprefixed - prefix applied automatically)
     * @param mixed $value Meta value
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function updateMeta(int $id, string $key, $value)
    {
        // Verify post exists
        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // The field's OWN bound closure — the one create(), update() and the
        // REST registration all use. Read off `$this->sanitizers`, never rebuilt
        // and never re-derived from the declaration: a field declared as a bare
        // string (`'body' => 'html'`) is bound like any other, and this path used
        // to store it raw.
        //
        // A key this model does not DECLARE gets create()'s answer, not a pass:
        // it is warned about by name and stored through sanitize_text_field().
        // One model must not have two answers for the same key, with the safe
        // one depending on which method the caller reached for.
        $this->warnUnregisteredKeys([$key => $value], 'updateMeta');
        $value = $this->sanitizeField($key, $value);

        $metaKey = $this->prefixMetaKey($key);
        if (!$this->updateMetaValue($id, $metaKey, $value)) {
            return new WP_Error('meta_update_failed', sprintf('Failed to update meta field %s', $metaKey), ['status' => 500]);
        }

        return true;
    }

    /**
     * Update multiple meta values for a post at once.
     *
     * This is the batch version of updateMeta() - use this when updating
     * multiple fields to avoid repeated validation.
     *
     * @param int $id Post ID
     * @param array<string, mixed> $data Associative array of key => value pairs (unprefixed)
     * @return bool True if all updates succeeded, false if any failed
     */
    public function updateMetaBatch(int $id, array $data): bool
    {
        // Verify post exists once
        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return false;
        }

        $previousMeta = [];

        // One warning for the whole batch, naming every key this model does not
        // declare; the values are still stored, cleaned the way updateMeta()
        // cleans them.
        $this->warnUnregisteredKeys($data, 'updateMetaBatch');

        foreach ($data as $key => $value) {
            // The same bound closure updateMeta() uses, for the same reason.
            $value = $this->sanitizeField($key, $value);

            $metaKey = $this->prefixMetaKey($key);
            $previousMeta[$metaKey] = [
                'exists' => metadata_exists('post', $id, $metaKey),
                'value' => get_post_meta($id, $metaKey, true),
            ];

            if (!$this->updateMetaValue($id, $metaKey, $value)) {
                $this->restoreMetaData($id, $previousMeta);
                return false;
            }
        }

        return true;
    }

    /**
     * Delete meta value for a post - convenience method
     *
     * @param int $id Post ID
     * @param string $key Meta key (unprefixed - prefix applied automatically)
     * @return bool|WP_Error True on success, WP_Error on failure
     */
    public function deleteMeta(int $id, string $key)
    {
        // Verify post exists
        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Delete meta with prefix
        $metaKey = $this->prefixMetaKey($key);
        $result = delete_post_meta($id, $metaKey);

        return $result;
    }

    /**
     * Delete a post
     *
     * @param int $id Post ID
     * @param bool $force Force delete (bypass trash)
     * @return bool|WP_Error Success or error
     */
    public function delete(int $id, bool $force = false)
    {
        // Check if post exists
        $existing = get_post($id);
        if (!$existing || $existing->post_type !== $this->post_type) {
            return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        }

        // Fire before hook
        do_action('ntdst/model/deleting', $this->post_type, $id);

        $result = $force ? wp_delete_post($id, true) : wp_trash_post($id);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
        }

        // Fire after hook
        do_action('ntdst/model/deleted', $this->post_type, $id);

        return true;
    }

    /**
     * Apply a named query scope — a reusable CONSTRAINT fragment.
     *
     * A scope is a `fn(NTDST_Data_Model $q, ...$args)` that NARROWS the builder
     * (calls the same `where*`/`orderBy`/`limit` chain and returns) — it never
     * changes result SHAPE. It cannot: shape is not this layer's question at
     * all any more, and the projection that used to sit beside scopes has moved
     * to the surface that exposes the rows. A scope decides WHICH ROWS; the
     * consumer decides WHICH KEYS.
     *
     * Resolution is MODEL-FIRST-THEN-GLOBAL: the model's own scopes are tried
     * before the global registry, so a model can shadow a global of the same
     * name with a stricter local rule.
     *
     * An unknown name FAILS LOUDLY (like NTDST_FieldTypes::get()'s throw on an
     * unknown type): a silent no-op would let a typo'd scope name drop the very
     * constraint it was meant to add — a fail-open a public read cannot afford.
     */
    public function scope(string $name, ...$args): self
    {
        $scope = $this->scopes[$name] ?? NTDST_Data_Manager::getScope($name);

        if (!is_callable($scope)) {
            throw new InvalidArgumentException(
                "Unknown query scope '{$name}' on '{$this->post_type}'. Declare it in the "
                . "model's 'scopes' config, or register it globally via "
                . 'NTDST_Data_Manager::addScope().',
            );
        }

        $scope($this, ...$args);

        return $this;
    }

    /**
     * Query builder - where clause
     */
    public function where(string $field, $value): self
    {
        // List of WordPress core post table fields that should be queried directly
        $core_fields = [
            'post_status', 'post_author', 'post_parent', 'post_type',
            'post_date', 'post_modified', 'menu_order', 'comment_status',
            'ping_status', 'post_password', 'post_name', 'post_mime_type',
        ];

        if (in_array($field, $core_fields)) {
            // Core WordPress field - add directly to query_args
            // Map post_name to 'name' for WP_Query compatibility.
            $queryKey = ($field === 'post_name') ? 'name' : $field;
            $this->query_args[$queryKey] = $value;
        } else {
            // Custom meta field - use meta_query with prefix
            if (!isset($this->query_args['meta_query'])) {
                $this->query_args['meta_query'] = [];
            }

            $metaKey = $this->prefixMetaKey($field);
            $this->query_args['meta_query'][] = is_array($value) && count($value) === 2
                ? ['key' => $metaKey, 'value' => $value[1], 'compare' => $value[0]]
                : ['key' => $metaKey, 'value' => $value];
        }

        return $this;
    }

    /**
     * Query builder - where NOT clause
     *
     * For core fields: uses post__not_in for IDs, or excludes via post_status array.
     * For meta fields: uses meta_query with != compare.
     */
    public function whereNot(string $field, $value): self
    {
        // List of WordPress core post table fields
        $core_fields = [
            'post_status', 'post_author', 'post_parent', 'post_type',
            'post_date', 'post_modified', 'menu_order', 'comment_status',
            'ping_status', 'post_password', 'post_name', 'post_mime_type',
        ];

        if (in_array($field, $core_fields)) {
            // Handle core field negation
            if ($field === 'post_status') {
                // For post_status, we need to explicitly include all OTHER statuses
                $all_statuses = ['publish', 'draft', 'pending', 'private', 'future', 'inherit'];
                $exclude = is_array($value) ? $value : [$value];
                $this->query_args['post_status'] = array_diff($all_statuses, $exclude);
            } elseif ($field === 'post_author') {
                // For author, use author__not_in
                $this->query_args['author__not_in'] = is_array($value) ? $value : [$value];
            } elseif ($field === 'post_parent') {
                // For parent, use post_parent__not_in
                $this->query_args['post_parent__not_in'] = is_array($value) ? $value : [$value];
            } else {
                // Other core fields - WP_Query doesn't support != for these
                // Throw exception to fail loudly rather than silently returning wrong results
                $this->query_args = [];
                throw new \InvalidArgumentException(
                    "whereNot() does not support negation for core field '{$field}'. "
                    . "Supported fields: post_status, post_author, post_parent. "
                    . "For other fields, use a custom meta field or filter results in PHP.",
                );
            }
        } else {
            // Custom meta field - use meta_query with != compare
            if (!isset($this->query_args['meta_query'])) {
                $this->query_args['meta_query'] = [];
            }

            $this->query_args['meta_query'][] = [
                'key' => $this->prefixMetaKey($field),
                'value' => $value,
                'compare' => '!=',
            ];
        }

        return $this;
    }

    /**
     * Query builder - where IN clause (for post IDs)
     *
     * @param string $field Field name ('ID' for post IDs)
     * @param array $values Array of values
     * @return self
     *
     * Example:
     * $model->whereIn('ID', [1, 2, 3])->get();
     */
    public function whereIn(string $field, array $values): self
    {
        if ($field === 'ID') {
            $this->query_args['post__in'] = array_map('intval', $values);
        } else {
            // For meta fields, use meta_query with IN comparison and prefix
            if (!isset($this->query_args['meta_query'])) {
                $this->query_args['meta_query'] = [];
            }

            $this->query_args['meta_query'][] = [
                'key' => $this->prefixMetaKey($field),
                'value' => $values,
                'compare' => 'IN',
            ];
        }

        return $this;
    }

    /**
     * Query builder - limit
     */
    public function limit(int $limit): self
    {
        $this->query_args['posts_per_page'] = $limit;
        return $this;
    }

    /**
     * Query builder - order by
     *
     * Supports both core WP fields (date, title, menu_order, etc.)
     * and custom meta fields (which will be prefixed automatically).
     *
     * @param string $field Field to order by
     * @param string $dir Direction: ASC or DESC
     * @param bool $numeric Use numeric ordering for meta values (meta_value_num)
     * @return self
     */
    public function orderBy(string $field, string $dir = 'DESC', bool $numeric = false): self
    {
        // Common WordPress column aliases → WP_Query orderby values
        // These map actual database column names to WP_Query's expected values
        $columnAliases = [
            'post_date' => 'date',
            'post_modified' => 'modified',
            'post_title' => 'title',
            'post_name' => 'name',
            'post_author' => 'author',
            'post_parent' => 'parent',
            'post_type' => 'type',
        ];

        // Core WordPress orderby values that don't need meta handling
        $coreOrderBy = [
            'none', 'ID', 'author', 'title', 'name', 'type', 'date',
            'modified', 'parent', 'rand', 'comment_count', 'relevance',
            'menu_order', 'meta_value', 'meta_value_num', 'post__in',
            'post_name__in', 'post_parent__in',
        ];

        // Apply alias mapping if applicable
        $orderByField = $columnAliases[$field] ?? $field;

        if (in_array($orderByField, $coreOrderBy, true)) {
            $this->query_args['orderby'] = $orderByField;
        } else {
            // Custom meta field - set up meta ordering with prefix
            $this->query_args['meta_key'] = $this->prefixMetaKey($field);
            $this->query_args['orderby'] = $numeric ? 'meta_value_num' : 'meta_value';
        }

        $this->query_args['order'] = strtoupper($dir);
        return $this;
    }

    /**
     * Query builder - taxonomy where clause
     *
     * @param string $taxonomy Taxonomy name
     * @param string|int|array $terms Term slug, ID, or array of slugs/IDs
     * @param string $field Field to match (term_id, slug, name) - default: slug
     * @param string $operator Operator (IN, NOT IN, AND) - default: IN
     * @return $this
     *
     * Example:
     * $model->whereTax('category', 'web-design')->get();
     * $model->whereTax('category', ['web-design', 'mobile'], 'slug', 'AND')->get();
     */
    public function whereTax(string $taxonomy, $terms, string $field = 'slug', string $operator = 'IN'): self
    {
        if (!isset($this->query_args['tax_query'])) {
            $this->query_args['tax_query'] = [];
        }

        $this->query_args['tax_query'][] = [
            'taxonomy' => $taxonomy,
            'field' => $field,
            'terms' => is_array($terms) ? $terms : [$terms],
            'operator' => strtoupper($operator),
        ];

        return $this;
    }

    /**
     * Include post meta in results
     *
     * @return self
     *
     * Example:
     * $posts = $model->withMeta()->get();
     */
    public function withMeta(): self
    {
        $this->query_args['include_meta'] = true;
        return $this;
    }

    /**
     * Include taxonomy terms in results
     *
     * @return self
     *
     * Example:
     * $posts = $model->withTerms()->get();
     */
    public function withTerms(): self
    {
        $this->query_args['include_terms'] = true;
        return $this;
    }

    /**
     * Execute query and get results
     */
    public function get(): array
    {
        try {
            return $this->projectRowsMeta($this->queryRows(array_merge([
                'post_type' => $this->post_type,
            ], $this->query_args)));
        } finally {
            $this->query_args = [];
        }
    }

    /**
     * Run each row's `meta` bag through the model's declared schema.
     *
     * THE MISSING HALF OF THE READ SURFACE. `formatMeta()` has always given
     * `find()` a projected shape under `->fields`; the query builder had NO
     * projected form at all, only the raw bag `queryRows()` builds from
     * `readPostMeta()`. So a service writing a list handler could not obtain a
     * safe shape from `get()` — its only options were the raw bag, or
     * re-reading every row through `find()`.
     *
     * That is a missing API, not a discipline problem, and the repo proves it:
     * `ProjectService` is the one correct list handler and being correct cost
     * it an N+1 (it discards the rows and re-fetches each one), while
     * `DiscographyService` and `TourService` did the obvious thing, returned
     * the rows, and leaked every undeclared meta key to anonymous callers.
     *
     * THE KEY IS DELIBERATELY STILL `meta`, not `fields`. Renaming it to match
     * `find()`'s vocabulary was the plan and was rejected on evidence: a survey
     * across daan, stride and stride-output-reshape found 13 consumers reading
     * `['meta']`, two of which would have broken SILENTLY AND FAIL-OPEN —
     * `TourService::excludeCancelled()` (cancelled gigs reappear publicly) and
     * stride's `exclude_from_catalog` filter. A cleanup whose likeliest failure
     * mode is a silent security regression is not a cleanup. Every consumer
     * reading a DECLARED field keeps working; only undeclared keys disappear,
     * which is exactly the set that should never have been there.
     *
     * `formatMeta()` also resolves `meta_prefix`, so a prefixed model now reads
     * back under its unprefixed schema names — the same keys `find()` reports.
     * Hand-rolled filters over the raw bag got that wrong and narrowed to
     * nothing, which looks like "no data" rather than a bug.
     *
     * KNOWN AND DELIBERATE: a model with NO declared schema passes through
     * unprojected. There is no declaration to project through, so `withMeta()`
     * on such a model is exactly the raw request it appears to be. Registering
     * a schema is what turns the projection on.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    protected function projectRowsMeta(array $rows): array
    {
        if ($this->schema === []) {
            return $rows;
        }

        foreach ($rows as $index => $row) {
            if (isset($row['meta']) && is_array($row['meta'])) {
                $rows[$index]['meta'] = $this->formatMeta($row['meta']);
            }
        }

        return $rows;
    }

    /**
     * Get first result as a WP_Post with model meta/fields attached, matching find().
     */
    public function first()
    {
        $results = $this->limit(1)->get();
        if (!$results) {
            return null;
        }

        return $this->hydratePostFromResult($results[0]);
    }

    /**
     * Get all results
     */
    public function all(int $limit = -1): array
    {
        return $this->limit($limit)->get();
    }

    /**
     * Count results
     */
    public function count(): int
    {
        try {
            return $this->countMatching($this->query_args);
        } finally {
            $this->query_args = [];
        }
    }

    /**
     * Paginate results
     *
     * @param int $page Current page (1-indexed)
     * @param int $per_page Items per page
     * @return array ['data' => [], 'pagination' => [...]]
     */
    public function paginate(int $page = 1, int $per_page = 10): array
    {
        $page = max(1, $page);
        $per_page = max(1, $per_page);
        $offset = ($page - 1) * $per_page;

        try {
            // Get total count first
            $total = $this->countMatching($this->query_args);
            $total_pages = (int) ceil($total / $per_page);

            // Get paginated results
            $this->query_args['posts_per_page'] = $per_page;
            $this->query_args['offset'] = $offset;

            $posts = $this->projectRowsMeta($this->queryRows(array_merge([
                'post_type' => $this->post_type,
            ], $this->query_args)));

            return [
                'data' => $posts,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $per_page,
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'from' => $total > 0 ? $offset + 1 : 0,
                    'to' => min($offset + $per_page, $total),
                ],
            ];
        } finally {
            $this->query_args = [];
        }
    }

    /**
     * Count matching posts.
     *
     * `no_found_rows => false` is not a performance flag — it is what makes
     * `found_posts` exist at all, which is the number being asked for. Core's
     * own `post-queries` cache serves the repeat call.
     */
    protected function countMatching(array $query_args): int
    {
        unset($query_args['include_meta'], $query_args['include_terms']);

        $count_args = array_merge([
            'post_type' => $this->post_type,
        ], $query_args, [
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => false,
        ]);

        $query = new WP_Query($count_args);

        return (int) $query->found_posts;
    }

    /**
     * Hydrate a formatted query result into the same WP_Post shape returned by find().
     */
    protected function hydratePostFromResult(array $item)
    {
        $id = (int) ($item['id'] ?? $item['ID'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $post = get_post($id);
        if (!$post || $post->post_type !== $this->post_type) {
            return null;
        }

        // Read raw meta rather than reusing `$item['meta']`. Since get() now
        // projects that bag through the schema, reusing it would make first()'s
        // `->meta` the PROJECTED set while find()'s `->meta` stays raw — a new
        // asymmetry between two methods documented as returning the same shape.
        // One cache read (already primed by the query above) buys exact parity.
        $post->meta = $this->readPostMeta($id);
        $post->fields = $this->formatMeta($post->meta);
        if (isset($item['terms'])) {
            $post->terms = $item['terms'];
        }

        return $post;
    }

    /**
     * Extract WordPress post-table data from input, keyed for wp_insert_post /
     * wp_update_post (i.e. mapped to their `post_*` column names where needed).
     */
    protected function extractPostData(array $data): array
    {
        $post = [];
        foreach (self::WP_COLUMNS as $inputKey => $columnName) {
            if (array_key_exists($inputKey, $data)) {
                $post[$columnName] = $data[$inputKey];
            }
        }
        return $post;
    }

    /**
     * Extract custom meta data from input.
     *
     * Filters out WordPress post-table fields, keeping only meta fields.
     * Applies meta_prefix if configured.
     */
    protected function extractMetaData(array $data): array
    {
        $meta = array_diff_key($data, self::WP_COLUMNS);

        // Apply prefix if configured
        if ($this->meta_prefix !== '') {
            $prefixed = [];
            foreach ($meta as $key => $value) {
                $prefixed[$this->meta_prefix . $key] = $value;
            }
            return $prefixed;
        }

        return $meta;
    }

    /**
     * Add meta prefix to a key
     */
    protected function prefixMetaKey(string $key): string
    {
        return $this->meta_prefix . $key;
    }

    /**
     * Strip meta prefix from a key
     */
    protected function stripMetaPrefix(string $key): string
    {
        if ($this->meta_prefix !== '' && str_starts_with($key, $this->meta_prefix)) {
            return substr($key, strlen($this->meta_prefix));
        }
        return $key;
    }

    /**
     * Format meta according to schema — a READ, so it casts and decodes; it does
     * not re-sanitize and it never looks anything up.
     *
     * A stored value was already cleaned on the way in, by this same model's
     * sanitizer. What a read owes is the declared PHP type — a `bool` stored as
     * "1" reads back true, an `int` stored as "-3" reads back -3 — and the
     * decoding of the shapes WordPress hands back as storage rather than as
     * values. Cost is the reason it stops there: find() runs this once per row,
     * so a 50-row withMeta() list must not buy 50 attachment lookups.
     *
     * Handles meta_prefix: looks up prefixed keys in raw meta,
     * returns unprefixed keys in formatted result.
     */
    protected function formatMeta(array $meta): array
    {
        if (empty($this->schema)) {
            // No schema - strip prefixes from all keys if prefix is set
            if ($this->meta_prefix !== '') {
                $unprefixed = [];
                foreach ($meta as $key => $value) {
                    $unprefixed[$this->stripMetaPrefix($key)] = $value;
                }
                return $unprefixed;
            }
            return $meta;
        }

        $formatted = [];
        foreach ($this->schema as $field => $type_config) {
            // Look up the prefixed key in meta, return unprefixed field name
            $metaKey = $this->meta_prefix . $field;
            $value = $meta[$metaKey] ?? $meta[$field] ?? null;
            $formatted[$field] = $this->readValue($type_config, $value);
        }

        return $formatted;
    }

    /**
     * One stored value, read back as its declared type: the entry's own `read`,
     * or its sanitizer when it declares none. A type owns how it is written and
     * how it is read, in one row of one table, so the model keeps no decoder of
     * its own that could disagree with the entry that wrote the value (INV-8).
     */
    private function readValue(mixed $config, mixed $value): mixed
    {
        $entry = NTDST_FieldTypes::get(NTDST_FieldTypes::declaredType($config));

        return ($entry->read ?? $entry->sanitize)($value, is_array($config) ? $config : []);
    }

    /**
     * Read a post's meta as a flat key => value map.
     *
     * Prefers core's `post_meta` cache — primed by `WP_Query` on any read and
     * invalidated by core on any write — and falls back to one SQL statement
     * when it is cold. The layer stores nothing of its own either way (T04).
     *
     * @param int $post_id Post ID
     * @return array Post meta data
     */
    private function readPostMeta(int $post_id): array
    {
        $wp_cached = wp_cache_get($post_id, 'post_meta');
        if ($wp_cached !== false && is_array($wp_cached)) {
            $meta = [];
            foreach ($wp_cached as $meta_key => $values) {
                // WordPress stores meta as array of values, we want single value
                $meta[$meta_key] = maybe_unserialize($values[0] ?? $values);
            }

            return $meta;
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d",
            $post_id,
        ));

        $meta = [];
        foreach ($results as $row) {
            $meta[$row->meta_key] = maybe_unserialize($row->meta_value);
        }

        return $meta;
    }

    /**
     * Read a post's terms, grouped by taxonomy and reduced to id/name/slug.
     *
     * Prefers core's `{$taxonomy}_relationships` cache — primed by `WP_Query`
     * and invalidated by core on any term write — and falls back to one SQL
     * statement when it is cold. Stores nothing of its own (T04).
     *
     * @param int $post_id Post ID
     * @param string $post_type Post type for taxonomy lookup
     * @return array Post terms grouped by taxonomy
     */
    private function readPostTerms(int $post_id, string $post_type): array
    {
        $taxonomies = get_object_taxonomies($post_type);
        $terms = [];

        // Did the cache answer for EVERY taxonomy of this type? wp_cache_get()
        // says `false` on a miss and an array — possibly EMPTY — on a hit, so
        // "no terms" and "not cached" are different answers and only this flag
        // can tell them apart. A type with no taxonomies at all answers
        // nothing, so it is not a complete answer either.
        $cache_answered = $taxonomies !== [];

        foreach ($taxonomies as $taxonomy) {
            $wp_cached = wp_cache_get($post_id, "{$taxonomy}_relationships");
            if ($wp_cached === false || !is_array($wp_cached)) {
                $cache_answered = false;
            }

            if ($wp_cached !== false && is_array($wp_cached)) {
                foreach ($wp_cached as $term) {
                    if (!is_object($term)) {
                        $term = get_term((int) $term, $taxonomy);
                    }

                    if (is_object($term) && !is_wp_error($term)) {
                        $terms[$taxonomy][] = [
                            'id'   => (int) $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                        ];
                    }
                }
            }
        }

        // An EMPTY bag from a complete cache is a real answer, and returning it
        // is the point: the old test was `if (!empty($terms))`, which cannot
        // distinguish "this post has no terms" from "nothing was cached" — so
        // every untagged post took the three-way JOIN below on every single
        // read, forever, to be told again that there is nothing to tell.
        if ($cache_answered || !empty($terms)) {
            return $terms;
        }

        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT t.term_id, t.name, t.slug, tt.taxonomy
             FROM {$wpdb->term_relationships} tr
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE tr.object_id = %d",
            $post_id,
        ));

        $terms = [];
        foreach ($results as $term) {
            $terms[$term->taxonomy][] = [
                'id'   => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
            ];
        }

        return $terms;
    }

    /**
     * Run a WP_Query with core's defaults and format the rows.
     *
     * That is the whole job, and the name says so (core-shape T04 renamed this
     * from `getPostsFast()`, which advertised a speed property it did not have
     * — the priming it did was itself the cost, and it is gone).
     *
     * PRIVATE, AND THAT IS THE POINT (core-trim FR-4). This was
     * `NTDST_Data_Manager::getFormattedPosts()`, a public static with a global
     * front door, so any caller could obtain rows without naming a model —
     * and therefore without the schema that says what the rows mean. The chain
     * is the one way in now, and it enters here after the builder has
     * assembled the arguments and before `projectRowsMeta()` reads the bag
     * back through the declaration.
     *
     * The only defaults set here are the SHAPE of the answer: which type,
     * which status, how many, what order. Everything WordPress decides about
     * priming, counting and caching, WordPress keeps deciding — a caller who
     * wants `no_found_rows` or a warm thumbnail cache asks for it here, at a
     * call site that can justify it.
     *
     * @param array $args Query arguments (WP_Query compatible)
     * @return array Array of post data
     */
    private function queryRows(array $args = []): array
    {
        // Extract custom args
        $include_meta = (bool) ($args['include_meta'] ?? false);
        $include_terms = (bool) ($args['include_terms'] ?? false);

        // Remove custom args so WP_Query doesn't get confused
        unset($args['include_meta'], $args['include_terms']);

        // Set defaults
        $defaults = [
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'orderby' => 'date',
            'order' => 'DESC',
            'ignore_sticky_posts' => true, // Skip sticky posts logic
            'fields' => '', // Get all fields (important!)
        ];

        $args = wp_parse_args($args, $defaults);

        // CRITICAL FIX: Convert 'p' parameter to 'post__in' for non-public post types
        // WP_Query with 'p' parameter has issues with non-public/non-publicly-queryable post types
        if (isset($args['p']) && $args['p']) {
            $args['post__in'] = [(int) $args['p']];
            $args['posts_per_page'] = 1;
            unset($args['p']);
        }

        // WP_Query primes the post, meta and term caches for this result set
        // itself — its `update_post_meta_cache` / `update_post_term_cache`
        // defaults are TRUE, and T04 stopped overriding them. Nothing is
        // primed here on top of that.
        //
        // The thumbnail prime is gone with them. Core does not prime thumbnails
        // by default; a consumer that needs it calls
        // `update_post_thumbnail_cache()` itself. So is the author prime, which
        // ran `get_users(['include' => $author_ids])` unconditionally — for
        // rows with `post_author = 0` that is `WHERE ID IN (0)`, a statement
        // per read that can never return anything. `get_the_author_meta()`
        // below reads core's user cache and answers the same question.
        $query = new WP_Query($args);
        $raw_posts = $query->posts;

        if (empty($raw_posts)) {
            return [];
        }

        // Format results
        $posts = [];
        foreach ($raw_posts as $post) {
            // A post password is enforced by WordPress at the DISPLAY layer —
            // `the_content` swaps the body for `get_the_password_form()` when
            // `post_password_required()` says so. `WP_Query` returns the row
            // either way, so a projection that reads `post_content` off the
            // row publishes precisely what the password withholds. Reproduced
            // anonymously over the wire on 2026-08-07 before this line existed.
            //
            // The two predicates are core's own and are deliberately different:
            // the REDACTION asks whether THIS viewer has supplied the password
            // (so a reader who has stays served), while the `protected` MARKER
            // states whether the post has one at all. Both are copied from
            // `WP_REST_Posts_Controller::prepare_item_for_response()` rather
            // than reasoned out again here — the layer's job is to ask
            // WordPress its question, not to re-derive the answer.
            $requires_password = post_password_required($post);

            $post_data = [
                'id' => (int) $post->ID,
                'title' => $post->post_title,
                // Fallback excerpt generation. Withheld with the body it trims:
                // an excerpt derived from protected content leaks the same text.
                'excerpt' => $requires_password
                    ? ''
                    : ($post->post_excerpt ?: wp_trim_words(strip_tags($post->post_content), 55)),
                'content' => $requires_password ? '' : $post->post_content,
                'protected' => (bool) $post->post_password,
                'permalink' => get_permalink($post->ID),
                'slug' => $post->post_name,
                // ISO 8601 date format for consistency
                'date' => mysql2date('c', $post->post_date),
                'modified' => mysql2date('c', $post->post_modified),
                'author' => [
                    'id' => (int) $post->post_author,
                    'name' => get_the_author_meta('display_name', $post->post_author),
                ],
            ];

            $thumbnail_id = get_post_thumbnail_id($post->ID);
            if ($thumbnail_id) {
                $post_data['thumbnail'] = [
                    'id' => $thumbnail_id,
                    'url' => wp_get_attachment_image_url($thumbnail_id, 'medium'),
                    'full' => wp_get_attachment_image_url($thumbnail_id, 'full'),
                ];
            } else {
                $post_data['thumbnail'] = null;
            }

            // Include post meta if requested (served from core's primed cache)
            if ($include_meta) {
                $post_data['meta'] = $this->readPostMeta($post->ID);
            }

            // Include taxonomy terms if requested (served from core's primed cache).
            // Keyed off the ROW's own type, not the query's: `post_type` may be
            // an array, and readPostTerms() takes one type.
            if ($include_terms) {
                $post_data['terms'] = $this->readPostTerms($post->ID, $post->post_type);
            }

            $posts[] = $post_data;
        }

        return $posts;
    }
}

/**
 * Data Manager - Registry for data models
 */
class NTDST_Data_Manager
{
    protected static array $models = [];

    /**
     * Global query scopes — applicable to ANY model, resolved by
     * NTDST_Data_Model::scope() only AFTER the model's own scopes (a model
     * shadows a global of the same name). Static, like $models.
     *
     * @var array<string, callable>
     */
    protected static array $globalScopes = [];

    /**
     * Register a global query scope, applicable to any model.
     *
     * The MECHANISM the operator asked for; no production global scope is
     * registered on top of it (nothing needs one yet). A model-local scope
     * declared in its `scopes` config is preferred over a global of the same
     * name.
     */
    public static function addScope(string $name, callable $scope): void
    {
        self::$globalScopes[$name] = $scope;
    }

    /**
     * Resolve a global scope by name, or null when none is registered.
     */
    public static function getScope(string $name): ?callable
    {
        return self::$globalScopes[$name] ?? null;
    }

    /**
     * Log a registration failure at the point it happens.
     *
     * INV-4 says register() must never swallow the failure, and it doesn't — it
     * returns the WP_Error. But register() is called for its SIDE EFFECT from
     * service constructors, so in practice every call site in this codebase
     * discards the return (6/6 in daan-core, verified). The contract was
     * therefore being defeated by the API's shape rather than by any one
     * caller's carelessness, and a model that failed to register vanished
     * without a trace.
     *
     * Logging HERE makes fail-loud hold regardless of caller discipline. The
     * return value is deliberately unchanged: callers that do check keep
     * working, and this is purely additive.
     */
    private function logRegistrationFailure(string $name, string $stage, WP_Error $error): void
    {
        ntdst_log('data')->error(
            sprintf('Failed to register %s for model "%s": %s', $stage, $name, $error->get_error_message()),
            [
                'model' => $name,
                'stage' => $stage,
                'code'  => $error->get_error_code(),
            ],
        );
    }

    /**
     * A declaration that reaches no /wp/v2 response, said out loud.
     *
     * Refusing is right; refusing SILENTLY is not — the module asked for
     * `show_in_rest => true` and got nothing back, with no way to find out short of
     * reading /wp/v2 and guessing. The layer fails closed AND loudly, so name the
     * model and say which half is missing.
     *
     * Once per model per process: registration runs on every `init`, and the same
     * warning on every request is noise nobody reads.
     *
     * @param list<string> $declared
     */
    private function warnDeclarationGoesNowhere(string $name, array $declared, string $reason): void
    {
        /** @var array<string, true> $warned */
        static $warned = [];

        if (isset($warned[$name])) {
            return;
        }

        $warned[$name] = true;

        ntdst_log('data')->warning(
            sprintf(
                'Model "%s" declares %d REST field(s) that reach no /wp/v2 response: %s. Fix that, '
                . 'or drop `show_in_rest` from the fields that cannot be published.',
                $name,
                count($declared),
                $reason,
            ),
            [
                'model'  => $name,
                'fields' => $declared,
            ],
        );
    }

    /**
     * Register a new data model
     *
     * @param  string $name   Post type name
     * @param  array  $config Configuration
     * @return NTDST_Data_Model|WP_Error `WP_Error` when register_post_type()
     *         refuses the name — the caller gets the failure rather than a
     *         model whose post type does not exist (INV-4).
     */
    public function register(string $name, array $config = [])
    {
        // Fire hook so services can add their filters (e.g., PriceableFieldsService)
        do_action('ntdst/model/registering', $name, $config);

        // Apply field and field_group filters (allows services to inject fields)
        $config['fields'] = apply_filters("ntdst/{$name}/fields", $config['fields'] ?? []);
        $config['field_groups'] = apply_filters("ntdst/{$name}/field_groups", $config['field_groups'] ?? []);

        // Built BEFORE the post type: `supports` has to know whether anything opted
        // in, and restFields() is the one place that is answered. Nothing is stored yet.
        $model = new NTDST_Data_Model(
            $name,
            $config['fields'] ?? [],
            $config['meta_prefix'] ?? '',
            $config['scopes'] ?? [],
        );

        $declared = $model->restFields();

        if (isset($config['label'])) {
            // PRIVATE BY DEFAULT. Silence is not privacy: this used to merge the
            // caller's config OVER `public => true, has_archive => true`, so a
            // model registered without visibility flags was published, archived
            // and queryable. That default is why every non-public CPT on this
            // codebase had to state six denials by hand, and why forgetting one
            // was a disclosure. Opt IN to public; never opt out of it.
            $args = array_merge([
                'public'       => false,
                'has_archive'  => false,
                'supports'     => ['title', 'editor', 'thumbnail'],
            ], array_diff_key($config, array_flip([
                'fields',
                'field_groups',
                'meta_prefix',
                'auto_metabox',
                'scopes',
                'taxonomies',
            ])));

            // `custom-fields` is the switch that makes WordPress emit `meta` at all,
            // so the support follows the declaration, and is added at most once. A
            // string is normalised to a list because WordPress takes `array|false`
            // and add_supports() foreaches it — a string loses every support silently.
            if ($declared !== []) {
                $supports = $args['supports'] ?? [];
                $supports = is_array($supports)
                    ? $supports
                    : (is_string($supports) && $supports !== '' ? [$supports] : []);

                if (!in_array('custom-fields', $supports, true)) {
                    $supports[] = 'custom-fields';
                }

                $args['supports'] = $supports;
            }

            $registered = register_post_type($name, $args);

            // Never swallow the failure. register_post_type() returns WP_Error
            // for an invalid name or a reserved key; the old code discarded it
            // and built the model anyway, leaving isRegistered() true while
            // post_type_exists() was false — a half-registered phantom that
            // reports healthy. INV-4: fail closed, and loudly.
            if (is_wp_error($registered)) {
                $this->logRegistrationFailure($name, 'post type', $registered);
                return $registered;
            }

            // Taxonomies declared WITH the model, collapsing the hand-rolled
            // register_taxonomy() calls the services used to carry. Each is
            // attached to THIS model's post type ($name) and registered only
            // AFTER register_post_type() above succeeded. registerTaxonomy()
            // holds the shared defaults and seeds any `terms` idempotently; a
            // registration failure is returned, never swallowed (INV-4).
            foreach ($config['taxonomies'] ?? [] as $taxonomy => $taxConfig) {
                $terms = $taxConfig['terms'] ?? [];
                unset($taxConfig['terms']);

                $taxResult = $this->registerTaxonomy($taxonomy, $name, $taxConfig, $terms);
                if (is_wp_error($taxResult)) {
                    $this->logRegistrationFailure($name, sprintf('taxonomy "%s"', $taxonomy), $taxResult);
                    return $taxResult;
                }
            }

            // Declaring a field on a model is the whole act — nobody calls
            // register_post_meta() by hand (INV-1). Inside this branch on
            // purpose: meta is registered against a post type, and only the
            // branch that registered one could also give it the `custom-fields`
            // support WordPress needs before it emits that meta.
            $model->registerRestMeta($name);

            // The meta is registered and harmless, but a type WordPress mounts no
            // /wp/v2 route for publishes none of it.
            if ($declared !== [] && empty($args['show_in_rest'])) {
                $this->warnDeclarationGoesNowhere(
                    $name,
                    $declared,
                    'the post type itself is not in REST (`show_in_rest` absent or false), so '
                    . 'WordPress mounts no route for it',
                );
            }
        } elseif ($declared !== []) {
            // No `label`, so no post type, so no meta and no `custom-fields`: a
            // write surface with no read surface is the wrong half to open.
            $this->warnDeclarationGoesNowhere(
                $name,
                $declared,
                'the model has no `label`, so it registers no post type at all',
            );
        }

        self::$models[$name] = $model;

        // Auto-register metabox if this model has fields and is registered as a post type
        if (!empty($config['fields']) && isset($config['label']) && ($config['auto_metabox'] ?? true)) {
            ntdst_metabox()->register($name, $config);
        }

        // Fire hook after registration complete
        do_action('ntdst/model/registered', $name, $config);

        return $model;
    }

    /**
     * Register a taxonomy with the layer's defaults, and optionally seed terms.
     *
     * THE single implementation of taxonomy registration. Its declarative front
     * door is register()'s `taxonomies` config key, whose loop calls this once
     * per declared taxonomy; callers may also invoke it directly. The defaults
     * below are shaped the way they are because they were lifted verbatim from
     * the `Theme::taxonomy()` wrapper S9 retired (a taxonomy outlives a theme
     * switch, so registering one was never Theme's job) — changing them changes
     * every taxonomy that relied on the wrapper's shape. Caller $args merge OVER
     * them. Returns WP_Error on failure rather than swallowing it (INV-4) — the
     * register() loop propagates that out.
     *
     * @param string       $taxonomy   Taxonomy name.
     * @param string|array $post_types Post type(s) to attach to.
     * @param array        $args       Taxonomy args, merged over the defaults.
     * @param array        $terms      Optional slug => Display Name terms, seeded idempotently.
     * @return WP_Error|null WP_Error when register_taxonomy() refuses; null on success.
     */
    public function registerTaxonomy(string $taxonomy, string|array $post_types, array $args = [], array $terms = [])
    {
        $registered = register_taxonomy($taxonomy, $post_types, array_merge([
            'public'       => true,
            'hierarchical' => false,
            'show_ui'      => true,
            'show_in_rest' => true,
            'rewrite'      => ['slug' => $taxonomy],
        ], $args));

        if (is_wp_error($registered)) {
            return $registered;
        }

        // Seed default terms idempotently — insert only when absent. This is
        // the logic the services' ensureDefaultTerms/addDefaultProjectTypes
        // helpers held, now in one place.
        foreach ($terms as $slug => $name) {
            if (!term_exists($slug, $taxonomy)) {
                wp_insert_term($name, $taxonomy, ['slug' => $slug]);
            }
        }

        return null;
    }

    /**
     * Get a registered data model
     *
     * @param string $name Model name
     * @return NTDST_Data_Model
     */
    public function get(string $name): NTDST_Data_Model
    {
        // Asking about a model does not create one. This used to
        // auto-register a phantom on every miss — into a STATIC array — so a
        // caller-supplied type name on a public endpoint could register
        // whatever it liked, and isRegistered() could never tell a real model
        // from something someone once mistyped.
        // Note it is NOT stored: caching it here is what made isRegistered()
        // report true for a name someone once mistyped.
        //
        // T10: and it is CLONED. $models is static, so every caller used to
        // share one mutable model — an abandoned ->where() (one that never
        // reached a terminal method, so never hit the reset in the `finally`)
        // stayed on the instance and silently narrowed the NEXT query from
        // anywhere in the process. A clone per acquisition costs nothing and
        // makes the leak unrepresentable.
        return isset(self::$models[$name])
            ? clone self::$models[$name]
            : new NTDST_Data_Model($name, [], '');
    }

    /**
     * Check whether a model has been explicitly registered.
     *
     * Unlike get(), this does NOT auto-create a phantom empty model as a
     * side effect. Use this when iterating over post types to find ones
     * that actually have schemas.
     */
    public function isRegistered(string $name): bool
    {
        return isset(self::$models[$name]);
    }
}

/**
 * Global helper - get data manager instance
 */
function ntdst_data(): NTDST_Data_Manager
{
    static $manager = null;
    return $manager ??= new NTDST_Data_Manager();
}

