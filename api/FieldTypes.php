<?php // api/FieldTypes.php
// The field-type vocabulary — one table, and the only one.
//
// A name used to mean four things in four files: a sanitizer, a REST leaf
// shape, a metabox control, and an unwritten rule about repeater rows. Four
// tables drift. Here a name means one entry, and every reader asks for it.
defined('ABSPATH') || exit;

/**
 * One field type: what sanitizes it, what it publishes as, what draws it,
 * whether it may live inside a repeater row, and how a stored value reads back.
 *
 * A type owns how it is written and how it is read, in one row of one table.
 * The read is a CAST or a DECODE, never a second sanitization and never a
 * lookup: the write side already ran, and a value stored around this model —
 * by an importer, by WP-CLI, by the site's previous plugin — is not this
 * model's to rewrite on the way out.
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
        // Storage decode: fn(mixed $stored, array $config): mixed.
        // null = the sanitizer IS the cast (int, float, bool).
        public readonly ?\Closure $read = null,
    ) {
    }
}

/**
 * The vocabulary's one home (INV-8). Seventeen names, and a closed set on
 * purpose: no filter, no registration method, two readers of the table and
 * nothing else that can reach it (`rowKey()` answers the KEY rule, not the
 * table) — a pluggable vocabulary is one a plugin can widen with a type whose
 * sanitizer is a no-op, and every type NAME on the site resolves here
 * (threat row #6).
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

    /**
     * THE KEY RULE, and there is one of it: the key a repeater cell is stored
     * under is sanitize_key() of its declared name.
     *
     * Whoever declares a cell, whoever sanitizes a row, whoever refuses two
     * names that collide, and whoever publishes the row's schema must all ask
     * the same question. While each carried its own copy, a cell could be
     * declared under one key and stored under another — and then the re-save
     * loses the cell's type (an int comes back as text), or two declarations
     * quietly become one.
     *
     * Idempotent, because the REST re-save arrives with the key the first pass
     * produced. The function_exists() guard is for the one caller that runs
     * before WordPress is loaded — a model constructed at file scope — and it
     * mirrors WordPress's own algorithm rather than inventing a second one.
     */
    public static function rowKey(string $name): string
    {
        return function_exists('sanitize_key')
            ? sanitize_key($name)
            : (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($name));
    }

    /**
     * THE DECLARATION RULE, and there is one of it: the type a declaration NAMES.
     *
     * A bare string IS the type; an array says so under `type`; a declaration
     * with neither is `text`, because a label-only field is the commonest
     * declaration on the fleet and it must not fatal at init.
     *
     * JUNK is not quietly rewritten to `text`: `['type' => 123]` is carried to
     * get(), which refuses it by name. "Someone wrote no type" and "someone
     * typo'd a type" are different mistakes, and only the first one is legal.
     *
     * Four readers each carried a copy of this rule — the model's constructor,
     * its sanitizer binding, the metabox render and the metabox save — and one
     * of them defaulted a missing type to `string`, a name v5.0.0 retired. A
     * copy of a rule is a second vocabulary (INV-8).
     */
    public static function declaredType(mixed $declaration): string
    {
        $type = is_array($declaration) ? ($declaration['type'] ?? null) : $declaration;

        if ($type === null || $type === '') {
            return 'text';
        }

        // A non-scalar names nothing. It is reported AS the shape it is, in a
        // form no entry can ever answer to, rather than cast into a name — the
        // cast that lands on a real entry is the silent wrong answer.
        return is_scalar($type) ? (string) $type : '(' . get_debug_type($type) . ')';
    }

    /**
     * Every rule the vocabulary has about a `fields` DECLARATION, asked once,
     * at registration — by the model's constructor AND by the metabox's
     * register().
     *
     * Until v5.0.0 only the model asked. The same declaration that fatally
     * refused to register as a model was accepted by a plain post type and
     * surfaced later — as a save-time notice, or as a text input that stored
     * escaped markup over the real content. One `fields` array is one `fields`
     * array, whoever declares it.
     *
     * Four refusals, all of them at register(): a name outside the vocabulary,
     * a `cell = false` type inside a row, two sub-field names that
     * NTDST_FieldTypes::rowKey() folds into ONE key (a row holds one cell per
     * key, so the second declaration would silently take the first one's type),
     * and a sub-field that declares its own `sanitizer` — refused because
     * nothing runs it: the row walk cleans each cell by its DECLARED TYPE and
     * never looks for a callable, so the declaration means the author believes
     * a cell is being tightened while it is not. "Quietly does nothing" is the
     * worst answer a security declaration can get.
     *
     * `callback` is legal here and has no entry: it is a RENDER DIRECTIVE — the
     * field draws itself and the consumer's own code owns what it stores. It is
     * live on the fleet, so the gate accepts it; what a MODEL then does with one
     * is the model's own question, asked later.
     *
     * A repeater INSIDE a repeater is legal — `cell = false` is a RENDERING
     * verdict — and its own sub-fields are walked the same way.
     *
     * @param array<array-key, mixed> $fields
     * @param string $where names the declaring model or metabox: on a site this
     *        fatal is the whole bug report, and a message with no subject in it
     *        is one nobody can act on.
     *
     * @throws InvalidArgumentException naming the field, the sub-field and the
     *         canonical name to write instead.
     */
    public static function assertDeclarations(array $fields, string $where): void
    {
        $prefix = $where === '' ? '' : $where . ': ';

        foreach ($fields as $field => $declaration) {
            $field = (string) $field;
            $type = self::declaredType($declaration);

            if ($type === 'callback') {
                continue;
            }

            $entry = self::entry($type, "{$prefix}Field '{$field}'");

            if ($entry->name === 'repeater') {
                self::assertRow(
                    $field,
                    is_array($declaration) ? ($declaration['sub_fields'] ?? null) : null,
                    '',
                    $prefix,
                );
            }
        }
    }

    /**
     * Every sub-field of a repeater, at every depth, against this same table.
     *
     * @param mixed  $subFields the repeater's declared `sub_fields`, whatever shape it arrived in
     * @param string $path      the dotted trail to this row, so a depth-two fault names where it is
     */
    private static function assertRow(string $field, mixed $subFields, string $path, string $prefix): void
    {
        if (!is_array($subFields)) {
            return;
        }

        $seen = [];

        foreach ($subFields as $name => $declaration) {
            $name = (string) $name;
            $key = self::rowKey($name);
            $at = $path === '' ? $name : $path . '.' . $name;
            $where = "{$prefix}Field '{$field}' sub-field '{$at}'";

            if (is_array($declaration) && array_key_exists('sanitizer', $declaration)) {
                throw new InvalidArgumentException(
                    "{$where}: a sub-field cannot declare a 'sanitizer'.",
                );
            }

            if (isset($seen[$key])) {
                throw new InvalidArgumentException(
                    "{$where}: '{$seen[$key]}' and '{$name}' both sanitize to the key "
                    . "'{$key}', and a repeater row holds one cell per key.",
                );
            }

            $seen[$key] = $name;

            $type = self::declaredType($declaration);
            $entry = self::entry($type, $where);

            if ($entry->name === 'repeater') {
                self::assertRow(
                    $field,
                    is_array($declaration) ? ($declaration['sub_fields'] ?? null) : null,
                    $at,
                    $prefix,
                );

                continue;
            }

            if (!$entry->cell) {
                throw new InvalidArgumentException(
                    "{$where}: '{$type}' cannot be a repeater sub-field — it has no cell control. "
                    . "Use 'textarea' for a cell, or declare '{$type}' as a top-level field.",
                );
            }
        }
    }

    /**
     * The table's entry for a name, with the declaration that asked for it.
     *
     * get()'s message says what to write instead; this says WHERE.
     */
    private static function entry(string $type, string $where): NTDST_FieldType
    {
        try {
            return self::get($type);
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($where . ': ' . $e->getMessage(), 0, $e);
        }
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

        // The read side, by shape rather than by name — five closures for the
        // fourteen entries that need one. `int`, `float` and `bool` need none:
        // their sanitizer IS the cast a read owes.
        //
        // readString  the string family and `date`: back byte for byte. A read
        //             that re-cleaned would print a different string from the
        //             one the row holds, and pay sanitize/kses per field per row.
        // readId      `image`/`file`: the id that is stored, with NO lookup —
        //             whether the attachment still exists is the write side's
        //             question, and a lookup here is a query per field per row.
        // readArray   `array`/`json`: decode only. The keys were sanitized on
        //             the way in; sanitize_key() on a read can only rename them.
        // readIds     `relation`/`gallery`: the same rule that WROTE the list,
        //             so a read cannot disagree with a write about what an id is.
        // readRows    `repeater`: rows, and nothing else. No cell is
        //             re-sanitized, and nothing is unserialized — WordPress's
        //             maybe_unserialize() already ran, so a string still
        //             serialized here is not a value this model wrote.
        $readString = static fn(mixed $stored, array $config): string => is_scalar($stored) ? (string) $stored : '';
        $readId = static fn(mixed $stored, array $config): int => is_scalar($stored) ? (int) $stored : 0;
        $readArray = static fn(mixed $stored, array $config): array => self::decode($stored);
        $readIds = static fn(mixed $stored, array $config): array => self::ids(self::decode($stored));
        $readRows = static fn(mixed $stored, array $config): array => array_values(
            array_filter(self::decode($stored), 'is_array'),
        );

        $table = [];

        // name · sanitize · REST leaf shape · admin control · may sit in a row
        // · read (named, and only where the sanitizer is not already the cast)
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
                read: $readString,
            ),
            // Keeps the newlines sanitize_text_field() would flatten.
            new NTDST_FieldType(
                'textarea',
                static fn(mixed $value, array $config): string => sanitize_textarea_field(self::scalar($value)),
                ['type' => 'string'], 'textarea', true,
                read: $readString,
            ),
            // OUTPUT CONTRACT — the 'html' field type.
            //
            // Stored: wp_kses_post()'s answer and nothing else. It keeps a safe
            // HTML subset (<p>, <a href>, <strong>, <em>, <ul>/<li>, <br>, ...)
            // where 'textarea' would strip every tag. A <script> TAG does not
            // survive; the script's BODY does, as text — kses removes the tag
            // and keeps what was between them. So the stored value is markup
            // that is safe to PRINT, never a value that is safe to EXECUTE.
            //
            // Printing it is the consumer's half of the contract: run
            // wp_kses_post() again at render time. NEVER esc_html() (it encodes
            // the markup and prints a literal "<p>" to the visitor) and NEVER a
            // raw echo (wp_update_post() and direct DB access reach this value
            // without ever passing this table).
            //
            // cell = false: markup cannot be edited in a repeater row. A row
            // that renders it as a single-line text input hands the editor raw
            // markup in a one-line box — unusable, not lossy: esc_attr() on the
            // way out is undone by the browser on the way back.
            new NTDST_FieldType(
                'html',
                static fn(mixed $value, array $config): string => wp_kses_post(self::scalar($value)),
                ['type' => 'string'], 'html', false,
                read: $readString,
            ),
            new NTDST_FieldType(
                'email',
                static fn(mixed $value, array $config): string => sanitize_email(self::scalar($value)),
                ['type' => 'string', 'format' => 'email'], 'email', true,
                read: $readString,
            ),
            new NTDST_FieldType(
                'url',
                static fn(mixed $value, array $config): string => esc_url_raw(self::scalar($value)),
                ['type' => 'string', 'format' => 'uri'], 'url', true,
                read: $readString,
            ),
            new NTDST_FieldType(
                'date',
                static fn(mixed $value, array $config): string => self::date($value),
                ['type' => 'string'], 'date', true,
                read: $readString,
            ),
            // The option list is the admin's business; the stored value is
            // still only text (option validation is out of scope, D-scope).
            new NTDST_FieldType(
                'select',
                $text,
                ['type' => 'string'], 'select', true,
                read: $readString,
            ),
            // The metabox posts this as a JSON string, so a JSON string is
            // accepted as well as an array. Not publishable: the sanitizer
            // keeps a keyed map with typed scalars, and a leaf with no closed
            // shape is never published (threat row #2).
            new NTDST_FieldType(
                'array',
                $toArray,
                null, 'json', true,
                read: $readArray,
            ),
            new NTDST_FieldType(
                'json',
                $toArray,
                null, 'json', true,
                read: $readArray,
            ),
            // A single pick posts as a scalar; the field still stores a list.
            new NTDST_FieldType(
                'relation',
                static fn(mixed $value, array $config): array => self::ids(is_array($value) ? $value : [$value]),
                ['type' => 'array', 'items' => ['type' => 'integer']], 'relation', false,
                read: $readIds,
            ),
            // A gallery is a multi-pick control: a scalar is not a gallery.
            new NTDST_FieldType(
                'gallery',
                static fn(mixed $value, array $config): array => is_array($value) ? self::ids($value) : [],
                ['type' => 'array', 'items' => ['type' => 'integer']], 'gallery', false,
                read: $readIds,
            ),
            new NTDST_FieldType(
                'image',
                $attachmentId,
                ['type' => 'integer'], 'media', true,
                read: $readId,
            ),
            new NTDST_FieldType(
                'file',
                $attachmentId,
                ['type' => 'integer'], 'media', true,
                read: $readId,
            ),
            // The repeater's shape is structural, not a leaf: schemaFor()
            // recurses over sub_fields and builds the object itself.
            new NTDST_FieldType(
                'repeater',
                static fn(mixed $value, array $config): array => self::repeater($value, $config),
                null, 'repeater', false,
                read: $readRows,
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
     * date(), never gmdate(): both halves must read the same clock. strtotime()
     * parses in the process timezone, so the answer is written in it too.
     * WordPress forces that clock to UTC (wp-settings.php:73), which is why the
     * pairing only shows itself once something moves it — a plugin, a WP-CLI
     * command, a consumer calling date_default_timezone_set(). Then gmdate()
     * would lose a day east of UTC on every save.
     *
     * A year outside 0000-9999 is refused because date() writes five digits
     * there, which the next pass re-parses as a different date — and
     * register_post_meta() runs the sanitizer again on every REST write.
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
     * The decode is decode()'s, the same one the READ side uses: one answer to
     * "what array do these stored bytes mean", so a write and a read cannot
     * disagree about it.
     *
     * @return array<array-key, mixed>
     */
    private static function toArray(mixed $value): array
    {
        return self::nested(self::decode($value));
    }

    /**
     * What an array-shaped storage value MEANS — an array, or nothing.
     *
     * An array is itself. A string is the JSON the metabox textarea posts (and
     * what a write stored, once maybe_serialize() had a list to hold). Anything
     * else, and any string that is not a JSON array or object, is the empty
     * answer.
     *
     * NOTHING here unserializes. WordPress's own maybe_unserialize() has run by
     * the time a meta value reaches this table, so a string that is STILL
     * serialized is not a value this vocabulary wrote — and unserializing it is
     * object instantiation from stored bytes.
     *
     * @return array<array-key, mixed>
     */
    private static function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
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
     * A row is kept when any POSTED cell is not '' / null AND any SANITIZED
     * cell is not '' / null. The posted half keeps the answers that only look
     * falsy — `int '0'` is 0, `bool 'false'` is false. The sanitized half drops
     * the row whose only content was refused: `url 'javascript:x'` is '', and a
     * row kept on pass 1 but dropped on pass 2 is one the REST re-save deletes.
     *
     * @param  array<string, mixed> $config the repeater's own declaration
     * @return list<array<array-key, mixed>>
     */
    private static function repeater(mixed $rows, array $config): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $subFields = self::declarations($config['sub_fields'] ?? null);
        $sanitized = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !self::filled($row)) {
                continue;
            }

            $cells = [];
            foreach ($row as $key => $value) {
                $key = is_string($key) ? self::rowKey($key) : $key;
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

            if (self::filled($cells)) {
                $sanitized[] = $cells;
            }
        }

        return $sanitized;
    }

    /**
     * The sub-field declarations, keyed by rowKey() of the DECLARED name: a cell
     * is stored under that key, so the re-save arrives with it and `subTitle`
     * must stay reachable from `subtitle` — otherwise the cell loses its type on
     * every REST write and an int becomes text.
     *
     * @return array<array-key, mixed>
     */
    private static function declarations(mixed $subFields): array
    {
        if (!is_array($subFields)) {
            return [];
        }

        $keyed = [];
        foreach ($subFields as $name => $declaration) {
            $keyed[is_string($name) ? self::rowKey($name) : $name] = $declaration;
        }

        return $keyed;
    }

    /** Has this row an answer in it at all — any cell that is not '' or null? */
    private static function filled(array $row): bool
    {
        foreach ($row as $cell) {
            if ($cell !== '' && $cell !== null) {
                return true;
            }
        }

        return false;
    }
}
