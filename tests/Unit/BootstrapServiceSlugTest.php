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

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // This file asks the slug question directly now — the end-to-end mount
        // proof it used to carry is the composite feature test's. What is left
        // to answer for is the WordPress surface `getServiceMetadata()` and
        // `getServiceSlug()` touch on the way. `ntdst_log()` is the suite's REAL
        // implementation (tests/bootstrap.php) and is never stubbed.
        Functions\when('is_admin')->justReturn(false);
        Functions\when('do_action')->justReturn(null);
        Functions\when('_doing_it_wrong')->justReturn(null);
        Functions\when('apply_filters')->alias(static fn($hook, $value = null) => $value);
        Functions\when('get_option')->alias(static fn($name, $default = false) => $default);
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

    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function serviceSlugProvider(): array
    {
        return [
            // A DECLARED name pins the slug, and pins it to something the
            // class-name derivation would never produce. todai's services/Ping.php
            // is the real one.
            'declares a name' => [SlugPinnedService::class, 'todai_ping'],

            // No declared name, so the derivation rules — consecutive capitals
            // held together as one token. getServiceMetadata() defaults `name`
            // to the human-readable "Slug Silent U I", and honouring THAT as a
            // slug would re-mangle every service on the fleet back to the retired
            // `slug_silent_u_i` spelling.
            'declares metadata but no name' => [SlugSilentUIService::class, 'slug_silent_ui'],
            'declares no metadata at all' => [SlugPlainUIService::class, 'slug_plain_ui'],
        ];
    }

    /**
     * The slug a class answers to, declared or derived.
     *
     * One provider, asserted against `getServiceSlug()` itself. Three cases used
     * to prove the same claim end to end — declared name mounts its hook, silent
     * service mounts the derived hook, and the composite feature test's whole
     * boot — and three round trips through `register()->bootFeatures()` to read
     * back one string is three ways for the same regression to be reported. The
     * ONE end-to-end mount proof stays where it means most: the composite
     * (BootstrapLoadsNothingByGuessingTest), where a consumer's declared slugs
     * carry real overrides to real services on a realistic config.
     *
     * @dataProvider serviceSlugProvider
     */
    public function testTheSlugIsTheNameDeclaredOrTheOneDerivedFromTheClass(
        string $class,
        string $expected,
    ): void {
        $this->assertSame($expected, $this->slugAfterRegistrationFlow($class));
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
}
