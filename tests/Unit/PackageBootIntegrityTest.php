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
     * line must match, both or neither. Exactly one row needs it: the field
     * vocabulary's own table of retired names (api/FieldTypes.php) has to spell
     * `signed_int` out in order to answer "use 'int' instead" — naming a retired
     * type in the message that retires it is the same exemption README's
     * `## Versions` section already gets.
     *
     * The pattern is what keeps that exemption honest. A whole-FILE exemption
     * would let api/FieldTypes.php grow `new NTDST_FieldType('signed_int', …)`
     * — the retired name back in the vocabulary, in the one file this test
     * agreed not to look at. The retirement row may say it; nothing else in
     * that file may.
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
            'restSchemaFor' => ['restSchemaFor', '5.0.0'],
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
     * A retired name may be spelled by the ONE line that retires it, and by no
     * other line in that file.
     *
     * The sweep is driven over a throwaway tree here, because the promise is
     * about a file api/FieldTypes.php could grow, not about the file it is
     * today: a whole-file exemption passes this and a line exemption does not.
     */
    public function testTheRetirementLineIsExemptAndNothingElseInThatFileIs(): void
    {
        $root = sys_get_temp_dir() . '/ntdst-sweep-' . getmypid() . '-' . uniqid();
        mkdir($root . '/api', 0777, true);
        file_put_contents($root . '/api/FieldTypes.php', implode("\n", [
            '<?php',
            "        'signed_int'    => 'int',",
            "            new NTDST_FieldType('signed_int', \$cast, ['type' => 'integer'], 'number', true),",
            '',
        ]));

        try {
            $hits = $this->sweep($root, 'signed_int', 'api/FieldTypes.php', "/^\s*'signed_int'\s*=>\s*'int',\s*$/");

            $this->assertCount(1, $hits, "Exactly one line is a hit — the retirement row is exempt:\n" . implode("\n", $hits));
            $this->assertStringContainsString('api/FieldTypes.php:3', $hits[0]);
            $this->assertStringContainsString('new NTDST_FieldType', $hits[0], 'The vocabulary may not grow the retired name back.');
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
                if (str_contains($line, $symbol)) {
                    $hits[] = str_replace($root . '/', '', $path) . ':' . ($n + 1) . ' → ' . trim($line);
                }
            }
        }

        return $hits;
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
