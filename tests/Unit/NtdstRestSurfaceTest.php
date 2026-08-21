<?php // tests/Unit/NtdstRestSurfaceTest.php
// The anonymous surface, made assertable again.
//
// NTDST_Actions gave a site ONE list to check: every anonymous action lived in
// `ntdst/api/public_actions`, and a test could assert on it. Routes scatter
// that decision across registrations. This restores the checkable property
// without restoring the router.
//
// The load-bearing assertion is testAClosurePermissionIsReportedAsOpaque(): a
// closure is indistinguishable from `fn() => true`, so counting it as
// "not public" would let a site's own "nothing is anonymous" test pass over a
// wide-open route. Introspection that cannot see a risk must SAY so.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestSurfaceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('did_action')->justReturn(1);
        Functions\when('add_action')->justReturn(true);
        Functions\when('_doing_it_wrong')->justReturn(null);
        Functions\when('apply_filters')->alias(fn($hook, $value, ...$rest) => $value);

        NTDST_Rest::forgetSurface();
    }

    protected function tearDown(): void
    {
        NTDST_Rest::forgetSurface();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testADeclaredPublicRouteIsInThePublicSurface(): void
    {
        ntdst_rest('surface/v1')->get('/open', static fn() => [], ['permission' => 'public']);

        $public = array_column(NTDST_Rest::publicSurface(), 'route');

        $this->assertContains('/open', $public);
    }

    public function testACapabilityGatedRouteIsNotInThePublicSurface(): void
    {
        ntdst_rest('surface/v1')->get('/gated', static fn() => [], ['permission' => 'manage_options']);

        $this->assertSame([], NTDST_Rest::publicSurface());
        $this->assertContains('/gated', array_column(NTDST_Rest::surface(), 'route'));
    }

    public function testLoggedInIsNotPublic(): void
    {
        ntdst_rest('surface/v1')->get('/members', static fn() => [], ['permission' => 'logged_in']);

        $this->assertSame([], NTDST_Rest::publicSurface());
    }

    /**
     * T1 — the whole point. A closure could be `fn() => true`. It must not be
     * quietly filed as "not public", or a site's own surface test passes over
     * a wide-open route.
     */
    public function testAClosurePermissionIsReportedAsOpaque(): void
    {
        ntdst_rest('surface/v1')->get('/unknowable', static fn() => [], ['permission' => static fn() => true]);

        $this->assertSame(
            [],
            NTDST_Rest::publicSurface(),
            'a closure is not DECLARED public',
        );
        $this->assertContains(
            '/unknowable',
            array_column(NTDST_Rest::opaqueSurface(), 'route'),
            'but it must be reported as unknowable, never silently treated as safe',
        );
    }

    /** T3 — a route the wrapper REFUSED never happened, so it is not surface. */
    public function testARefusedRouteIsNotRecorded(): void
    {
        ntdst_rest('surface/v1')->get('/no-permission', static fn() => []);
        ntdst_rest('surface/v1')->get('/bad-handler', 'no_such_function_at_all', ['permission' => 'public']);

        $this->assertSame([], NTDST_Rest::surface(), 'a refused route is not part of the surface');
    }

    /** Re-registering the same route replaces its entry rather than appending. */
    public function testTheRegistryDoesNotGrowOnReRegistration(): void
    {
        for ($i = 0; $i < 5; $i++) {
            ntdst_rest('surface/v1')->get('/same', static fn() => [], ['permission' => 'public']);
        }

        $this->assertCount(1, NTDST_Rest::surface());
    }
}
