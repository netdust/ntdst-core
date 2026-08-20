<?php // tests/Unit/BootstrapServiceSlugTest.php
// F7 — a declared `metadata()['name']` cannot pin a service slug.
//
// The slug is the user-facing extension key: it names the
// `ntdst_service_{slug}_enabled` filter, the `ntdst_service_{slug}_config`
// filter and the `ntdst_service_{slug}` option. Bootstrap's own header says a
// declared name "takes precedence over the derivation entirely" — and then
// concedes that in the real registration flow it does not, because
// isServiceEnabled() derives and CACHES the slug from the class name before
// anything metadata-aware runs. A consumer (todai's services/Ping.php) wrote a
// five-line comment about this surprise instead of a `name`.
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

        Functions\when('apply_filters')->alias(function ($hook, $value = null) {
            $this->hooks[] = (string) $hook;
            return $value;
        });
        Functions\when('get_option')->alias(function ($name, $default = false) {
            $this->options[] = (string) $name;
            return $default;
        });
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
     * metadata, ask whether the service is enabled (which resolves the slug
     * for the filter and the option), then resolve the slug for the config
     * override. A slug that depends on WHICH of those ran first is the defect.
     */
    private function slugAfterRegistrationFlow(string $class): string
    {
        $boot = $this->bootstrap();
        $metadata = $this->call($boot, 'getServiceMetadata', [$class]);
        $this->call($boot, 'isServiceEnabled', [$class, $metadata]);

        return (string) $this->call($boot, 'getServiceSlug', [$class]);
    }

    public function testADeclaredNamePinsTheSlug(): void
    {
        $this->assertSame('todai_ping', $this->slugAfterRegistrationFlow(SlugPinnedService::class));
    }

    public function testADeclaredNamePinsTheEnabledFilterAndItsOption(): void
    {
        // The whole point of the slug: these two names are the site's control
        // surface over the service. Declaring a name that the filter never
        // hears is a promise the framework does not keep.
        $this->slugAfterRegistrationFlow(SlugPinnedService::class);

        $this->assertContains('ntdst_service_todai_ping_enabled', $this->hooks);
        $this->assertContains('ntdst_service_todai_ping', $this->options);
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
