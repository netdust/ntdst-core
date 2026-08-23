<?php // tests/Unit/BootstrapServiceSlugTest.php
// F7 — a declared `metadata()['name']` cannot pin a service slug.
//
// The slug is the user-facing extension key. It named three things when this
// file was written; core-trim T02 leaves it naming exactly ONE — the
// config-override filter `ntdst/service/{slug}/config`, which is the single
// reader that kept the derivation alive (FR-2). Bootstrap's own header said a
// declared name "takes precedence over the derivation entirely" — and then
// conceded that in the real registration flow it did not, because the retired
// enable check derived and CACHED the slug from the class name before anything
// metadata-aware ran. A consumer (todai's services/Ping.php) wrote a five-line
// comment about this surprise instead of a `name`.
//
// What must NOT move while fixing it: getServiceMetadata() defaults `name` to
// a human-readable label derived from the class ("AdminUIService" -> "Admin U
// I"). Honouring THAT as a slug would rename every service on the fleet to the
// retired `admin_u_i` mangling. Only a DECLARED name pins a slug.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/ServiceInterface.php';
require_once __DIR__ . '/../../core/Bootstrap.php';

/** Declares a name that the class-name derivation would never produce. */
final class SlugPinnedService implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return ['name' => 'todai ping', 'description' => 'pins its own slug'];
    }
}

/** Implements the interface but declares no name — derivation still rules. */
final class SlugSilentUIService implements NTDST_Service_Meta
{
    public static function metadata(): array
    {
        return ['description' => 'says nothing about its name'];
    }
}

/** No metadata() at all — the plain derivation path. */
final class SlugPlainUIService
{
}

final class BootstrapServiceSlugTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var list<string> Every filter hook applied during the flow. */
    private array $hooks = [];

    /** @var list<string> Every option name read during the flow. */
    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->hooks = [];
        $this->options = [];

        // The full registration flow runs in the config-filter case below, so
        // the WordPress functions it touches answer here. `add_filter()`,
        // `ntdst_log()`, `ntdst_set()` and `ntdst_get()` are the suite's REAL
        // implementations (tests/bootstrap.php) and are never stubbed.
        Functions\when('is_admin')->justReturn(false);
        Functions\when('do_action')->justReturn(null);
        Functions\when('_doing_it_wrong')->justReturn(null);
        Functions\when('apply_filters')->alias(function ($hook, $value = null) {
            $this->hooks[] = (string) $hook;
            return $value;
        });
        Functions\when('get_option')->alias(function ($name, $default = false) {
            $this->options[] = (string) $name;
            return $default;
        });

        // An earlier file's mount is still on the real add_filter bus; "the
        // declared name pinned THIS hook" is a claim about this run.
        foreach (['_ntdst_test_filters', '_ntdst_test_filters_at'] as $bag) {
            foreach (array_keys($GLOBALS[$bag] ?? []) as $hook) {
                if (str_starts_with((string) $hook, 'ntdst/service/') || str_starts_with((string) $hook, 'ntdst_service_')) {
                    unset($GLOBALS[$bag][$hook]);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Bootstrap without its constructor: the slug question needs no config,
     * no logger and no container. The registration ORDER is what this file
     * reproduces, and that lives in the methods, not the constructor.
     */
    private function bootstrap(): NTDST_Bootstrap
    {
        return (new ReflectionClass(NTDST_Bootstrap::class))->newInstanceWithoutConstructor();
    }

    private function call(NTDST_Bootstrap $boot, string $method, array $args): mixed
    {
        $m = new ReflectionMethod(NTDST_Bootstrap::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($boot, $args);
    }

    /**
     * The real registration order, as registerService() runs it: read the
     * metadata, then resolve the slug for the config override. A slug that
     * depends on WHICH of those ran first is the defect.
     *
     * The middle step used to be the enable check, and it was the whole defect:
     * it resolved the slug with no metadata in hand and CACHED that answer, so
     * every later metadata-aware call was served the class-name derivation.
     * core-trim T02 deletes that check (FR-2), which removes the collision —
     * but not the property, because getServiceMetadata() still runs first and
     * `getServiceSlug()` still caches. So the order stays reproduced here.
     */
    private function slugAfterRegistrationFlow(string $class): string
    {
        $boot = $this->bootstrap();
        $this->call($boot, 'getServiceMetadata', [$class]);

        return (string) $this->call($boot, 'getServiceSlug', [$class]);
    }

    public function testADeclaredNamePinsTheSlug(): void
    {
        $this->assertSame('todai_ping', $this->slugAfterRegistrationFlow(SlugPinnedService::class));
    }

    public function testADeclaredNamePinsTheConfigFilterName(): void
    {
        // The whole point of the slug, and after core-trim T02 the ONLY thing
        // it names: the hook a consumer's override travels on. Declaring a name
        // the hook never hears is a promise the framework does not keep — and
        // it is now a LOUD one, because an override key that matches no
        // registered slug is refused at register().
        //
        // Asserted through the public flow rather than the private helpers the
        // cases above use: the mount is what a consumer can observe, and this
        // one case is about the NAME the site writes its add_filter against.
        $boot = new NTDST_Bootstrap([
            'services' => [
                'core' => [SlugPinnedService::class],
                'overrides' => ['todai_ping' => ['pinged' => true]],
            ],
        ]);
        $boot->register()->bootFeatures();

        $this->assertArrayHasKey(
            'ntdst/service/todai_ping/config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'A declared name pins the config filter: the override must mount on the name the service chose.',
        );
        $this->assertArrayNotHasKey(
            'ntdst_service_todai_ping_config',
            $GLOBALS['_ntdst_test_filters_at'] ?? [],
            'And the retired spelling is not mounted beside it — 5.0.0 ships one name, no shim.',
        );
        $this->assertNotContains(
            'ntdst_service_todai_ping_enabled',
            $this->hooks,
            'The enable filter is gone (FR-2): a declared name no longer buys a DENY hook that fails open.',
        );
        $this->assertSame(
            [],
            $this->options,
            'and no option is read for it either: ' . implode(', ', $this->options),
        );
    }

    public function testTheSlugIsTheSameWhicheverQuestionIsAskedFirst(): void
    {
        // Asked cold, with nothing warmed, the answer must be identical. A
        // slug that depends on call order is the defect itself.
        $cold = $this->bootstrap();

        $this->assertSame(
            $this->slugAfterRegistrationFlow(SlugPinnedService::class),
            (string) $this->call($cold, 'getServiceSlug', [SlugPinnedService::class]),
        );
    }

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function derivedSlugProvider(): array
    {
        return [
            'declares metadata but no name' => [SlugSilentUIService::class, 'slug_silent_ui'],
            'declares no metadata at all' => [SlugPlainUIService::class, 'slug_plain_ui'],
        ];
    }

    /**
     * A service that declares no name keeps the derivation it has today —
     * consecutive capitals held together as one token. getServiceMetadata()
     * defaults `name` to the human-readable "Slug Silent U I", and honouring
     * that as a slug would re-mangle every service on the fleet back to the
     * retired `slug_silent_u_i` spelling.
     *
     * @dataProvider derivedSlugProvider
     */
    public function testAServiceThatDeclaresNoNameKeepsItsDerivedSlug(string $class, string $expected): void
    {
        $this->assertSame($expected, $this->slugAfterRegistrationFlow($class));
    }
}
