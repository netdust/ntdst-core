<?php // tests/Unit/CoreTrimClusterDFeatureTest.php
// core-trim Cluster D — the docs-to-code seam, driven the way a release manager
// drives it before tagging v5.0.0.
//
// Cluster D promised three things a human is asked to trust at the tag: the
// zero-reader sweep is SILENT and the silence is earned (INV-9), core loads
// nothing by guessing and the check that says so still BITES (INV-10), and
// README is the fleet's migration record — every documented extension point
// still fires, and every "Was" symbol is really gone.
//
// These tests never read the implementation to decide what to assert. They run
// the checks as ARCHITECTURE-INVARIANTS.md publishes them, from outside, and
// compare the documents against the shipped package.
//
// Not duplicated here (T13 owns them):
//   PackageBootIntegrityTest::testEveryRemovedFiveOhSymbolHasAMigrationRow  — provider → row
//   PackageBootIntegrityTest::testNoShippedFileReferencesARemovedSymbol     — provider → code
// This file runs the REVERSE direction: row → code, and table → code.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use PHPUnit\Framework\TestCase;

final class CoreTrimClusterDFeatureTest extends TestCase
{
    private const PACKAGE = 'api core admin services support ntdst-core.php';

    /** The six model-lifecycle hooks FR-11 renamed. */
    private const MODEL_HOOKS = [
        'ntdst/model/creating',
        'ntdst/model/created',
        'ntdst/model/updating',
        'ntdst/model/updated',
        'ntdst/model/deleting',
        'ntdst/model/deleted',
    ];

    /**
     * Bare method names a migration row spells WITHOUT a receiver, where a
     * DIFFERENT surviving class declares the same name. A bare name search is
     * receiver-blind — the same imprecision INV-9's script documents for its
     * methods half. Each allowance pins the exact file that may declare it, so
     * the removed member coming back anywhere else is still a failure.
     */
    private const RECEIVER_COLLISIONS = [
        'flush'    => ['services/Logger.php'],        // NTDST_Logger::flush(), not NTDST_Container::flush()
        'metadata' => ['core/ServiceInterface.php'],  // NTDST_Service_Meta::metadata(), not NTDST_RelationField::metadata()
        'toArray'  => ['api/FieldTypes.php'],         // FieldTypes' private static, not NTDST_Mailer::toArray()
    ];

    private static string $repo;

    public static function setUpBeforeClass(): void
    {
        self::$repo = dirname(__DIR__, 2);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. INV-9 — the sweep is silent, and the silence is on stdout
    // ─────────────────────────────────────────────────────────────────────────

    public function testZeroReaderSweepPrintsNothingOnStdoutAndExitsZero(): void
    {
        $run = $this->run_in_repo('bash bin/zero-readers.sh');

        $this->assertSame(
            '',
            trim($run['out']),
            "INV-9's check is `bash bin/zero-readers.sh | wc -l` = 0. stdout carried findings:\n" . $run['out']
        );
        $this->assertSame(0, $run['code'], "bin/zero-readers.sh exited {$run['code']} with empty stdout — stderr:\n" . $run['err']);
    }

    public function testTheAdvisoryHalfDoesNotListAnyRenamedModelHook(): void
    {
        $run = $this->run_in_repo('bash bin/zero-readers.sh');

        foreach (self::MODEL_HOOKS as $hook) {
            $this->assertStringNotContainsString(
                $hook,
                $run['err'],
                "The advisory (stderr) half named {$hook}. The six FR-11 hooks are exempted by CONTENT and documented; "
                . "seeing one on the candidate list means the exemption stopped matching the fired spelling."
            );
        }
    }

    /**
     * The failure mode the check exists to prevent: a sweep that quietly drops
     * a consumer root prints 0 for the wrong reason. Driven through a mirror of
     * the package with one root pointed at a directory that does not exist —
     * the roots are a literal array in the script, so a mirror is the only
     * edit-free way to ask the question.
     */
    public function testTheSweepIsLoudWhenAConsumerRootIsMissing(): void
    {
        $mirror  = $this->make_mirror_with_a_missing_root($missing);
        $run     = $this->run_bash('bash ' . escapeshellarg($mirror . '/bin/zero-readers.sh'), $mirror);

        $this->assertNotSame(0, $run['code'], "A missing consumer root must fail the sweep. stdout:\n{$run['out']}\nstderr:\n{$run['err']}");
        $this->assertStringContainsString(
            $missing,
            $run['out'],
            "A missing consumer root must be a FINDING ON STDOUT naming the root, not a silent skip. stdout was:\n" . $run['out']
        );
        $this->assertStringContainsString('missing-consumer-root', $run['out']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. INV-10 — the four published commands, run as written
    // ─────────────────────────────────────────────────────────────────────────

    public function testInvTenPartOneCountsNoScannerParserOrAutoloaderInTheLoadingFiles(): void
    {
        $run = $this->run_check(<<<'SH'
        grep -c "glob(\|file_get_contents(\|preg_match('/^\\\s*namespace\|spl_autoload_register" \
            core/Bootstrap.php ntdst-core.php
        SH);

        $this->assertSame(
            ['core/Bootstrap.php:0', 'ntdst-core.php:0'],
            $this->lines($run['out']),
            "INV-10 (1) must print 0 for both loading files."
        );
    }

    public function testInvTenPartTwoFindsNoPathDerivedFromAClassName(): void
    {
        $run = $this->run_check($this->inv_ten_part_two('.'));

        $this->assertSame([], $this->lines($run['out']), "INV-10 (2) must be empty — a class name concatenated with a file extension is a guess.");
    }

    /**
     * A check nobody has seen fail is a check nobody knows works. The same
     * regex, over a file that DOES derive a path from a class name.
     */
    public function testInvTenPartTwoBitesOnAPathDerivedFromAClassName(): void
    {
        $dir = sys_get_temp_dir() . '/inv10-bite-' . getmypid();
        @mkdir($dir, 0o777, true);
        file_put_contents($dir . '/Guesser.php', "<?php\nfunction load(\$dir, \$class) {\n    require_once \$dir . '/' . \$class . '.php';\n}\n");

        try {
            $run = $this->run_check($this->inv_ten_part_two(escapeshellarg($dir)));
            $this->assertCount(
                1,
                $this->lines($run['out']),
                "INV-10 (2) did not bite on `require_once \$dir . '/' . \$class . '.php';` — the check passes for the wrong reason. Output:\n" . $run['out']
            );
        } finally {
            @unlink($dir . '/Guesser.php');
            @rmdir($dir);
        }
    }

    public function testInvTenPartThreeShowsExactlyOneReaderOfTheServicesConfigKey(): void
    {
        $run = $this->run_check('grep -rln "\[\'services\'\]" --include=*.php . | grep -vE "vendor|tests|specs"');

        $files = array_map(static fn (string $l): string => ltrim($l, './'), $this->lines($run['out']));
        $this->assertSame(
            ['core/Bootstrap.php'],
            $files,
            "INV-10 (3): the `services` config key must have exactly one reader. A second reader is a second admission test."
        );
    }

    public function testInvTenPartFourGatesTheOneSetOfAVariableClassBehindClassExists(): void
    {
        $run = $this->run_check('grep -rn "ntdst_set(\$class" --include=*.php ' . self::PACKAGE);
        $hits = $this->lines($run['out']);

        $this->assertCount(1, $hits, "INV-10 (4): exactly one ntdst_set() takes a variable class. Hits:\n" . $run['out']);
        $this->assertStringStartsWith('core/Bootstrap.php:', $hits[0], "The one variable-class set() must live at the convergence point.");

        [, $set_line] = explode(':', $hits[0], 3);
        $gate = $this->run_check('grep -n "class_exists(" core/Bootstrap.php');
        $gate_lines = array_map(static fn (string $l): int => (int) strtok($l, ':'), $this->lines($gate['out']));

        $this->assertNotEmpty($gate_lines, 'core/Bootstrap.php declares no class_exists() gate at all.');
        $this->assertLessThan(
            (int) $set_line,
            min($gate_lines),
            "class_exists() must be reached BEFORE the container is handed a class name (INV-10's convergence point)."
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3. README ↔ code — the hook table
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A documented extension point that no longer fires is worse than an
     * undocumented one: it invites a consumer to write a listener that is never
     * called, and nothing says so.
     */
    public function testEveryDocumentedExtensionPointHookIsFiredByShippedCode(): void
    {
        $fired   = $this->fired_hooks();
        $missing = [];

        foreach ($this->documented_extension_point_hooks() as $documented) {
            $stem  = $this->stem($documented);
            $found = false;
            foreach ($fired as $hook) {
                if (str_starts_with($this->stem($hook), $stem) || str_starts_with($stem, $this->stem($hook))) {
                    $found = true;
                    break;
                }
            }
            $found || $missing[] = $documented;
        }

        $this->assertSame([], $missing, "README documents extension points nothing fires: " . implode(', ', $missing));
    }

    /**
     * The independent half of INV-9's hook question, asked without trusting the
     * script's own EXCEPTIONS array: a hook core fires either has a reader in
     * the package or on the fleet, or README's table names who will read it.
     */
    public function testEveryFiredHookIsEitherReadSomewhereOrCarriedByTheTable(): void
    {
        $documented = array_map(fn (string $h): string => $this->stem($h), $this->documented_extension_point_hooks());
        $roots      = self::PACKAGE . ' ' . implode(' ', $this->consumer_roots());
        $orphans    = [];

        foreach ($this->fired_hooks() as $hook) {
            $stem = $this->stem($hook);
            foreach ($documented as $doc) {
                if (str_starts_with($stem, $doc) || str_starts_with($doc, $stem)) {
                    continue 2;
                }
            }
            $run   = $this->run_check('grep -rlF -e ' . escapeshellarg($stem) . " --include=*.php {$roots} 2>/dev/null | grep -vE '(^|/)vendor/|(^|/)tests/'");
            $files = $this->lines($run['out']);
            if (count($files) < 2) {
                $orphans[] = $hook . ' (readers: ' . (implode(', ', $files) ?: 'none') . ')';
            }
        }

        $this->assertSame([], $orphans, "INV-9: a hook with no reader is documented, or it goes. Orphans:\n" . implode("\n", $orphans));
    }

    /**
     * The script greps README.md as a whole, so a name buried in a migration
     * row satisfies its exemption test. "Documented extension point" means the
     * TABLE, which is the only place that names WHO reads it.
     */
    public function testEveryHookExemptedBySweepIsCarriedByTheExtensionPointsTable(): void
    {
        $table   = $this->documented_extension_point_hooks();
        $stems   = array_map(fn (string $h): string => $this->stem($h), $table);
        $missing = [];

        foreach ($this->sweep_exceptions() as $name) {
            if (!str_starts_with($name, 'ntdst/')) {
                continue; // the non-hook exemptions (interface, method, function) are their own rows
            }
            $stem = $this->stem($name);
            foreach ($stems as $doc) {
                if (str_starts_with($stem, $doc) || str_starts_with($doc, $stem)) {
                    continue 2;
                }
            }
            $missing[] = $name;
        }

        $this->assertSame([], $missing, "Exempted in bin/zero-readers.sh but absent from README's `#### Extension points` table: " . implode(', ', $missing));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4. README ↔ code — the migration tables, read backwards
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Every "Was" symbol the migration record names must be gone from the
     * shipped package. A row that still names a live symbol tells an adopter to
     * rewrite working code — the same class of defect T13's own RED found at
     * README:90, from the other side.
     */
    public function testEveryMigrationRowSymbolIsAbsentFromShippedCode(): void
    {
        $survivors = [];

        foreach ($this->migration_was_symbols() as $symbol) {
            $where = $this->still_shipped($symbol);
            if ($where !== []) {
                $survivors[] = $symbol . ' → ' . implode(', ', $where);
            }
        }

        $this->assertSame(
            [],
            $survivors,
            "README's 5.0.0 migration table says these left the package, and the package still ships them:\n" . implode("\n", $survivors)
        );
    }

    public function testTheMigrationSweepReadsEnoughRowsToBeMeaningful(): void
    {
        // Guards the test above against silently degrading into an empty loop
        // when the README section is renamed or the table shape changes.
        $this->assertGreaterThanOrEqual(30, count($this->migration_was_symbols()), 'SC-7 wants ≥ 30 rows; the sweep found fewer symbols than that.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // helpers — shell, README parsing, code queries
    // ─────────────────────────────────────────────────────────────────────────

    /** @return array{out: string, err: string, code: int} */
    private function run_bash(string $command, ?string $cwd = null): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process     = proc_open(['bash', '-c', $command], $descriptors, $pipes, $cwd ?? self::$repo);

        $this->assertIsResource($process, "could not start: {$command}");

        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['out' => (string) $out, 'err' => (string) $err, 'code' => proc_close($process)];
    }

    /** @return array{out: string, err: string, code: int} */
    private function run_in_repo(string $command): array
    {
        return $this->run_bash($command);
    }

    /**
     * A published check is written for a shell prompt, not for a PHP string.
     * It goes to a scratch file verbatim and bash reads it from there, so the
     * quoting in the test is not a re-spelling of the quoting in the document.
     *
     * @return array{out: string, err: string, code: int}
     */
    private function run_check(string $script): array
    {
        $file = tempnam(sys_get_temp_dir(), 'inv-check-') ?: throw new RuntimeException('no temp file');
        file_put_contents($file, "set -uo pipefail\n" . $script . "\n");

        try {
            return $this->run_bash('bash ' . escapeshellarg($file));
        } finally {
            @unlink($file);
        }
    }

    /**
     * INV-10 (2), copied from the document as a NOWDOC — no PHP interpolation
     * touches it. The first draft of this helper used an interpolating heredoc
     * and bash read `$[a-zA-Z_]` as arithmetic expansion, so the check returned
     * nothing on every input. It looked green. That is why the bite test exists.
     */
    private function inv_ten_part_two(string $root): string
    {
        $command = <<<'SH'
        grep -rnE "[Cc]lass[A-Za-z_]* *\. *['\"][^'\"]*\.php|['\"][^'\"]*\.php['\"] *\. *\\\$[a-zA-Z_]*[Cc]lass" \
            --include=*.php {ROOT} | grep -vE "(^|/)vendor/|(^|/)tests/|(^|/)specs/"
        SH;

        return str_replace('{ROOT}', $root, $command);
    }

    /** @return list<string> */
    private function lines(string $out): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== ''));
    }

    private function stem(string $hook): string
    {
        $brace = strpos($hook, '{');

        return $brace === false ? $hook : substr($hook, 0, $brace);
    }

    /** @return list<string> */
    private function consumer_roots(): array
    {
        $script = file_get_contents(self::$repo . '/bin/zero-readers.sh') ?: '';
        preg_match('/CONSUMER_ROOTS=\((.*?)\)/s', $script, $m);
        $roots = $this->lines($m[1] ?? '');

        $this->assertGreaterThanOrEqual(12, count($roots), 'the sweep must search the twelve D4 consumer roots');

        return array_map('escapeshellarg', $roots);
    }

    /** @return list<string> the `name` half of every EXCEPTIONS row */
    private function sweep_exceptions(): array
    {
        $script = file_get_contents(self::$repo . '/bin/zero-readers.sh') ?: '';
        preg_match('/EXCEPTIONS=\((.*?)\n\)/s', $script, $m);

        $names = [];
        foreach ($this->lines($m[1] ?? '') as $row) {
            $row = trim($row, "' ");
            $names[] = strtok($row, '|');
        }

        $this->assertNotEmpty($names, 'could not read the sweep exceptions');

        return $names;
    }

    /** @return list<string> every quoted `ntdst/…` hook the package fires */
    private function fired_hooks(): array
    {
        $run = $this->run_check(
            'grep -rhoE "(do_action|apply_filters)\( *[\"\']ntdst/[^\"\']*" --include=*.php ' . self::PACKAGE
            . ' | sed -E "s/.*[\"\']//" | sort -u'
        );
        $hooks = $this->lines($run['out']);

        $this->assertNotEmpty($hooks, 'no ntdst/ hook found in the package at all');

        return $hooks;
    }

    /** @return list<string> the hook names in README's `#### Extension points` table */
    private function documented_extension_point_hooks(): array
    {
        $hooks = [];
        foreach ($this->readme_section('#### Extension points') as $line) {
            if (!str_starts_with($line, '|') || str_starts_with($line, '|---')) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            $kind  = strtolower(end($cells));
            if ($kind !== 'action' && $kind !== 'filter') {
                continue;
            }
            preg_match_all('/`(ntdst\/[^`]+)`/', $cells[0], $m);
            foreach ($m[1] as $hook) {
                $hooks[] = $hook;
            }
        }

        $this->assertNotEmpty($hooks, "README's `#### Extension points` table has no action/filter row");

        return $hooks;
    }

    /**
     * The first cell of every row of every table whose first header cell is
     * `Was`, inside the core-trim migration section, reduced to the symbol
     * shapes a machine can decide: `name()`, `Class::name()`, `ntdst_*`,
     * `NTDST_*`. Prose in the cell is ignored on purpose.
     *
     * @return list<string>
     */
    private function migration_was_symbols(): array
    {
        $symbols = [];
        $in_was  = false;

        foreach ($this->readme_section('#### Core-trim — what left the package') as $line) {
            if (!str_starts_with($line, '|')) {
                $in_was = false;
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, '|')));
            if (strtolower($cells[0]) === 'was') {
                $in_was = true;
                continue;
            }
            if (!$in_was || str_starts_with($line, '|---')) {
                continue;
            }
            preg_match_all('/`([^`]+)`/', $cells[0], $m);
            foreach ($m[1] as $token) {
                if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*::)?[A-Za-z_][A-Za-z0-9_]*\(\)$/', $token)
                    || preg_match('/^(ntdst_|NTDST_)[A-Za-z0-9_]*$/', $token)) {
                    $symbols[] = $token;
                }
            }
        }

        return array_values(array_unique($symbols));
    }

    /**
     * Where a migration-row symbol is still declared or still spelled in the
     * shipped package, ignoring comment lines.
     *
     * @return list<string>
     */
    private function still_shipped(string $symbol): array
    {
        // a hook, option or class NAME: the literal, quoted for hooks/options
        if (!str_ends_with($symbol, '()')) {
            $pattern = str_starts_with($symbol, 'NTDST_')
                ? '\b' . preg_quote($symbol, '/') . '\b'
                : "['\"]" . preg_quote($symbol, '/') . "['\"]";

            return $this->grep_package('grep -rnE ' . escapeshellarg($pattern) . ' --include=*.php ' . self::PACKAGE);
        }

        $name  = rtrim($symbol, '()');
        $class = null;
        if (str_contains($name, '::')) {
            [$class, $name] = explode('::', $name, 2);
        }

        $declaration = 'function +' . preg_quote($name, '/') . ' *\(';

        if ($class !== null) {
            $file = $this->class_file($class);

            return $file === null
                ? []                                                     // the whole class is gone
                : $this->grep_package('grep -nE ' . escapeshellarg($declaration) . ' ' . escapeshellarg($file));
        }

        $hits = $this->grep_package('grep -rnE ' . escapeshellarg('^ *(public |private |protected |static |final |abstract )*' . $declaration) . ' --include=*.php ' . self::PACKAGE);

        if (isset(self::RECEIVER_COLLISIONS[$name])) {
            $files = array_values(array_unique(array_map(static fn (string $h): string => strtok($h, ':'), $hits)));
            sort($files);
            $allowed = self::RECEIVER_COLLISIONS[$name];
            sort($allowed);

            return $files === $allowed ? [] : $hits;
        }

        return $hits;
    }

    /** @return list<string> matching lines, comment lines dropped */
    private function grep_package(string $command): array
    {
        $run = $this->run_check($command . ' || true');

        return array_values(array_filter(
            $this->lines($run['out']),
            static function (string $line): bool {
                $code = preg_replace('/^[^:]*:[0-9]+: */', '', $line) ?? $line;

                return !preg_match('#^(\*|//|\#|/\*)#', trim($code));
            }
        ));
    }

    private function class_file(string $class): ?string
    {
        foreach ([$class, 'NTDST_' . $class] as $spelling) {
            $run   = $this->run_check('grep -rlE ' . escapeshellarg('^ *(final |abstract )*class ' . preg_quote($spelling, '/') . '\b') . ' --include=*.php ' . self::PACKAGE . ' || true');
            $files = $this->lines($run['out']);
            if ($files !== []) {
                return $files[0];
            }
        }

        return null;
    }

    /** @return list<string> the lines of a README section, heading exclusive, to the next `### ` */
    private function readme_section(string $heading): array
    {
        $lines = explode("\n", (string) file_get_contents(self::$repo . '/README.md'));
        $out   = [];
        $in    = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, $heading)) {
                $in = true;
                continue;
            }
            if ($in && preg_match('/^#{1,4} /', $line) && !str_starts_with($line, $heading)) {
                if (preg_match('/^#{1,3} /', $line) || str_starts_with($line, '#### ')) {
                    break;
                }
            }
            if ($in) {
                $out[] = $line;
            }
        }

        $this->assertNotEmpty($out, "README has no `{$heading}` section");

        return $out;
    }

    /** A mirror of the package with ONE consumer root pointed at a directory that does not exist. */
    private function make_mirror_with_a_missing_root(?string &$missing): string
    {
        $mirror  = sys_get_temp_dir() . '/zero-readers-mirror-' . getmypid();
        $missing = self::$repo . '/NO_SUCH_CONSUMER_ROOT';
        $parent  = dirname(self::$repo);

        $this->run_bash('rm -rf ' . escapeshellarg($mirror) . ' && mkdir -p ' . escapeshellarg($mirror . '/bin')
            . ' && cp -a api core admin services support ntdst-core.php README.md ' . escapeshellarg($mirror));

        $script = (string) file_get_contents(self::$repo . '/bin/zero-readers.sh');
        // the roots are relative to the package root; the mirror sits elsewhere,
        // so they become absolute — and exactly one becomes a root that is not there.
        $script = preg_replace('#^(\s*)\.\./#m', '$1' . $parent . '/', $script) ?? $script;
        $script = str_replace($parent . '/todai-client/web/app/themes/todai-child', $missing, $script);

        file_put_contents($mirror . '/bin/zero-readers.sh', $script);

        $this->assertStringContainsString($missing, (string) file_get_contents($mirror . '/bin/zero-readers.sh'));

        return $mirror;
    }

    protected function tearDown(): void
    {
        $mirror = sys_get_temp_dir() . '/zero-readers-mirror-' . getmypid();
        if (is_dir($mirror)) {
            $this->run_bash('rm -rf ' . escapeshellarg($mirror));
        }
    }
}
