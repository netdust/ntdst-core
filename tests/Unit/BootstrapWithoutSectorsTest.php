<?php // tests/Unit/BootstrapWithoutSectorsTest.php
// §1b — the sector system leaves core, and Bootstrap must boot without it.
//
// `core/SectorRegistry.php` was 527 lines defining "independent platforms
// (gallery, artist, musician, theater)" with per-sector enable options, tier
// options and discovery paths. That is product domain, not framework, and
// core's own rule is that a feature enters only with a named consumer. There
// were none: verified across the fleet, every surviving reference was either a
// test of core's own class or a workaround for its coupling.
//
// THE EVIDENCE THIS FILE TURNS INTO A TEST. Bootstrap hard-depended on the
// system — a readonly constructor property, and `discoverSectorServices()`
// called UNCONDITIONALLY on every boot — so a site that used no sectors still
// had to satisfy it. One did: bavi's ntdst-coreloader.php declared a fake
// five-method `NTDST_SectorRegistry` under the comment "Bootstrap requires
// ntdst_sectors() but the full sector system is not implemented yet". A site
// had to hand-write a framework class in order to boot. That is the coupling
// stating its own case, and these assertions are what keep it gone.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Bootstrap.php';

final class BootstrapWithoutSectorsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // ntdst_log() is the suite's real null logger (tests/bootstrap.php) —
        // stubbing it here would define it process-wide through Patchwork and
        // break every later test file. See the note there.
        Functions\when('is_admin')->justReturn(false);
        Functions\when('do_action')->justReturn(null);
        Functions\when('apply_filters')->alias(static fn($hook, $value = null) => $value);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testBootstrapIsConstructedWithoutASectorRegistry(): void
    {
        // No site should have to fabricate a framework class to get this far.
        Functions\expect('ntdst_sectors')->never();

        $boot = new NTDST_Bootstrap([]);

        $this->assertInstanceOf(NTDST_Bootstrap::class, $boot);
    }

    public function testRegistrationAsksNoSectorAnything(): void
    {
        Functions\expect('ntdst_sectors')->never();

        $boot = new NTDST_Bootstrap(['services' => ['core' => []]]);
        $boot->register();

        $this->assertSame([], $boot->getServices());
    }

    public function testNoDirectoryIsScannedWhateverTheConfigSays(): void
    {
        // WAS: "a boot with discovery OFF scans no theme directory".
        // `discoverSectorServices()` ran on EVERY boot, and its base path fell
        // back to get_stylesheet_directory() — so a mu-plugin consumer that had
        // deliberately switched auto-discovery off still scanned whatever theme
        // happened to be active. That was the half of F5 the sector removal
        // deleted outright rather than fixed.
        //
        // RE-POINTED for core-trim FR-1 / INV-10. "Off" is no longer the
        // question, because there is no switch: core scans no directory at all.
        // So this asks the harder version — discovery turned ON, a populated
        // `discovery_paths`, a file that matches the retired `*Service.php`
        // glob exactly — and still nothing is read and nothing registers.
        // BootstrapResolvesOnlyLoadedClassesTest owns the full contract; this
        // case keeps the theme question dead where it was first asked.
        Functions\expect('ntdst_sectors')->never();
        Functions\expect('get_stylesheet_directory')->never();

        $GLOBALS['_ntdst_sectors_probe_included'] = [];
        $dir = sys_get_temp_dir() . '/ntdst-sectors-' . getmypid() . '-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents(
            $dir . '/ProbeService.php',
            "<?php namespace NtdstT01Sectors;\n"
                . "\$GLOBALS['_ntdst_sectors_probe_included'][] = __FILE__;\n"
                . "class ProbeService { public static function metadata(): array { return []; } }\n",
        );

        try {
            $boot = new NTDST_Bootstrap([
                'services' => ['auto_discover' => true, 'discovery_paths' => [$dir]],
            ]);
            $boot->register();

            $this->assertSame(
                [],
                $GLOBALS['_ntdst_sectors_probe_included'],
                'Core executed a file out of a configured directory: '
                    . implode(', ', $GLOBALS['_ntdst_sectors_probe_included']),
            );
            $this->assertSame([], $boot->getServices(), 'A directory is not a service list.');
        } finally {
            unlink($dir . '/ProbeService.php');
            rmdir($dir);
        }
    }

    public function testAServiceDeclaringSectorsIsNoLongerFilteredOnThem(): void
    {
        // `sectors` was never part of the documented metadata contract
        // (NTDST_Service_Meta lists name, description, admin_only, enabled,
        // priority). A stale `sectors` key left in a consumer's service must
        // now be inert data, not a silent refusal to load — the old
        // checkSectorRequirements() would have declined this one, because a
        // registry that no longer exists cannot report the sector as enabled.
        Functions\expect('ntdst_sectors')->never();
        Functions\when('get_option')->justReturn('1');
        // ntdst_set() is the REAL container helper (core/Container.php loads in
        // tests/bootstrap.php, before Patchwork, so it cannot be stubbed).
        // Binding a lazy service into the container is harmless here.

        $boot = new NTDST_Bootstrap(['services' => ['core' => [SectorlessLegacyService::class]]]);
        $boot->register();

        $this->assertTrue(
            $boot->hasService(SectorlessLegacyService::class),
            'A leftover `sectors` metadata key must not keep a service from loading.',
        );
    }
}

/** A consumer service still carrying the retired `sectors` metadata key. */
final class SectorlessLegacyService
{
    public static function metadata(): array
    {
        return ['sectors' => ['gallery' => 'premium'], 'priority' => 10];
    }
}
