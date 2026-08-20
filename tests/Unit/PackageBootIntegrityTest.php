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
     * Symbols v3.0.0 removed. Nothing shipped may still call them.
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
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            // vendor/ is third-party; tests/ and specs/ legitimately NAME the
            // removed symbols in order to assert their absence.
            if (str_contains($path, '/vendor/') || str_contains($path, '/tests/') || str_contains($path, '/specs/')) {
                continue;
            }

            foreach (file($path) as $n => $line) {
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
            "{$symbol} was removed in v3.0.0 but is still referenced in shipped code:\n" . implode("\n", $hits),
        );
    }

    public function testThePluginHeaderVersionMatchesWhatTheCodeSaysItIs(): void
    {
        // F2 — v3.0.0 shipped with `Version: 2.4.1` in its header while
        // api/Rest.php's _doing_it_wrong() call announced 3.0.0. WordPress
        // reports the header, so a consumer asking "what do I actually have"
        // got the wrong answer — and the v3 rename is a hard break with no
        // class_alias shims, which is exactly when that answer matters.
        $root = dirname(__DIR__, 2);

        preg_match('/^ \* Version: (.+)$/m', file_get_contents($root . '/ntdst-core.php'), $header);
        $this->assertNotEmpty($header, 'ntdst-core.php must carry a Version header — WP reads it.');

        preg_match("/_doing_it_wrong\(.*?'([0-9]+\.[0-9]+\.[0-9]+)',/s", file_get_contents($root . '/api/Rest.php'), $code);
        $this->assertNotEmpty($code, 'api/Rest.php names the version it belongs to.');

        $this->assertSame(
            $code[1],
            trim($header[1]),
            'The header version and the version the code self-identifies as must be the same release.',
        );
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
