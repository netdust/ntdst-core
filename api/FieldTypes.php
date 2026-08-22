<?php // api/FieldTypes.php
// The field-type vocabulary — one table, and the only one.
//
// A name used to mean four things in four files: a sanitizer, a REST leaf
// shape, a metabox control, and an unwritten rule about repeater rows. Four
// tables drift. Here a name means one entry, and every reader asks for it.
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
        public readonly \Closure $sanitize, // fn(mixed $value, array $config): mixed — idempotent
        public readonly ?array $schema,     // REST leaf shape; null = no leaf shape
        public readonly string $control,    // admin input key — rendering intent, never a type name
        public readonly bool $cell,         // may render inside a repeater row
    ) {
    }
}

/**
 * The vocabulary's one home (INV-8). Seventeen names, and a closed set on
 * purpose: no filter, no registration method, two readers and nothing else —
 * a pluggable vocabulary is one a plugin can widen with a type whose sanitizer
 * is a no-op, and every type NAME on the site resolves here (threat row #6).
 * A name outside the 17 is a typo or a retired alias, and both fail loudly at
 * register(), the retired ones naming what to write instead.
 *
 * Where WordPress has the word, the entry is WordPress's function — this table
 * maps names to them, it does not re-implement them.
 */
final class NTDST_FieldTypes
{
    /**
     * The names v5.0.0 retired, each pointing at the one that replaced it.
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

    /** The table is the class: there is nothing here to instantiate. */
    private function __construct()
    {
    }

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

    /** @return array<string, NTDST_FieldType> */
    private static function build(): array
    {
        // `array` and `json` sanitize the same; the difference is what they publish.
        $toArray = static fn(mixed $value, array $config): array => self::toArray($value);
        $attachmentId = static fn(mixed $value, array $config): int => self::attachmentId($value);
        $text = static fn(mixed $value, array $config): string => sanitize_text_field(self::scalar($value));

        $table = [];

        // name · sanitize · REST leaf shape · admin control · may sit in a row
        foreach ([
            // Signed on purpose (FR-5): absint() stripped the sign, and a
            // discount in cents is a negative int. A non-scalar is not a number.
            new NTDST_FieldType(
                'int',
                static fn(mixed $value, array $config): int => is_scalar($value) ? (int) $value : 0,
                ['type' => 'integer'], 'number', true,
            ),
            // INF and NAN are not JSON-encodable: one overflowing post would
            // empty a whole REST response.
            new NTDST_FieldType(
                'float',
                static fn(mixed $value, array $config): float => is_scalar($value)
                    && is_finite((float) $value) ? (float) $value : 0.0,
                ['type' => 'number'], 'decimal', true,
            ),
            // WordPress's word, so WordPress's answer: only the exact string
            // "false" is false. A non-scalar is the one place bool leaves
            // WordPress — "posted something shaped wrong" never means "yes".
            new NTDST_FieldType(
                'bool',
                static fn(mixed $value, array $config): bool => is_scalar($value) && wp_validate_boolean($value),
                ['type' => 'boolean'], 'checkbox', true,
            ),
            new NTDST_FieldType(
                'text',
                $text,
                ['type' => 'string'], 'text', true,
            ),
            // Keeps the newlines sanitize_text_field() would flatten.
            new NTDST_FieldType(
                'textarea',
                static fn(mixed $value, array $config): string => sanitize_textarea_field(self::scalar($value)),
                ['type' => 'string'], 'textarea', true,
            ),
            // cell = false: markup cannot be edited in a repeater row, and a
            // row that renders it as a text input stores the escaped soup.
            new NTDST_FieldType(
                'html',
                static fn(mixed $value, array $config): string => wp_kses_post(self::scalar($value)),
                ['type' => 'string'], 'html', false,
            ),
            new NTDST_FieldType(
                'email',
                static fn(mixed $value, array $config): string => sanitize_email(self::scalar($value)),
                ['type' => 'string', 'format' => 'email'], 'email', true,
            ),
            new NTDST_FieldType(
                'url',
                static fn(mixed $value, array $config): string => esc_url_raw(self::scalar($value)),
                ['type' => 'string', 'format' => 'uri'], 'url', true,
            ),
            new NTDST_FieldType(
                'date',
                static fn(mixed $value, array $config): string => self::date($value),
                ['type' => 'string'], 'date', true,
            ),
            // The option list is the admin's business; the stored value is
            // still only text (option validation is out of scope, D-scope).
            new NTDST_FieldType(
                'select',
                $text,
                ['type' => 'string'], 'select', true,
            ),
            // The metabox posts this as a JSON string, so a JSON string is
            // accepted as well as an array. Not publishable: the sanitizer
            // keeps a keyed map with typed scalars, and a leaf with no closed
            // shape is never published (threat row #2).
            new NTDST_FieldType(
                'array',
                $toArray,
                null, 'json', true,
            ),
            new NTDST_FieldType(
                'json',
                $toArray,
                null, 'json', true,
            ),
            // A single pick posts as a scalar; the field still stores a list.
            new NTDST_FieldType(
                'relation',
                static fn(mixed $value, array $config): array => self::ids(is_array($value) ? $value : [$value]),
                ['type' => 'array', 'items' => ['type' => 'integer']], 'relation', false,
            ),
            // A gallery is a multi-pick control: a scalar is not a gallery.
            new NTDST_FieldType(
                'gallery',
                static fn(mixed $value, array $config): array => is_array($value) ? self::ids($value) : [],
                ['type' => 'array', 'items' => ['type' => 'integer']], 'gallery', false,
            ),
            new NTDST_FieldType(
                'image',
                $attachmentId,
                ['type' => 'integer'], 'media', true,
            ),
            new NTDST_FieldType(
                'file',
                $attachmentId,
                ['type' => 'integer'], 'media', true,
            ),
            // The repeater's shape is structural, not a leaf: schemaFor()
            // recurses over sub_fields and builds the object itself.
            new NTDST_FieldType(
                'repeater',
                static fn(mixed $value, array $config): array => self::repeater($value, $config),
                null, 'repeater', false,
            ),
        ] as $type) {
            $table[$type->name] = $type;
        }

        return $table;
    }

    /**
     * What a scalar entry can sanitize. An array, or an object with no
     * __toString, is not one: it answers empty rather than raising a TypeError
     * or an "Array to string conversion" warning. A posted field name that
     * collides with a checkbox group is all it takes (threat row #1).
     */
    private static function scalar(mixed $value): string
    {
        if (is_scalar($value)) {
            return (string) $value;
        }

        return $value instanceof Stringable ? (string) $value : '';
    }

    /**
     * A date field holds a date. Junk is refused rather than stored as text —
     * a date column that sometimes holds "not a date" cannot be sorted.
     *
     * date(), never gmdate(): strtotime() reads the posted string in the site's
     * timezone, so the answer is written in it too. A year outside 0000-9999 is
     * refused because date() writes five digits there, which the next pass
     * re-parses as a different date — and register_post_meta() runs the
     * sanitizer again on every REST write.
     */
    private static function date(mixed $value): string
    {
        $raw = trim(self::scalar($value));
        if ($raw === '') {
            return '';
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false || preg_match('/^\d{4}$/', date('Y', $timestamp)) !== 1) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * An image/file field holds an attachment id — and one that exists.
     *
     * absint() alone would store 999999, or the id of a blog post, and the
     * field would read back as a number that resolves to nothing. It also
     * turns a non-scalar into 1, so the non-scalar is refused first.
     */
    private static function attachmentId(mixed $value): int
    {
        $id = is_scalar($value) ? absint($value) : 0;

        return ($id > 0 && get_post_type($id) === 'attachment') ? $id : 0;
    }

    /**
     * A list of attachment/post ids, re-indexed — a gap-keyed array serializes
     * as a JSON object, and Data::find() consumers and ntdst's own Rest
     * responses read it as one (REST's prepare_value() re-indexes on its own).
     * Zeros go, and a non-scalar never becomes an id: absint(['a']) is 1, which
     * is a real post on every site. absint('-3') is 3 — WordPress's answer, not
     * ours. Not wp_parse_id_list(): it de-duplicates and leaves gap keys.
     *
     * @param  array<mixed> $value
     * @return list<int>
     */
    private static function ids(array $value): array
    {
        $ids = array_map(static fn(mixed $item): int => is_scalar($item) ? absint($item) : 0, $value);

        return array_values(array_filter($ids));
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
     * Sanitize the leaves, keep the structure. Not map_deep(): this keeps typed
     * scalars (a JSON `false` stays false, not "") and sanitizes the keys,
     * because a key is a meta-ish identifier, not content.
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
                $sanitized[$key] = sanitize_text_field(self::scalar($item));
            }
        }

        return $sanitized;
    }

    /**
     * Each cell is sanitized as ITS declared type, through this same table — a
     * repeater is not a special vocabulary, it is the vocabulary nested. An
     * undeclared key is still stored, as text, and its key is sanitized the way
     * nested() sanitizes one: the metabox echoes that key back as an input name.
     *
     * A row is kept when any POSTED cell is not '' and not null. The rule reads
     * what the editor typed, not what came out — `int ''` sanitizes to 0, and a
     * quantity of "0" is an answer, not a blank.
     *
     * @param  array<string, mixed> $config the repeater's own declaration
     * @return list<array<array-key, mixed>>
     */
    private static function repeater(mixed $rows, array $config): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $subFields = is_array($config['sub_fields'] ?? null) ? $config['sub_fields'] : [];
        $sanitized = [];

        foreach ($rows as $row) {
            $posted = is_array($row)
                ? array_filter($row, static fn(mixed $cell): bool => $cell !== '' && $cell !== null)
                : [];

            if ($posted === []) {
                continue;
            }

            $cells = [];
            foreach ($row as $key => $value) {
                $key = is_string($key) ? sanitize_key($key) : $key;
                $declared = $subFields[$key] ?? null;
                $type = is_array($declared) ? ($declared['type'] ?? null) : $declared;

                if ($type === 'image' || $type === 'file') {
                    // 0 reads as a real id to every consumer that absint()s the
                    // cell, so an empty media cell stores what an empty text
                    // cell stores. Scoped to the row deliberately — a top-level
                    // image/file field keeps returning int.
                    $id = self::attachmentId($value);
                    $cells[$key] = $id > 0 ? $id : '';
                    continue;
                }

                $cells[$key] = is_string($type) && $type !== ''
                    ? (self::get($type)->sanitize)($value, is_array($declared) ? $declared : [])
                    : sanitize_text_field(self::scalar($value));
            }

            $sanitized[] = $cells;
        }

        return $sanitized;
    }
}
