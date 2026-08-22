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
     * Symbols v3.0.0 and v5.0.0 removed. Nothing shipped may still call them.
     *
     * @return array<string, array{0: string}>
     */
    public static function removedSymbolProvider(): array
    {
        return [
            'ntdst_api_action' => ['ntdst_api_action'],
            'ntdst_router' => ['ntdst_router'],
            'ntdst_route' => ['ntdst_route'],
            'ntdst_endpoints' => ['ntdst_endpoints'],
            'NTDST_Router' => ['NTDST_Router'],
            'NTDST_Endpoints' => ['NTDST_Endpoints'],
            // The sector system left the package: product domain, not
            // framework, and no functional consumer anywhere on the fleet.
            'NTDST_SectorRegistry' => ['NTDST_SectorRegistry'],
            'ntdst_sectors' => ['ntdst_sectors'],
            // v5.0.0 — the NTDST_Rest surface registry. WordPress records every
            // route it registers, so a second registry was a copy that could
            // disagree with the original. rest_get_server()->get_routes() is
            // the list now, and README shows the assertion over it.
            'publicSurface' => ['publicSurface'],
            'opaqueSurface' => ['opaqueSurface'],
            'forgetSurface' => ['forgetSurface'],
            'NtdstRestSurfaceTest' => ['NtdstRestSurfaceTest'],
        ];
    }

    /**
     * @dataProvider removedSymbolProvider
     */
    public function testNoShippedFileReferencesARemovedSymbol(string $symbol): void
    {
        $root = dirname(__DIR__, 2);
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
                if (str_contains($line, $symbol)) {
                    $hits[] = str_replace($root . '/', '', $path) . ':' . ($n + 1) . ' → ' . trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "{$symbol} was removed but is still referenced in shipped code:\n" . implode("\n", $hits),
        );
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
