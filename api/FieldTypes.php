<?php // api/FieldTypes.php
// The field-type vocabulary — one table, and the only one.
//
// A field name used to mean four separate things in four separate files: a
// sanitizer in Data's match, a REST leaf shape in restSchemaFor(), a control in
// the metabox switch, and an unwritten rule about what may sit in a repeater
// row. Four tables drift — a name added to one is a silent text input in
// another. Here a name means one entry, and every reader asks for it.
defined('ABSPATH') || exit;

/**
 * One field type: what sanitizes it, what it publishes as, what draws it, and
 * whether it may live inside a repeater row.
 *
 * Readonly because an entry is handed out, not lent: a caller that could edit
 * the sanitizer on the instance it received would edit it for every later
 * caller (threat row #6).
 */
final class NTDST_FieldType
{
    public function __construct(
        public readonly string $name,
        /** @var \Closure fn(mixed $value, array $config): mixed — idempotent */
        public readonly \Closure $sanitize,
        /** @var array<string, mixed>|null REST JSON schema for the leaf; null = no leaf shape */
        public readonly ?array $schema,
        /** admin input key: number|checkbox|text|textarea|html|email|url|date|select|media|relation|gallery|repeater */
        public readonly string $control,
        /** may render inside a repeater row */
        public readonly bool $cell,
    ) {
    }
}

/**
 * The vocabulary's one home (INV-8). Seventeen names, and a closed set on
 * purpose: no filter, no registration method, two readers and nothing else.
 *
 * A pluggable vocabulary is a vocabulary a plugin can widen with a type whose
 * sanitizer is a no-op, and every stored value on the site passes through this
 * table (threat row #6). A name outside the 17 is a typo or a retired alias,
 * and both fail loudly at register() — the retired ones naming what to write
 * instead, because a consumer reading the fatal at init is the person who has
 * to fix it.
 *
 * Every entry's sanitizer is idempotent: register_post_meta() applies it a
 * second time on a REST write, and the second pass must change nothing.
 *
 * Where WordPress has the word, the entry is WordPress's function — this table
 * maps names to them, it does not re-implement them.
 */
final class NTDST_FieldTypes
{
    /**
     * The names v5.0.0 retired, each pointing at the one that replaced it.
     *
     * Read only to build the message. A retired name is not a type: it never
     * resolves, it never appears in names(), and there is no alias path.
     *
     * @var array<string, string>
     */
    private const RETIRED = [
        'integer'       => 'int',
        'signed_int'    => 'int',
        'number'        => 'int',
        'double'        => 'float',
        'decimal'       => 'float',
        'boolean'       => 'bool',
        'string'        => 'text',
        'longtext'      => 'textarea',
        'wysiwyg'       => 'html',
        'content'       => 'html',
        'datetime'      => 'date',
        'person'        => 'relation',
        'post_relation' => 'relation',
    ];

    /** @var array<string, NTDST_FieldType>|null */
    private static ?array $table = null;

    /**
     * The entry for a type name, or a fatal that says what to write instead.
     *
     * @throws InvalidArgumentException for anything outside the 17.
     */
    public static function get(string $name): NTDST_FieldType
    {
        $table = self::table();

        if (isset($table[$name])) {
            return $table[$name];
        }

        if (isset(self::RETIRED[$name])) {
            throw new InvalidArgumentException(
                "Unknown field type '{$name}'. Use '" . self::RETIRED[$name] . "'.",
            );
        }

        throw new InvalidArgumentException(
            "Unknown field type '{$name}'. Known: " . implode(', ', array_keys($table)) . '.',
        );
    }

    /** @return list<string> The whole vocabulary, in declaration order. */
    public static function names(): array
    {
        return array_keys(self::table());
    }

    /** @return array<string, NTDST_FieldType> */
    private static function table(): array
    {
        return self::$table ??= self::build();
    }

    /**
     * Built once, then handed out. The closures capture nothing, so an entry is
     * a value: two callers holding `get('text')` hold the same sanitizer.
     *
     * @return array<string, NTDST_FieldType>
     */
    private static function build(): array
    {
        // name · sanitize · REST leaf shape · admin control · may sit in a row
        $entries = [
            [
                // Signed on purpose (FR-5): absint() stripped the sign, and a
                // discount in cents is a negative int. An array is not a number.
                'int',
                static fn(mixed $value, array $config): int => is_array($value) ? 0 : (int) $value,
                ['type' => 'integer'],
                'number',
                true,
            ],
            [
                'float',
                static fn(mixed $value, array $config): float => floatval($value),
                ['type' => 'number'],
                'number',
                true,
            ],
            [
                // WordPress's word, so WordPress's answer: only the exact string
                // "false" is false. The old local fallback disagreed ("no", "off")
                // and was a second truth about the same question.
                'bool',
                static fn(mixed $value, array $config): bool => wp_validate_boolean($value),
                ['type' => 'boolean'],
                'checkbox',
                true,
            ],
            [
                'text',
                static fn(mixed $value, array $config): string => sanitize_text_field($value),
                ['type' => 'string'],
                'text',
                true,
            ],
            [
                // Keeps the newlines sanitize_text_field() would flatten.
                'textarea',
                static fn(mixed $value, array $config): string => sanitize_textarea_field($value),
                ['type' => 'string'],
                'textarea',
                true,
            ],
            [
                // cell = false: markup cannot be edited in a repeater row, and
                // a row that renders it as a text input stores the escaped soup.
                'html',
                static fn(mixed $value, array $config): string => wp_kses_post($value),
                ['type' => 'string'],
                'html',
                false,
            ],
            [
                'email',
                static fn(mixed $value, array $config): string => sanitize_email($value),
                ['type' => 'string', 'format' => 'email'],
                'email',
                true,
            ],
            [
                'url',
                static fn(mixed $value, array $config): string => esc_url_raw($value),
                ['type' => 'string', 'format' => 'uri'],
                'url',
                true,
            ],
            [
                'date',
                static fn(mixed $value, array $config): string => self::date($value),
                ['type' => 'string'],
                'date',
                true,
            ],
            [
                // The option list is the admin's business; the stored value is
                // still only text (option validation is out of scope, D-scope).
                'select',
                static fn(mixed $value, array $config): string => sanitize_text_field((string) $value),
                ['type' => 'string'],
                'select',
                true,
            ],
            [
                // The metabox posts this as a JSON string, so a JSON string is
                // accepted as well as an array.
                'array',
                static fn(mixed $value, array $config): array => self::toArray($value),
                ['type' => 'array', 'items' => ['type' => 'string']],
                'textarea',
                true,
            ],
            [
                // Same sanitizer as `array`; the difference is what it publishes.
                // A free-form object has no closed shape, and a leaf with no
                // closed shape is never published (schema null, threat row #2).
                'json',
                static fn(mixed $value, array $config): array => self::toArray($value),
                null,
                'textarea',
                true,
            ],
            [
                // A single pick posts as a scalar; the field still stores a list.
                'relation',
                static fn(mixed $value, array $config): array => self::ids(is_array($value) ? $value : [$value]),
                ['type' => 'array', 'items' => ['type' => 'integer']],
                'relation',
                false,
            ],
            [
                // A gallery is a multi-pick control: a scalar is not a gallery.
                'gallery',
                static fn(mixed $value, array $config): array => is_array($value) ? self::ids($value) : [],
                ['type' => 'array', 'items' => ['type' => 'integer']],
                'gallery',
                false,
            ],
            [
                'image',
                static fn(mixed $value, array $config): int => self::attachmentId($value),
                ['type' => 'integer'],
                'media',
                true,
            ],
            [
                'file',
                static fn(mixed $value, array $config): int => self::attachmentId($value),
                ['type' => 'integer'],
                'media',
                true,
            ],
            [
                // The repeater's shape is structural, not a leaf: schemaFor()
                // recurses over sub_fields and builds the object itself, so
                // there is no leaf shape here to hold.
                'repeater',
                static fn(mixed $value, array $config): array => is_array($value)
                    ? self::repeater($value, is_array($config['sub_fields'] ?? null) ? $config['sub_fields'] : [])
                    : [],
                null,
                'repeater',
                false,
            ],
        ];

        $table = [];
        foreach ($entries as [$name, $sanitize, $schema, $control, $cell]) {
            $table[$name] = new NTDST_FieldType($name, $sanitize, $schema, $control, $cell);
        }

        return $table;
    }

    /**
     * A date field holds a date. Junk is refused rather than stored as text —
     * a date column that sometimes holds "next tuesday" cannot be sorted.
     */
    private static function date(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        $ts = strtotime($raw);

        return $ts === false ? '' : gmdate('Y-m-d', $ts);
    }

    /**
     * An image/file field holds an attachment id — and one that exists.
     *
     * absint() alone would happily store 999999, or the id of a blog post, and
     * the field would read back as a number that resolves to nothing.
     */
    private static function attachmentId(mixed $value): int
    {
        $id = absint($value);

        return ($id > 0 && get_post_type($id) === 'attachment') ? $id : 0;
    }

    /**
     * A list of attachment/post ids, re-indexed.
     *
     * The zeros go because a junk id is not an id, and the keys are re-indexed
     * because a gap-keyed array serializes as a JSON object and stops matching
     * the published `array of integer`. One filter after absint() catches both
     * the empty cell and the unparseable one.
     *
     * absint('-3') is 3 — that is WordPress's answer, not ours.
     *
     * @param  array<mixed> $value
     * @return list<int>
     */
    private static function ids(array $value): array
    {
        return array_values(array_filter(array_map('absint', $value)));
    }

    /**
     * Whatever arrives — an array, or the JSON string the metabox textarea
     * posts — becomes a sanitized array, or nothing at all. A scalar JSON
     * document ("a string", 5) is not a field value: it stores as empty.
     *
     * @return array<array-key, mixed>
     */
    private static function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return self::nested($value);
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? self::nested($decoded) : [];
    }

    /**
     * Sanitize the leaves, keep the structure.
     *
     * String keys go through sanitize_key() because a key is a meta-ish
     * identifier, not content. Bools, ints, floats and null survive as
     * themselves — casting them to text is how a JSON `false` became "".
     *
     * @param  array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function nested(array $value): array
    {
        $sanitized = [];

        foreach ($value as $key => $item) {
            $key = is_string($key) ? sanitize_key($key) : $key;

            if (is_array($item)) {
                $sanitized[$key] = self::nested($item);
            } elseif (is_bool($item) || is_int($item) || is_float($item) || $item === null) {
                $sanitized[$key] = $item;
            } else {
                $sanitized[$key] = sanitize_text_field($item);
            }
        }

        return $sanitized;
    }

    /**
     * Each cell is sanitized as ITS declared type, through this same table —
     * a repeater is not a special vocabulary, it is the vocabulary nested.
     *
     * A key the repeater never declared is still sanitized, as text: an
     * undeclared cell is the one most likely to be junk.
     *
     * @param  array<mixed>                $rows
     * @param  array<string, mixed|string> $subFields declared shape, or a bare type name
     * @return list<array<string, mixed>>
     */
    private static function repeater(array $rows, array $subFields): array
    {
        $sanitized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $cells = [];
            foreach ($row as $key => $value) {
                $config = $subFields[$key] ?? null;
                $type = is_array($config) ? ($config['type'] ?? null) : $config;

                if ($type === 'image' || $type === 'file') {
                    // 0 reads as a real id to every consumer that absint()s the
                    // cell, so an empty media cell stores what an empty text
                    // cell stores. Scoped to the row deliberately — a top-level
                    // image/file field keeps returning int.
                    $id = self::attachmentId($value);
                    $cells[$key] = $id > 0 ? $id : '';
                    continue;
                }

                if (is_string($type) && $type !== '') {
                    $cells[$key] = (self::get($type)->sanitize)($value, is_array($config) ? $config : []);
                    continue;
                }

                $cells[$key] = sanitize_text_field($value);
            }

            // An all-empty row is a row the editor added and never filled.
            if (!empty(array_filter($cells))) {
                $sanitized[] = $cells;
            }
        }

        return $sanitized;
    }
}
