<?php // tests/Unit/BootstrapNamespacePathTest.php
// F5 (surviving half) — Bootstrap resolved a namespaced service's file path by
// asking WHICH THEME IS ACTIVE.
//
// `registerService()` stripped `basename(get_stylesheet_directory())` from the
// front of the namespace-derived path. For a theme consumer whose namespace
// root happens to equal its directory name that works by coincidence. For a
// mu-plugin it is nonsense twice over: the consumer is not a theme, and the
// answer changes when someone switches theme. The other half of F5 —
// `discoverSectorServices()`'s `get_stylesheet_directory() . '/services'`
// fallback — left with the sector system in 4.0.0. This is the half that did
// not.
//
// The code wanted "the namespace may or may not carry a root segment the
// filesystem does not repeat". It used the theme name as a proxy for that.
// Ask the filesystem instead.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Bootstrap.php';

final class BootstrapNamespacePathTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('is_admin')->justReturn(false);
        Functions\when('do_action')->justReturn(null);
        Functions\when('apply_filters')->alias(static fn($hook, $value = null) => $value);
        Functions\when('get_option')->justReturn('1');

        // A mu-plugin laid out the way a consumer lays one out: the namespace
        // carries a vendor root (`acme\`) that the directory tree does not.
        $this->root = sys_get_temp_dir() . '/ntdst-f5-' . getmypid() . '-' . random_int(1000, 9999);
        mkdir($this->root . '/services/Billing', 0777, true);
        file_put_contents(
            $this->root . '/services/Billing/InvoiceService.php',
            "<?php namespace acme\\services\\Billing; class InvoiceService { public static function metadata(): array { return []; } }",
        );
    }

    protected function tearDown(): void
    {
        foreach (['/services/Billing/InvoiceService.php', '/services/Billing', '/services', ''] as $leaf) {
            $path = $this->root . $leaf;
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }

        Monkey\tearDown();
        parent::tearDown();
    }

    private function boot(): NTDST_Bootstrap
    {
        return new NTDST_Bootstrap([
            'services' => [
                'core' => ['acme\services\Billing\InvoiceService'],
                'discovery_paths' => [$this->root . '/services'],
            ],
        ]);
    }

    public function testANamespacedServiceResolvesWithoutAskingWhichThemeIsActive(): void
    {
        // The assertion that matters is the `never()`: the answer must not
        // depend on the theme, so the question must not be asked.
        Functions\expect('get_stylesheet_directory')->never();

        $boot = $this->boot();
        $boot->register();

        $this->assertTrue(
            $boot->hasService('acme\services\Billing\InvoiceService'),
            'A mu-plugin consumer must be able to lay its namespace out without matching a theme directory.',
        );
    }

    public function testTheSameLayoutResolvesWhateverThemeIsActive(): void
    {
        // Switching theme is not a code change. It used to be one.
        Functions\when('get_stylesheet_directory')->justReturn('/var/www/wp-content/themes/twentytwentyfive');

        $boot = $this->boot();
        $boot->register();

        $this->assertTrue($boot->hasService('acme\services\Billing\InvoiceService'));
    }
}
