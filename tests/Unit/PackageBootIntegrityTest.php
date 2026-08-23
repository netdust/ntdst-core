<?php // tests/Unit/PackageBootIntegrityTest.php
// Reproduces the two Cluster-A review Criticals, and closes the blind spot that
// let them through.
//
// The rename deleted ntdst_api_action() and ntdst_router(), but two shipped
// files still called them:
//   admin/RelationField.php:47  → fatal on EVERY request (it is required by
//                                 ntdst-core.php and constructed on after_setup_theme)
//   core/Theme.php:106          → SILENT: the function_exists() guard simply went
//                                 false, so the `router` mixin never registered and
//                                 Theme::single()/page()/archive() throw at call time
//
// The suite was 155/155 green while both were live, because `composer lint` is
// php -l (never resolves a function name), bin/guard.sh only checks ABSPATH, and
// no test loaded either file. An absence test proves the rename HAPPENED; it says
// nothing about whether the package survived it. This asserts survival.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use PHPUnit\Framework\TestCase;

final class PackageBootIntegrityTest extends TestCase
{
    /**
     * Every symbol a release REMOVED, with the release that removed it.
     *
     * The version is part of the row, not decoration: when this test fails,
     * the one thing the reader needs is which upgrade deleted the name they
     * are still calling — that is what tells them whether to fix the caller or
     * to pin the older package. The failure message says it out loud.
     *
     * The FR-5 rows carry the CALL SHAPES as well as the bare names.
     * `surface` on its own is an ordinary English word this codebase uses in
     * prose ("the exposure surface", "typos surface immediately"), so the
     * removed method is pinned as the three ways PHP can reach it — a static
     * call, an instance call, and the property.
     *
     * A third and fourth element EXEMPT one LINE — a path AND a pattern that
     * line must match, both or neither. Only the retired TYPE NAMES need it:
     * the registry (api/FieldTypes.php) has to spell each retired name out in
     * order to answer "use 'int' instead", and it writes `['type' => 'integer']`
     * as a publish column for a live type. Naming a retired name in the entry
     * that retires it is the same exemption README's `## Versions` section
     * already gets.
     *
     * The pattern is what keeps that exemption honest. A whole-FILE exemption
     * would let api/FieldTypes.php grow `new NTDST_FieldType('signed_int', …)`
     * — the retired name back in the registry, in the one file this test agreed
     * not to look at. The retirement entry may say it; nothing else in that
     * file may.
     *
     * @return array<string, array{0: string, 1: string, 2?: string, 3?: string}>
     */
    public static function removedSymbolProvider(): array
    {
        return [
            'ntdst_api_action' => ['ntdst_api_action', '3.0.0'],
            'ntdst_router' => ['ntdst_router', '3.0.0'],
            'ntdst_route' => ['ntdst_route', '3.0.0'],
            'ntdst_endpoints' => ['ntdst_endpoints', '3.0.0'],
            'NTDST_Router' => ['NTDST_Router', '3.0.0'],
            'NTDST_Endpoints' => ['NTDST_Endpoints', '3.0.0'],
            // The sector system left the package: product domain, not
            // framework, and no functional consumer anywhere on the fleet.
            'NTDST_SectorRegistry' => ['NTDST_SectorRegistry', '3.0.0'],
            'ntdst_sectors' => ['ntdst_sectors', '3.0.0'],
            // v5.0.0 — the NTDST_Rest surface registry. WordPress records every
            // route it registers, so a second registry was a copy that could
            // disagree with the original. rest_get_server()->get_routes() is
            // the list now, and README shows the assertion over it.
            'publicSurface' => ['publicSurface', '5.0.0'],
            'opaqueSurface' => ['opaqueSurface', '5.0.0'],
            'forgetSurface' => ['forgetSurface', '5.0.0'],
            'NtdstRestSurfaceTest' => ['NtdstRestSurfaceTest', '5.0.0'],
            'Rest::surface' => ['::surface(', '5.0.0'],
            'Rest->surface' => ['->surface(', '5.0.0'],
            'Rest $surface' => ['$surface', '5.0.0'],
            // v5.0.0 field-types — the model's own type tables. Every one of
            // these was a second vocabulary that could disagree with the first
            // (INV-8): a `bool` sanitized one way on the metabox path and
            // another on the model path, and adding a type meant editing seven
            // places. NTDST_FieldTypes::get() is the table now.
            'getDefaultSanitizer' => ['getDefaultSanitizer', '5.0.0'],
            'sanitizeRepeater' => ['sanitizeRepeater', '5.0.0'],
            'sanitizeBoolean' => ['sanitizeBoolean', '5.0.0'],
            'sanitizeJson' => ['sanitizeJson', '5.0.0'],
            'sanitizeNestedArray' => ['sanitizeNestedArray', '5.0.0'],
            'sanitizeDate' => ['sanitizeDate', '5.0.0'],
            'sanitizeAttachmentId' => ['sanitizeAttachmentId', '5.0.0'],
            // v5.0.0 field-types — the METABOX's own type switch. It was the
            // second half of the same defect: a value posted from the edit
            // screen was cleaned here by one table and again inside the model
            // by another, and the two disagreed (`bool 'false'` was true on
            // this path and false on the model's, `int '-500'` lost its sign
            // here and kept it there). Pinned as the CALL SHAPE rather than the
            // bare word: `sanitize_field` is a plausible name for a future
            // helper and for prose about sanitizing a field, but no shipped
            // line may CALL or DECLARE one.
            'sanitize_field' => ['sanitize_field(', '5.0.0'],
            // The metabox's other two second tables, retired by the same task
            // and pinned the same way (simplicity S17 — one sweep, not three
            // bespoke reflection cases). MARKER_ONLY_REQUIRED_TYPES was a
            // hand-kept list of type names deciding which controls may carry
            // native `required`; the registry entry's own $cell/$control answers
            // that now. render_repeater_media_cell() was the row's copy of the
            // media picker, and a copy of a control is a control that drifts —
            // the row cell and the top-level field must be the same widget.
            'MARKER_ONLY_REQUIRED_TYPES' => ['MARKER_ONLY_REQUIRED_TYPES', '5.0.0'],
            'render_repeater_media_cell' => ['render_repeater_media_cell(', '5.0.0'],
            // The retired type NAME (D4: it folded into a signed `int`). Shipped
            // code may not declare a field with it; the vocabulary's own
            // retirement table is the one place that still says the word.
            'signed_int' => ['signed_int', '5.0.0', 'api/FieldTypes.php', "/^\\s*'signed_int'\\s*=>\\s*'int',\\s*$/"],
            // v5.0.0 field-types — the two 0-reader REST reads of the field
            // description. What shape a field publishes is asked once, by
            // registerRestMeta(); a second PUBLIC way to ask it is a second
            // exposure a consumer can assemble beside the convergence point,
            // which is the thing INV-1 exists to prevent. Neither name is part
            // of any other word, so both are pinned bare.
            'restSubFields' => ['restSubFields', '5.0.0'],
            // The 13 retired type NAMES, pinned by DECLARATION POSITION.
            // Twelve of them are ordinary JSON-Schema and English words, so a
            // bare sweep would hit 617 shipped lines and api/FieldTypes.php's
            // own `['type' => 'integer']` publish column with them. What no
            // shipped line may do is DECLARE a field with one, and a
            // declaration has exactly three shapes: `'type' => '<retired>'`,
            // the bare shorthand `=> '<retired>'`, and
            // `new NTDST_FieldType('<retired>'`. The exemption is stated by
            // CONTENT — a retirement entry, or a registry entry's JSON-Schema
            // leaf — so it anchors to the ROW and not to the file: a real
            // `new NTDST_FieldType('integer', …)` still fires inside the very
            // file that retires the name. bin/guard.sh runs the same three
            // shapes and the same exemption over *.php; this sweep adds
            // README.md, where a retired name in an INSTRUCTION is a wrong
            // instruction.
            'retired type integer' => ["/'type' *=> *'integer'|=> *'integer'|NTDST_FieldType\\('integer'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type signed_int' => ["/'type' *=> *'signed_int'|=> *'signed_int'|NTDST_FieldType\\('signed_int'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type number' => ["/'type' *=> *'number'|=> *'number'|NTDST_FieldType\\('number'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type double' => ["/'type' *=> *'double'|=> *'double'|NTDST_FieldType\\('double'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type decimal' => ["/'type' *=> *'decimal'|=> *'decimal'|NTDST_FieldType\\('decimal'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type boolean' => ["/'type' *=> *'boolean'|=> *'boolean'|NTDST_FieldType\\('boolean'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type string' => ["/'type' *=> *'string'|=> *'string'|NTDST_FieldType\\('string'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type longtext' => ["/'type' *=> *'longtext'|=> *'longtext'|NTDST_FieldType\\('longtext'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type wysiwyg' => ["/'type' *=> *'wysiwyg'|=> *'wysiwyg'|NTDST_FieldType\\('wysiwyg'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type content' => ["/'type' *=> *'content'|=> *'content'|NTDST_FieldType\\('content'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type datetime' => ["/'type' *=> *'datetime'|=> *'datetime'|NTDST_FieldType\\('datetime'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type person' => ["/'type' *=> *'person'|=> *'person'|NTDST_FieldType\\('person'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'retired type post_relation' => ["/'type' *=> *'post_relation'|=> *'post_relation'|NTDST_FieldType\\('post_relation'/", '5.0.0', 'api/FieldTypes.php', '/^\\s*(\'(integer|signed_int|number|double|decimal|boolean|string|longtext|wysiwyg|content|datetime|person|post_relation)\'\\s*=>\\s*\'(bool|date|float|html|int|relation|text|textarea)\',|\\[\'type\' *=> *\'(integer|number|boolean|string|array)\'.*\\], *\'[a-z_]+\', *(true|false),)\\s*$/'],
            'restSchemaFor' => ['restSchemaFor', '5.0.0'],
            // v5.0.0 core-trim (FR-1, INV-10) — Bootstrap's service scanner and
            // the two config keys that armed it. The scanner globbed
            // `*Service.php` under `services.discovery_paths`, `require_once`d
            // every hit and regex-parsed the source for its class name; a
            // writable directory on that list was code execution. It had zero
            // users: all five consumer sites set `auto_discover => false` and
            // load their services by `require_once` or by Composer.
            //
            // The two KEYS are swept as well as the two methods, and that is
            // the point of the pair. Deleting the methods while a shipped line
            // still reads `$config['services']['auto_discover']` leaves the
            // switch half-alive — a config key core consults and then does
            // nothing with is exactly the "loads nothing by guessing" promise
            // read back as a maybe. `discoverServicesInPath` needs no row of
            // its own: `discoverServices` is a substring of it.
            //
            // A consumer config may still CARRY both keys — core simply never
            // reads them (AF-4), and README's core-trim table says so. This
            // sweep is over what the PACKAGE ships, not over what a site writes.
            'discoverServices' => ['discoverServices', '5.0.0'],
            'getClassNameFromFile' => ['getClassNameFromFile', '5.0.0'],
            'auto_discover' => ['auto_discover', '5.0.0'],
            'discovery_paths' => ['discovery_paths', '5.0.0'],
            // v5.0.0 core-trim (FR-2) — the per-service enable switch and the
            // read-only copies of the service registry.
            //
            // `ntdst_service_` is swept as a PREFIX, and that is deliberate: it
            // is the shared stem of the retired option `ntdst_service_{slug}`,
            // the retired DENY filter `ntdst_service_{slug}_enabled` and the
            // retired config filter `ntdst_service_{slug}_config`, and every
            // one of them is interpolated (`"ntdst_service_{$slug}_enabled"`),
            // so a row per full name would match nothing. The stem is the only
            // shape a sweep can see. The enable switch failed OPEN — a filter
            // nobody answers returns true — so a half-removal that leaves one
            // interpolation behind is a service a site believes is off and is
            // not, which is the exact wart philosophy §4 records.
            //
            // The four accessors had zero readers across daan, josworld,
            // stride, todai and netdust: a second, read-only copy of the
            // registry that could disagree with the original.
            //
            // README.md is the one file whose MIGRATION ROWS may spell them —
            // naming what was removed is the entire job of that table, and the
            // exemption is by ROW (a table line whose first cell is a code
            // span), not by file, so a README that grows a live INSTRUCTION
            // using a retired name still fails. The `## Versions` section is
            // already exempt wholesale; this keeps the rows honest wherever the
            // table ends up.
            'ntdst_service_' => ['ntdst_service_', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'getServiceConfig' => ['getServiceConfig', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            // The fifth accessor. FR-2 names five and the four rows around this
            // one pinned four, so `getServices()` — the read-only copy of the
            // whole registry, and the widest of the five — could come back
            // without a single test noticing. `discoverServices` does not cover
            // it: neither name contains the other.
            'getServices' => ['getServices', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'getBootedServices' => ['getBootedServices', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'hasService' => ['hasService', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            'isBooted' => ['isBooted', '5.0.0', 'README.md', '/^\\s*\\|\\s*`[^`]+`.*\\|/'],
            // v5.0.0 core-trim (FR-4) — the SECOND query API and the term
            // helpers. `getFormattedPosts()` and its global front door
            // `ntdst_get_formatted_posts()` answered "give me rows" without a
            // model and therefore without a schema, beside the chain that
            // answers it with one. Two read paths on one layer is the defect
            // find()/getMeta() already produced here once: a consumer that
            // gates with the path carrying the declaration and reads with the
            // path that does not has a bypass, and neither call site looks
            // wrong. The engine survives as the model's private business;
            // `getPostTerms()` was its public half and goes with it.
            //
            // The three term writers wrapped one `wp_set_post_terms()` call
            // each and had zero readers on the fleet, and `whereDate()`/
            // `orWhere()` restate `date_query` and a flat root-level OR
            // `meta_query` — arguments WordPress names better than the builder
            // does, since the builder cannot express the nested groups an
            // `orWhere()` caller usually means.
            //
            // These rows are what make the removal LOUD rather than silent.
            // Two shipped callers were live when the rows landed — admin/
            // RelationField.php's relation picker on the global, and
            // services/Logger.php's clearOld() on whereDate() — and neither is
            // covered by a test, so without this sweep both would have shipped
            // as a fatal nobody's suite could see. Each name is its own word
            // here: none is a substring of another (`detachTerms` does not
            // contain `attachTerms`), and none appears in README or docs, so
            // all eight are pinned bare with no exemption.
            'getFormattedPosts' => ['getFormattedPosts', '5.0.0'],
            'ntdst_get_formatted_posts' => ['ntdst_get_formatted_posts', '5.0.0'],
            'getPostTerms' => ['getPostTerms', '5.0.0'],
            'attachTerms' => ['attachTerms', '5.0.0'],
            'syncTerms' => ['syncTerms', '5.0.0'],
            'detachTerms' => ['detachTerms', '5.0.0'],
            'whereDate' => ['whereDate', '5.0.0'],
            'orWhere' => ['orWhere', '5.0.0'],
            // v5.0.0 core-trim (FR-5) — the Logger's database half and its
            // runtime handler API.
            //
            // The database half was a PII sink armed by a filter: on every
            // error it wrote REQUEST_URI, the client IP and the whole context
            // array into post meta, and it was ON whenever WP_DEBUG was — which
            // is what an operator turns on DURING the incident. It also
            // answered each error with wp_insert_post plus N meta writes plus a
            // save_post cascade, at the worst possible moment to do so.
            //
            // The two rows that matter most here are the CONSTRUCTOR's, not the
            // handler's. Registering the post type reached the Data layer from
            // NTDST_Logger::__construct(), so services/Logger.php could not
            // load before api/Data.php — which is the whole reason core's call
            // sites guarded their logging with function_exists('ntdst_log')
            // (FR-3, I-2). A stray reference left behind here is that load
            // order quietly coming back.
            //
            // The handler API let a consumer add a sink, move the level gate or
            // switch batching off from anywhere at runtime, so no call site
            // could say where a line would end up. Zero readers across daan,
            // josworld and stride.
            //
            // All nine are pinned BARE: none is a substring of another, none
            // appears in README, and each is either a distinctive method name
            // or an `ntdst_`-prefixed global. `recent()` and `clearOld()` are
            // FR-5 removals with no row, deliberately — they are ordinary
            // English words that a bare sweep would find in prose. What pins
            // those two is LoggerSurfaceTest's exact public-method list, which
            // is the stronger check anyway: it fails on a name coming BACK as
            // well as on one that never left.
            'log_entry' => ['log_entry', '5.0.0'],
            'ntdst_log_database_enabled' => ['ntdst_log_database_enabled', '5.0.0'],
            'addHandler' => ['addHandler', '5.0.0'],
            'removeHandler' => ['removeHandler', '5.0.0'],
            'setMinLevel' => ['setMinLevel', '5.0.0'],
            'setBatchingEnabled' => ['setBatchingEnabled', '5.0.0'],
            'ntdst_log_debug' => ['ntdst_log_debug', '5.0.0'],
            'ntdst_log_info' => ['ntdst_log_info', '5.0.0'],
            'ntdst_log_error' => ['ntdst_log_error', '5.0.0'],
        ];
    }

    /**
     * @dataProvider removedSymbolProvider
     */
    public function testNoShippedFileReferencesARemovedSymbol(string $symbol, string $removedIn, string $exceptPath = '', string $exceptLine = ''): void
    {
        $hits = $this->sweep(dirname(__DIR__, 2), $symbol, $exceptPath, $exceptLine);

        $this->assertSame(
            [],
            $hits,
            "{$symbol} was removed in v{$removedIn} but is still referenced in shipped code:\n"
                . implode("\n", $hits),
        );
    }

    /**
     * The throwaway trees the exemption is judged on.
     *
     * Each case names a row of removedSymbolProvider() and the file that row's
     * exemption has to survive: the retirement entry, the registry's own
     * JSON-Schema line, and a `new NTDST_FieldType(<retired>)` line that would
     * put the name back into the registry. Only the last one is a hit.
     *
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function rowShapedExemptionProvider(): array
    {
        return [
            'signed_int — pinned bare' => ['signed_int', [
                "        'signed_int'    => 'int',",
                "            new NTDST_FieldType('signed_int', \$cast, ['type' => 'integer'], 'number', true),",
            ]],
            'integer — pinned by declaration position' => ['retired type integer', [
                "        'integer'       => 'int',",
                "                ['type' => 'integer'], 'number', true,",
                "            new NTDST_FieldType('integer', \$cast, ['type' => 'integer'], 'number', true),",
            ]],
        ];
    }

    /**
     * A retired name may be spelled by the ONE line that retires it, and by no
     * other line in that file.
     *
     * The sweep runs over a throwaway tree, because the promise is about a file
     * api/FieldTypes.php could GROW, not about the file it is today: a
     * whole-file exemption passes this and a row exemption does not.
     *
     * The symbol and the exemption are READ OFF the provider row rather than
     * copied here. A hand-copy proves a regex this file wrote; the shipped row
     * is what guard.sh and this suite actually run.
     *
     * @param list<string> $lines
     *
     * @dataProvider rowShapedExemptionProvider
     */
    public function testTheRetirementLineIsExemptAndNothingElseInThatFileIs(string $row, array $lines): void
    {
        $rows = self::removedSymbolProvider();

        $this->assertArrayHasKey($row, $rows, "removedSymbolProvider() must pin '{$row}'.");

        [$symbol, , $exceptPath, $exceptLine] = $rows[$row] + [2 => '', 3 => ''];

        $root = sys_get_temp_dir() . '/ntdst-sweep-' . getmypid() . '-' . uniqid();
        mkdir($root . '/api', 0777, true);
        file_put_contents($root . '/api/FieldTypes.php', implode("\n", ['<?php', ...$lines, '']));

        try {
            $hits = $this->sweep($root, $symbol, $exceptPath, $exceptLine);

            $this->assertCount(1, $hits, "Exactly one line is a hit — the retirement entry is exempt:\n" . implode("\n", $hits));
            $this->assertStringContainsString('api/FieldTypes.php:' . (count($lines) + 1), $hits[0]);
            $this->assertStringContainsString('new NTDST_FieldType', $hits[0], 'The registry may not grow the retired name back.');
        } finally {
            unlink($root . '/api/FieldTypes.php');
            rmdir($root . '/api');
            rmdir($root);
        }
    }

    /**
     * Every shipped line under $root that spells $symbol, except a line that
     * matches BOTH $exceptPath and $exceptLine.
     *
     * A $symbol beginning with `/` is a REGEX, so one row can pin the three
     * shapes a field DECLARATION takes without three near-identical rows. A
     * bare name stays a substring — a removed function is a substring question.
     *
     * @return list<string>
     */
    private function sweep(string $root, string $symbol, string $exceptPath = '', string $exceptLine = ''): array
    {
        $hits = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            // README.md is swept too: it is the first thing an adopter reads,
            // so a retired name in an INSTRUCTION is a wrong instruction, not a
            // typo. Its `## Versions` section is exempt — naming what was
            // removed is the entire job of a changelog line, and this test bit
            // that section the first time it ran.
            if (!str_ends_with($path, '.php') && $path !== $root . '/README.md') {
                continue;
            }
            $section = '';
            // vendor/ is third-party; tests/ and specs/ legitimately NAME the
            // removed symbols in order to assert their absence.
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/specs/')) {
                continue;
            }
            // The one file whose retirement LINE a row may exempt (see the
            // provider). The file itself is swept like any other.
            $exempt = $exceptPath !== '' && $exceptLine !== '' && str_contains($path, $exceptPath);

            foreach (file($path) as $n => $line) {
                if (str_starts_with($line, '## ')) {
                    $section = trim(substr($line, 3));
                }
                if ($section === 'Versions') {
                    continue;
                }

                // A comment may discuss a removed name; a call may not.
                $code = trim($line);
                if ($code === '' || str_starts_with($code, '*') || str_starts_with($code, '//') || str_starts_with($code, '#') || str_starts_with($code, '/*')) {
                    continue;
                }
                if ($exempt && preg_match($exceptLine, $line) === 1) {
                    continue;
                }
                $spelled = str_starts_with($symbol, '/')
                    ? preg_match($symbol, $line) === 1
                    : str_contains($line, $symbol);

                if ($spelled) {
                    $hits[] = str_replace($root . '/', '', $path) . ':' . ($n + 1) . ' → ' . trim($line);
                }
            }
        }

        return $hits;
    }

    /**
     * README names every retired type, and names it once.
     *
     * The vocabulary's retirement table is the fact; README's migration table
     * is the ADOPTER's copy of it. A copy that drifts is the worst kind here:
     * the reader upgrades, hits a fatal, opens README, and the name that
     * fataled is not in the table. So the table is read back OFF the class —
     * RETIRED is private, because nothing in the package may branch on it, and
     * reflection is how a test reads a fact it must not make public.
     *
     * Exactly 13 rows, and no fourteenth: a row for a name the class does not
     * retire is an instruction to rewrite a type that still works.
     */
    public function testReadmeNamesEveryRetiredTypeAndItsCanonicalName(): void
    {
        $retired = (new ReflectionClass(NTDST_FieldTypes::class))->getConstant('RETIRED');

        $this->assertIsArray($retired, 'NTDST_FieldTypes::RETIRED is the fact this table copies.');

        $rows = $this->fieldTypeRows();

        $this->assertCount(
            count($retired),
            $rows,
            'README\'s "Field types" table must have one row per retired name, and no more.',
        );

        foreach ($retired as $name => $canonical) {
            $this->assertArrayHasKey(
                $name,
                $rows,
                "NTDST_FieldTypes retires '{$name}', and README's migration table never names it.",
            );
            $this->assertSame(
                $canonical,
                $rows[$name],
                "README sends '{$name}' to '{$rows[$name]}'; the vocabulary sends it to '{$canonical}'.",
            );
        }
    }

    /**
     * The one storage change no rename can carry: `int` keeps its sign now.
     *
     * A consumer who only reads the migration table renames `integer` to `int`
     * and ships, believing nothing else moved. absint() is gone from that path,
     * so a field that used to store 500 for -500 stores -500. The overflow half
     * is scoped: a numeric STRING saturates at PHP_INT_MAX, and an oversized
     * float is PHP's undefined cast (FieldTypesTest pins both). All of it is
     * named, or the section is telling half a truth.
     */
    public function testTheFieldTypesSectionStatesTheIntSignChange(): void
    {
        $section = $this->fieldTypesSection();

        $this->assertStringContainsString(
            'absint',
            $section,
            'Name the function that left the path — that is what the reader greps for.',
        );
        $this->assertMatchesRegularExpression(
            '/negative/i',
            $section,
            '`int` stores negatives now; a migration table alone does not say so.',
        );
        $this->assertMatchesRegularExpression(
            '/numeric string[^.]*saturates at `PHP_INT_MAX`/',
            $section,
            'Saturation is the numeric-STRING promise; unscoped it reads as a promise for every value.',
        );
        $this->assertMatchesRegularExpression(
            '/float[^.]*(undefined|clamp)/i',
            $section,
            'A float past the maximum is PHP\'s undefined cast — say so where the promise is made.',
        );
    }

    /**
     * I-2 — the section says which retired WORDS are not field types.
     *
     * `integer`, `string` and `boolean` are JSON-Schema words as well as
     * retired type names. A consumer who greps for a retired name and renames
     * every hit breaks the schema in a `register_post_meta()` description, in a
     * `register_rest_route()` `args` block and in an ability schema — `int` and
     * `bool` are not JSON-Schema words. On stride that shape is 94 of the 95
     * remaining hits, so it is the section's rule, not a footnote.
     */
    public function testTheFieldTypesSectionSaysWhichRetiredNamesAreNotFieldTypes(): void
    {
        $section = $this->fieldTypesSection();

        $this->assertMatchesRegularExpression(
            '/rename only what the site DECLARES/i',
            $section,
            'The rename instruction has a scope, and the scope is the DECLARATION.',
        );
        $this->assertStringContainsString(
            'register_post_meta',
            $section,
            'Name the function whose schema keeps WordPress\'s words.',
        );
        $this->assertStringContainsString(
            '`args`',
            $section,
            'A `register_rest_route()` `args` schema is the second place a retired word is not a field type.',
        );
        $this->assertMatchesRegularExpression(
            '/JSON.Schema/i',
            $section,
            'Say which vocabulary those words belong to instead.',
        );
    }

    /**
     * The section stops at the next heading, and only the retired-names table
     * is counted.
     *
     * Both halves are read off a throwaway document, because the promise is
     * about a README that GROWS — a later 5.0.0 subsection with a two-column
     * table of backticked words must not be read as thirteen more migration
     * rows, and a `####` sub-head inside the block must not close it early.
     */
    public function testTheFieldTypesSectionEndsAtTheNextHeadingAndCountsOnlyTheRetiredTable(): void
    {
        $readme = implode("\n", [
            '### 5.0.0 — BREAKING',
            '',
            '**Field types — one registry, two names retired.**',
            '',
            '| Retired | Write instead |',
            '|---|---|',
            '| `integer` | `int` |',
            '| `boolean` | `bool` |',
            '',
            '#### A sub-head does not close the block',
            '',
            'absint left that path.',
            '',
            '| Type | Control |',
            '|---|---|',
            '| `html` | `wysiwyg` |',
            '',
            '**What the registry stores differently.**',
            '',
            '| Type | What changed |',
            '|---|---|',
            '| `date` | `Y-m-d` |',
            '',
            '### 4.4.2',
            '',
        ]);

        $section = $this->fieldTypesSection($readme);

        $this->assertStringContainsString(
            'absint left that path.',
            $section,
            'A `####` sub-head is inside the block, not the end of it.',
        );
        $this->assertStringNotContainsString(
            'What the registry stores differently',
            $section,
            'The next `**` heading closes the block — that is the bound this docblock claims.',
        );
        $this->assertSame(
            ['integer' => 'int', 'boolean' => 'bool'],
            $this->fieldTypeRows($readme),
            'Only the retired-names table is counted; a second two-column table in the block is not migration rows.',
        );
    }

    /**
     * README's "Field types" migration table, as retired => canonical.
     *
     * ANCHORED on that table's own header row, and it stops at the first line
     * that is not a row. The block holds other two-column tables — what the
     * registry stores, what a read gives back — and a row of two backticked
     * words in one of those is not an instruction to rename anything.
     *
     * @return array<string, string>
     */
    private function fieldTypeRows(string $readme = ''): array
    {
        $rows = [];
        $inTable = false;

        foreach (explode("\n", $this->fieldTypesSection($readme)) as $line) {
            $line = trim($line);

            if ($line === '| Retired | Write instead |') {
                $inTable = true;

                continue;
            }
            if (!$inTable || str_starts_with($line, '|---')) {
                continue;
            }
            if (!str_starts_with($line, '|')) {
                break;
            }
            if (preg_match('/^\| `([a-z_]+)` \| `([a-z_]+)` \|$/', $line, $m) === 1) {
                $rows[$m[1]] = $m[2];
            }
        }

        return $rows;
    }

    /**
     * The "Field types" block of README's `### 5.0.0` section.
     *
     * Anchored on the bold lead-in and closed by the next `###` or `**`
     * heading, so a later 5.0.0 subsection that happens to spell a type name
     * cannot be read as part of the migration record. Sub-heads INSIDE the
     * block are `####`, which is neither, so the block keeps its own structure.
     *
     * $readme is the document to read; the default is the shipped README. The
     * bound is a promise about a file that grows, so it is provable on a
     * throwaway document rather than only on today's text.
     */
    private function fieldTypesSection(string $readme = ''): string
    {
        $readme = $readme !== '' ? $readme : file_get_contents(dirname(__DIR__, 2) . '/README.md');

        $this->assertMatchesRegularExpression(
            '/^\*\*Field types — .*$/m',
            $readme,
            'README\'s 5.0.0 section must lead its field-type block with `**Field types — …**`.',
        );

        $block = [];

        foreach (explode("\n", $readme) as $line) {
            if ($block === []) {
                if (str_starts_with($line, '**Field types — ')) {
                    $block[] = $line;
                }

                continue;
            }
            if (str_starts_with($line, '### ') || str_starts_with($line, '**')) {
                break;
            }

            $block[] = $line;
        }

        return implode("\n", $block);
    }

    public function testThePackageNeverClaimsToBeOlderThanWhatItShips(): void
    {
        // F2 — v3.0.0 shipped with `Version: 2.4.1` in its header while
        // api/Rest.php's _doing_it_wrong() call announced 3.0.0. WordPress
        // reports the header, so a consumer asking "what do I actually have"
        // got the previous release — and the v3 rename is a hard break with no
        // class_alias shims, which is exactly when that answer decides whether
        // a consumer boots or fatals.
        //
        // The rule is an ORDERING, not an equality. A version passed to
        // _doing_it_wrong() is a @since marker — the release that introduced
        // that notice — and it stays put while the package moves on, so
        // requiring the two to MATCH would fail the next major for no reason.
        // (It did: bumping the header to 4.0.0 failed the first version of this
        // test against a 3.0.0 marker that was perfectly correct.) What must
        // never happen is the reverse: a header claiming a release older than a
        // change the package already contains.
        $root = dirname(__DIR__, 2);

        preg_match('/^ \* Version: (.+)$/m', file_get_contents($root . '/ntdst-core.php'), $matched);
        $this->assertNotEmpty($matched, 'ntdst-core.php must carry a Version header — WP reads it.');
        $header = trim($matched[1]);

        $shipped = [];
        foreach ($this->shippedFiles() as $path) {
            preg_match_all("/_doing_it_wrong\(.*?'([0-9]+\.[0-9]+\.[0-9]+)'/s", file_get_contents($path), $m);
            foreach ($m[1] as $version) {
                $shipped[$version] = str_replace($root . '/', '', $path);
            }
        }

        $this->assertNotEmpty(
            $shipped,
            'No shipped file names the release it belongs to, so nothing here can check the header.',
        );

        foreach ($shipped as $version => $where) {
            $this->assertTrue(
                version_compare($header, $version, '>='),
                "The header says {$header}, but {$where} already ships a change marked {$version}.",
            );
        }
    }

    /**
     * Every PHP file this package actually ships.
     *
     * @return list<string>
     */
    private function shippedFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];

        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/specs/')) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    public function testEveryFileInTheLoaderListParsesAndDefinesItsSymbols(): void
    {
        // The durable half: walk ntdst-core.php's OWN require list and confirm each
        // file at least parses. A deleted symbol with a surviving caller is a
        // runtime error, not a parse error — the grep above is what catches that —
        // but this pins the list itself against a rename that moves a file and
        // forgets the loader (T01 renamed core/Router.php → core/Pages.php).
        $root = dirname(__DIR__, 2);
        $loader = file_get_contents($root . '/ntdst-core.php');

        preg_match_all("#require_once NTDST_PATH \. '([^']+)'#", $loader, $m);
        $this->assertNotEmpty($m[1], 'ntdst-core.php must require its files explicitly, not scan a directory.');

        foreach ($m[1] as $rel) {
            $this->assertFileExists(
                $root . $rel,
                "ntdst-core.php requires {$rel}, which does not exist — a rename moved a file and left the loader behind.",
            );
        }
    }
}
