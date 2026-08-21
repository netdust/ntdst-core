<?php

/**
 * NTDST Data Layer - Minimal ORM
 *
 * A chain API over WP_Query plus the CPT/field vocabulary the metabox
 * generator reads. Nothing else.
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

        $this->setupSanitizers();
        $this->setupValidators();
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
     * The fields that may ever leave this model — every declared field except
     * those marked `private => true`.
     *
     * A CEILING, not a shape. Which of these an exposure actually emits is the
     * exposure's decision; this only says what is never on the table. Public by
     * default because most fields are, and a rule nobody has to remember for
     * the common case is a rule that holds.
     *
     * @return list<string>
     */
    public function publicFields(): array
    {
        $public = [];

        foreach ($this->schema as $field => $config) {
            if (!(is_array($config) && ($config['private'] ?? false) === true)) {
                $public[] = (string) $field;
            }
        }

        return $public;
    }

    /**
     * The same ceiling one level down, for the field kinds that nest.
     *
     * A flat list cannot express "this repeater is public but its sale_price is
     * not", and that is exactly where a sensitive value hides. A parent marked
     * private is absent entirely; it takes its children with it.
     *
     * @return array<string, list<string>>
     */
    public function publicSubFields(): array
    {
        $public = [];

        foreach ($this->schema as $field => $config) {
            if (!is_array($config) || ($config['private'] ?? false) === true) {
                continue;
            }

            if (!is_array($config['sub_fields'] ?? null)) {
                continue;
            }

            $kept = [];

            foreach ($config['sub_fields'] as $sub => $subConfig) {
                if (!(is_array($subConfig) && ($subConfig['private'] ?? false) === true)) {
                    $kept[] = (string) $sub;
                }
            }

            $public[(string) $field] = $kept;
        }

        return $public;
    }

    /**
     * Narrow rows to a declared set of keys, in the declared order.
     *
     * APPLIES a shape; never knows one. The keys come from whoever is exposing
     * the rows, which is the only party that knows who is asking.
     *
     * An empty key list yields empty rows. The mechanism this replaces returned
     * EVERY field when no shape was declared, so forgetting the declaration
     * published the row — a fail-open on the one path where it costs most.
     *
     * @param  array<int, array<string, mixed>> $rows
     * @param  list<string>                     $keys
     * @param  array<string, list<string>>      $subKeys Nested narrowing, keyed by field.
     * @return array<int, array<string, mixed>>
     */
    public static function project(array $rows, array $keys, array $subKeys = []): array
    {
        $projected = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $out = [];

            foreach ($keys as $key) {
                if (!array_key_exists($key, $row)) {
                    continue;
                }

                $out[$key] = $key === 'meta' && is_array($row[$key])
                    ? self::projectNested($row[$key], $subKeys)
                    : $row[$key];
            }

            $projected[] = $out;
        }

        return $projected;
    }

    /**
     * @param  array<string, mixed>        $meta
     * @param  array<string, list<string>> $subKeys
     * @return array<string, mixed>
     */
    private static function projectNested(array $meta, array $subKeys): array
    {
        foreach ($subKeys as $field => $kept) {
            if (!isset($meta[$field]) || !is_array($meta[$field])) {
                continue;
            }

            $meta[$field] = array_values(array_map(
                static function ($entry) use ($kept) {
                    if (!is_array($entry)) {
                        return $entry;
                    }

                    $narrowed = [];

                    foreach ($kept as $key) {
                        if (array_key_exists($key, $entry)) {
                            $narrowed[$key] = $entry[$key];
                        }
                    }

                    return $narrowed;
                },
                $meta[$field],
            ));
        }

        return $meta;
    }

    /**
     * Setup default sanitizers based on schema types
     */
    protected function setupSanitizers(): void
    {
        foreach ($this->schema as $field => $type) {
            // Extract sanitizer if provided as array ['type' => 'string', 'sanitizer' => 'callback']
            if (is_array($type)) {
                $extracted_type = $type['type'] ?? 'string';

                // T15: a repeater's sub-fields declare types too, and they were
                // ignored on both sides — sanitizeRepeater() ran
                // sanitize_text_field() over every value regardless. Bind the
                // sub-field config here so the declared types actually apply.
                if ($extracted_type === 'repeater') {
                    $subFields = $type['sub_fields'] ?? [];
                    $this->sanitizers[$field] = $type['sanitizer']
                        ?? fn($v) => is_array($v) ? $this->sanitizeRepeater($v, $subFields) : [];
                    continue;
                }

                $this->sanitizers[$field] = $type['sanitizer'] ?? $this->getDefaultSanitizer($extracted_type);
                // DON'T simplify schema - preserve full config for metadata access
                // $this->schema[$field] = $extracted_type;  // ← REMOVED: This destroyed field metadata
            } else {
                $this->sanitizers[$field] = $this->getDefaultSanitizer($type);
            }
        }
    }

    /**
     * Setup validators based on schema
     */
    protected function setupValidators(): void
    {
        foreach ($this->schema as $field => $type_config) {
            if (is_array($type_config)) {
                $this->validators[$field] = [
                    'required' => $type_config['required'] ?? false,
                    'min' => $type_config['min'] ?? null,
                    'max' => $type_config['max'] ?? null,
                    'validate' => $type_config['validate'] ?? null,
                ];
            }
        }
    }

    /**
     * Get default sanitizer for a field type
     */
    protected function getDefaultSanitizer(string $type): callable
    {
        return match ($type) {
            'int', 'integer' => 'absint',
            // signed_int: an int that may be negative (e.g. a price discount in cents).
            // absint() was the original bug — it strips the sign (Stride 744b5b05).
            'signed_int' => fn($v) => is_array($v) ? 0 : (int) $v,
            'float', 'double' => 'floatval',
            'bool', 'boolean' => fn($v) => $this->sanitizeBoolean($v),
            'email' => 'sanitize_email',
            'url' => 'esc_url_raw',
            'text' => 'sanitize_text_field',
            'textarea' => 'sanitize_textarea_field',
            'html', 'content' => fn($v) => wp_kses_post($v),
            'array' => fn($v) => is_array($v) ? $this->sanitizeNestedArray($v) : [],
            'json' => fn($v) => $this->sanitizeJson($v),
            'relation' => fn($v) => is_array($v) ? array_map('absint', array_filter($v)) : (!empty($v) ? [absint($v)] : []),
            'gallery' => fn($v) => is_array($v) ? array_map('absint', array_filter($v)) : [],
            'repeater' => fn($v) => is_array($v) ? $this->sanitizeRepeater($v) : [],

            // Types this helper has always ADVERTISED but never sanitised —
            // they fell through to sanitize_text_field, silently. daan alone
            // declares these in 20 places. A CPT helper that accepts a type
            // name and then ignores it is lying about its own vocabulary.
            'select'        => fn($v) => sanitize_text_field((string) $v),
            'date'          => fn($v) => $this->sanitizeDate($v),
            'wysiwyg'       => fn($v) => wp_kses_post($v),
            'image', 'file' => fn($v) => $this->sanitizeAttachmentId($v),
            'person', 'post_relation' => fn($v) => is_array($v)
                ? array_map('absint', array_filter($v))
                : (!empty($v) ? [absint($v)] : []),

            // An unknown type is a typo, and a typo that silently becomes
            // sanitize_text_field is how a `wysiwig` field loses its markup
            // with nothing failing. Fail loudly at registration instead.
            default => throw new InvalidArgumentException(
                "Unknown field type '{$type}'. Supported: int, signed_int, float, bool, email, url, "
                . "text, textarea, html, array, json, relation, gallery, repeater, "
                . "select, date, wysiwyg, image, file, person, post_relation.",
            ),
        };
    }

    /**
     * Sanitize boolean values without treating non-empty strings like "false" as true.
     */
    protected function sanitizeBoolean($value): bool
    {
        if (function_exists('wp_validate_boolean')) {
            return wp_validate_boolean($value);
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['false', '0', 'no', 'off', ''], true)) {
                return false;
            }
            if (in_array($normalized, ['true', '1', 'yes', 'on'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }

    /**
     * Sanitize JSON-like values to an array and reject invalid JSON strings.
     */
    protected function sanitizeJson($value): array
    {
        if (is_array($value)) {
            return $this->sanitizeNestedArray($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $this->sanitizeNestedArray($decoded) : [];
    }

    /**
     * Recursively sanitize scalar values in a nested array while preserving structure.
     */
    protected function sanitizeNestedArray(array $value): array
    {
        $sanitized = [];

        foreach ($value as $key => $item) {
            $sanitized_key = is_string($key) ? sanitize_key($key) : $key;
            if (is_array($item)) {
                $sanitized[$sanitized_key] = $this->sanitizeNestedArray($item);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $sanitized[$sanitized_key] = $item;
            } else {
                $sanitized[$sanitized_key] = sanitize_text_field($item);
            }
        }

        return $sanitized;
    }

    /**
     * Sanitize field value based on schema
     */
    protected function sanitizeField(string $field, $value)
    {
        if (!isset($this->sanitizers[$field])) {
            return sanitize_text_field($value);
        }

        $sanitizer = $this->sanitizers[$field];

        if (is_callable($sanitizer)) {
            return $sanitizer($value);
        }

        return $value;
    }

    /**
     * Sanitize repeater field data
     *
     * @param array $rows Array of repeater rows
     * @return array Sanitized repeater data
     */
    /**
     * A date field holds a date. Junk is rejected rather than stored as text.
     */
    protected function sanitizeDate($value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);

        return $ts === false ? '' : gmdate('Y-m-d', $ts);
    }

    /**
     * An image/file field holds an attachment ID — and one that exists.
     *
     * absint() alone would happily store 999999, or the ID of a blog post,
     * and the field would read back as a number that resolves to nothing.
     */
    protected function sanitizeAttachmentId($value): int
    {
        $id = absint($value);

        return ($id > 0 && get_post_type($id) === 'attachment') ? $id : 0;
    }

    /**
     * @param array $subFields The repeater's declared sub-field config, so each
     *                         sub-value is sanitised as ITS type rather than as
     *                         text. Empty config falls back to text, which is
     *                         what every repeater got before T15.
     */
    protected function sanitizeRepeater(array $rows, array $subFields = []): array
    {
        if (empty($rows) || !is_array($rows)) {
            return [];
        }

        $sanitized_rows = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sanitized_row = [];
            foreach ($row as $sub_field => $value) {
                $config = $subFields[$sub_field] ?? null;
                $subType = is_array($config) ? ($config['type'] ?? null) : $config;

                if ($subType === 'image' || $subType === 'file') {
                    // sanitizeAttachmentId() returns 0 for "nothing selected",
                    // and 0 reads as a real id to every consumer that absint()s
                    // the cell. An empty media cell stores what an empty text
                    // cell stores. Scoped to the repeater path deliberately —
                    // a top-level image/file field keeps returning int.
                    $id = $this->sanitizeAttachmentId($value);
                    $sanitized_row[$sub_field] = $id > 0 ? $id : '';
                    continue;
                }

                if (is_string($subType) && $subType !== '') {
                    // The declared type does what it says — an `image`
                    // sub-field stores a verified attachment id, a `wysiwyg`
                    // sub-field keeps its markup.
                    $sanitized_row[$sub_field] = ($this->getDefaultSanitizer($subType))($value);
                    continue;
                }

                $sanitized_row[$sub_field] = sanitize_text_field($value);
            }

            // Only add row if it has data
            if (!empty(array_filter($sanitized_row))) {
                $sanitized_rows[] = $sanitized_row;
            }
        }

        return $sanitized_rows;
    }

    /**
     * Format repeater field data on load
     *
     * @param mixed $value Raw repeater data from database
     * @return array Formatted repeater data
     */
    protected function formatRepeaterField($value): array
    {
        // Handle null/empty
        if (empty($value)) {
            return [];
        }

        // If it's a JSON string, decode it
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            } else {
                // Not valid JSON, try unserialize (legacy)
                $unserialized = @unserialize($value);
                $value = is_array($unserialized) ? $unserialized : [];
            }
        }

        // Ensure it's an array
        if (!is_array($value)) {
            return [];
        }

        // Ensure each row is an array
        $formatted_rows = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $formatted_rows[] = $row;
            }
        }

        return $formatted_rows;
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
     * Log a warning when a caller passes keys that are neither WordPress
     * columns nor registered schema fields. Drops the keys silently from
     * the returned array — the warning is the contract signal.
     *
     * Called from create()/update() before sanitization, so typos surface
     * as a clear "unregistered key" warning rather than a confusing
     * "required field missing" validation error.
     */
    protected function warnUnregisteredKeys(array $data, string $operation): array
    {
        $allowed = array_flip(array_keys($this->schema)) + self::WP_COLUMNS;
        $unknown = array_diff_key($data, $allowed);

        if (empty($unknown)) {
            return $data;
        }

        if (function_exists('ntdst_log')) {
            ntdst_log('data')->warning(
                sprintf('Unregistered key(s) passed to %s on %s', $operation, $this->post_type),
                [
                    'post_type' => $this->post_type,
                    'keys'      => array_keys($unknown),
                ],
            );
        }

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
        do_action('ntdst_model_create_before', $this->post_type, $data);

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
        do_action('ntdst_model_create_after', $this->post_type, $post_id, $data);

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
        do_action('ntdst_model_update_before', $this->post_type, $id, $data);

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
        do_action('ntdst_model_update_after', $this->post_type, $id, $data);

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

        $post->meta = NTDST_Data_Manager::getPostMeta($id);
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

        // Sanitize value based on this model's field schema.
        $fieldSchema = $this->schema[$key] ?? null;
        if ($fieldSchema && is_array($fieldSchema)) {
            $fieldType = $fieldSchema['type'] ?? 'text';
            $sanitizer = $this->getDefaultSanitizer($fieldType);
            if (is_callable($sanitizer)) {
                $value = $sanitizer($value);
            }
        }

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

        foreach ($data as $key => $value) {
            // Get field schema for sanitization (schema IS the fields array)
            $fieldSchema = $this->schema[$key] ?? null;
            if ($fieldSchema && is_array($fieldSchema)) {
                $fieldType = $fieldSchema['type'] ?? 'text';
                $sanitizer = $this->getDefaultSanitizer($fieldType);
                if (is_callable($sanitizer)) {
                    $value = $sanitizer($value);
                }
            }

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
        do_action('ntdst_model_delete_before', $this->post_type, $id);

        $result = $force ? wp_delete_post($id, true) : wp_trash_post($id);

        if (!$result) {
            return new WP_Error('delete_failed', 'Failed to delete post', ['status' => 500]);
        }

        // Fire after hook
        do_action('ntdst_model_delete_after', $this->post_type, $id);

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
     * An unknown name FAILS LOUDLY (like getDefaultSanitizer()'s throw on an
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
     * Query builder - date where clause
     *
     * @param string $column Date column (post_date, post_modified, etc.)
     * @param string $compare Comparison operator (=, !=, >, >=, <, <=, BETWEEN, NOT BETWEEN)
     * @param string|array $value Date value or array of dates for BETWEEN
     * @return $this
     *
     * Example:
     * $model->whereDate('post_date', '>=', '2024-01-01')->get();
     * $model->whereDate('post_date', 'BETWEEN', ['2024-01-01', '2024-12-31'])->get();
     */
    public function whereDate(string $column = 'post_date', string $compare = '=', $value = null): self
    {
        if (!isset($this->query_args['date_query'])) {
            $this->query_args['date_query'] = [];
        }

        if ($compare === 'BETWEEN' || $compare === 'NOT BETWEEN') {
            $dates = is_array($value) ? $value : [$value, $value];
            $this->query_args['date_query'][] = [
                'column' => $column,
                'after' => $dates[0],
                'before' => $dates[1] ?? $dates[0],
                'inclusive' => true,
            ];
        } else {
            $this->query_args['date_query'][] = [
                'column' => $column,
                'compare' => $compare,
                'value' => $value,
            ];
        }

        return $this;
    }

    /**
     * Query builder - OR where clause (starts a new OR relation)
     *
     * @return $this
     *
     * Note: this creates one flat root-level OR meta_query. It cannot express
     * nested groups like A AND (B OR C); use a custom meta_query for those cases.
     *
     * Example:
     * $model->where('featured', true)
     * ->orWhere('price', ['<', 100])
     * ->get();
     */
    public function orWhere(string $field, $value): self
    {
        if (!isset($this->query_args['meta_query'])) {
            $this->query_args['meta_query'] = ['relation' => 'OR'];
        } elseif (!isset($this->query_args['meta_query']['relation'])) {
            // Convert existing queries to OR relation
            $this->query_args['meta_query']['relation'] = 'OR';
        }

        $metaKey = $this->prefixMetaKey($field);
        $this->query_args['meta_query'][] = is_array($value) && count($value) === 2
            ? ['key' => $metaKey, 'value' => $value[1], 'compare' => $value[0]]
            : ['key' => $metaKey, 'value' => $value];

        return $this;
    }

    /**
     * Attach taxonomy terms to a post
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Array of term IDs
     * @param bool $append Append to existing terms (true) or replace (false)
     * @return bool|WP_Error
     *
     * Example:
     * $model->attachTerms(123, 'category', [1, 2, 3]);
     */
    public function attachTerms(int $post_id, string $taxonomy, array $term_ids, bool $append = true)
    {
        $result = wp_set_post_terms($post_id, $term_ids, $taxonomy, $append);

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
    }

    /**
     * Sync taxonomy terms (replace all existing terms)
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Array of term IDs
     * @return bool|WP_Error
     *
     * Example:
     * $model->syncTerms(123, 'category', [1, 2, 3]);
     */
    public function syncTerms(int $post_id, string $taxonomy, array $term_ids)
    {
        return $this->attachTerms($post_id, $taxonomy, $term_ids, false);
    }

    /**
     * Detach taxonomy terms from a post
     *
     * @param int $post_id Post ID
     * @param string $taxonomy Taxonomy name
     * @param array $term_ids Array of term IDs to remove (empty array removes all)
     * @return bool|WP_Error
     *
     * Example:
     * $model->detachTerms(123, 'category', [1, 2]);
     * $model->detachTerms(123, 'category', []); // Remove all
     */
    public function detachTerms(int $post_id, string $taxonomy, array $term_ids = [])
    {
        if (empty($term_ids)) {
            $result = wp_set_post_terms($post_id, [], $taxonomy, false);
        } else {
            $existing = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
            $remaining = array_diff($existing, $term_ids);
            $result = wp_set_post_terms($post_id, $remaining, $taxonomy, false);
        }

        if (is_wp_error($result)) {
            return $result;
        }

        return true;
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
            return $this->projectRowsMeta(NTDST_Data_Manager::getFormattedPosts(array_merge([
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
     * projected form at all, only the raw bag `getFormattedPosts()` builds from
     * `getPostMeta()`. So a service writing a list handler could not obtain a
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

            $posts = $this->projectRowsMeta(NTDST_Data_Manager::getFormattedPosts(array_merge([
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
        $post->meta = NTDST_Data_Manager::getPostMeta($id);
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
     * Decode array-like stored values without returning null on invalid JSON.
     */
    protected function decodeArrayField($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Format meta according to schema with sanitization
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

            // Extract type string from config array if needed
            $type = is_array($type_config) ? ($type_config['type'] ?? 'text') : $type_config;

            // Type cast (with null safety for json_decode in PHP 8.1+)
            $formatted[$field] = match ($type) {
                'int', 'integer' => (int) $value,
                'signed_int' => (int) $value,
                'float', 'double' => (float) $value,
                'bool', 'boolean' => $this->sanitizeBoolean($value),
                'array' => is_array($value) ? $value : [],
                'json' => $this->decodeArrayField($value),
                'relation' => array_map('intval', $this->decodeArrayField($value)),
                'gallery' => array_map('intval', $this->decodeArrayField($value)),
                'repeater' => $this->formatRepeaterField($value),

                // T14: the read side needs the same arms the write side gained,
                // or a field sanitised as an int reads back as a string and the
                // declared type is still decorative — just one layer further on.
                'image', 'file' => (int) $value,
                'person', 'post_relation' => array_map('intval', $this->decodeArrayField($value)),

                // select, date and wysiwyg are strings on the way out; the
                // default arm below already handles them correctly.
                default => is_array($value) ? json_encode($value) : (string) ($value ?? ''),
            };

            // Additional sanitization for simple arrays only (not JSON/nested structures)
            if ($type === 'array' && is_array($formatted[$field])) {
                $formatted[$field] = $this->sanitizeNestedArray($formatted[$field]);
            }
            // JSON fields are already sanitized when saved, don't re-sanitize on output
        }

        return $formatted;
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
        if (!function_exists('ntdst_log')) {
            return;
        }

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

        if (isset($config['label'])) {
            // PRIVATE BY DEFAULT. Silence is not privacy: this used to merge the
            // caller's config OVER `public => true, has_archive => true`, so a
            // model registered without visibility flags was published, archived
            // and queryable. That default is why every non-public CPT on this
            // codebase had to state six denials by hand, and why forgetting one
            // was a disclosure. Opt IN to public; never opt out of it.
            $registered = register_post_type($name, array_merge([
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
            ]))));

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
        }

        $model = new NTDST_Data_Model(
            $name,
            $config['fields'] ?? [],
            $config['meta_prefix'] ?? '',
            $config['scopes'] ?? [],
        );

        self::$models[$name] = $model;

        // Auto-register metabox if this model has fields and is registered as a post type
        if (!empty($config['fields']) && isset($config['label']) && ($config['auto_metabox'] ?? true)) {
            // Assumes ntdst_metabox() returns a valid metabox manager object
            if (function_exists('ntdst_metabox')) {
                ntdst_metabox()->register($name, $config);
            }
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
    public static function getPostMeta(int $post_id): array
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
    public static function getPostTerms(int $post_id, string $post_type): array
    {
        $taxonomies = get_object_taxonomies($post_type);
        $terms = [];

        foreach ($taxonomies as $taxonomy) {
            $wp_cached = wp_cache_get($post_id, "{$taxonomy}_relationships");
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

        if (!empty($terms)) {
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
     * That is the whole job, and the name says so (T04 renamed this from
     * `getPostsFast()`, which advertised a speed property it did not have —
     * the priming it did was itself the cost, and it is gone).
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
    public static function getFormattedPosts(array $args = []): array
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
                $post_data['meta'] = self::getPostMeta($post->ID);
            }

            // Include taxonomy terms if requested (served from core's primed cache).
            // Keyed off the ROW's own type, not the query's: `post_type` may be
            // an array, and getPostTerms() takes one type.
            if ($include_terms) {
                $post_data['terms'] = self::getPostTerms($post->ID, $post->post_type);
            }

            $posts[] = $post_data;
        }

        return $posts;
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

/**
 * Global helper - run a WP_Query with core's defaults and format the rows
 *
 * @param array $args Query arguments
 * @return array Array of post data
 */
function ntdst_get_formatted_posts(array $args = []): array
{
    return NTDST_Data_Manager::getFormattedPosts($args);
}
