<?php // tests/Unit/ContainerSurfaceTest.php
declare(strict_types=1);
// core-trim T07 — the container registers a binding, resolves it once, and says
// whether it can. Nothing else.
//
// This is the RED contract for spec FR-6 / FR-10 and SC-3. Until this task
// NTDST_Container carried five more public methods beside set/get/has, and each
// was a SECOND way to ask a question the three already answer:
//
//   make()    — resolve WITHOUT the singleton cache. A second resolution path
//               means "the container gave me a Foo" no longer says which Foo,
//               and a service that is a singleton on one call site and a fresh
//               instance on another is a lifecycle nobody declared. Zero
//               shipped readers; `new Foo(...)` is what a caller who wants a
//               fresh object should write.
//   call()    — method injection, with its own `callableReflections` cache.
//               It let any callable be invoked with container-resolved
//               arguments, so a dependency could enter an object AFTER
//               construction, from a call site the constructor never named.
//               Zero shipped readers, and the cache is the only reason the
//               class held reflections it never used for resolution.
//   forget()  — un-register one id. Rebinding through set() already clears the
//               resolved cache for that id (Container.php: `unset($this->
//               resolved[$id])`), so the only thing forget() added was removing
//               a binding at runtime — mutation of the registry after boot,
//               which the file's own conventions block already forbids.
//   flush()   — clear everything. A test-only affordance shipped in production
//               code: a fresh `new NTDST_Container()` is the same thing, costs
//               one line, and cannot be reached from a request.
//   keys()    — the registry, read back as a list. The same shape FR-2 already
//               removed from Bootstrap (getServices / getBootedServices): a
//               read-only copy of a registry is a copy that can be walked and
//               acted on beside the convergence point.
//
// `ntdst_make()` goes with make(), for the same reason and one more: it is the
// GLOBAL front door to the second resolution path, so it is the spelling a
// consumer would reach for first.
//
// WHAT THIS FILE ASSERTS, in order:
//   1. THE SURFACE, by reflection, as an EXACT list (SC-3). An exact list and
//      not five absences: absences pin what left, a list also pins that nothing
//      new arrived under a different name.
//   2. NO `callableReflections` PROPERTY. call() is the only thing that wrote
//      it, so a surviving cache is call() surviving under another name.
//   3. THE FILE'S HELPERS — ntdst_container / ntdst_set / ntdst_get are
//      defined, ntdst_make is not.
//   4. THE KEPT BEHAVIOUR STILL WORKS, and this is the half that makes the
//      five deletions safe: `ntdst_set(Class)` then `ntdst_get(Class)` twice
//      returns the SAME instance, with its constructor dependency autowired.
//      Singleton resolution and autowiring are what the container is for; the
//      trim may not cost them.
//   5. NTDST_RelationField STOPS CLAIMING TO BE A SERVICE (FR-10). It declared
//      `metadata()` and implemented NTDST_Service_Meta, but it is not on any
//      service list — it mounts itself from the bottom of its own file
//      (`add_action('after_setup_theme', …)` @ 20). So the metadata was a
//      claim Bootstrap never read: a class that looks registered, is not, and
//      whose `enabled => true` / `priority => 5` keys would silently do
//      nothing if a consumer ever trusted them.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/ServiceInterface.php';
require_once __DIR__ . '/../../core/Container.php';

/** The autowired dependency. Distinctive names: the global container is process-wide. */
final class ContainerSurfaceProbeDep
{
    public function __construct()
    {
    }
}

/** The registered class. Its one constructor argument must arrive autowired. */
final class ContainerSurfaceProbe
{
    public function __construct(public ContainerSurfaceProbeDep $dep)
    {
    }
}

final class ContainerSurfaceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * 1. THE SURFACE — exact, not a set of absences.
     */
    public function testTheContainersPublicSurfaceIsExactlyConstructSetGetHas(): void
    {
        $methods = array_map(
            static fn(ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(NTDST_Container::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        $this->assertSame(
            ['__construct', 'get', 'has', 'set'],
            $methods,
            'The container registers, resolves once, and answers whether it can. '
                . 'A sixth public method is a second way to ask one of those three.',
        );
    }

    /**
     * 2. NO CALLABLE-REFLECTION CACHE — call() is the only writer it had.
     */
    public function testTheContainerHoldsNoCallableReflectionCache(): void
    {
        $properties = array_map(
            static fn(ReflectionProperty $p): string => $p->getName(),
            (new ReflectionClass(NTDST_Container::class))->getProperties(),
        );

        $this->assertNotContains(
            'callableReflections',
            $properties,
            'callableReflections was call()\'s cache. A surviving cache is method '
                . 'injection surviving under another name.',
        );
    }

    /**
     * 3. THE HELPERS — three front doors, and the fourth is gone.
     */
    public function testTheFileDeclaresThreeHelpersAndNotNtdstMake(): void
    {
        $this->assertTrue(function_exists('ntdst_container'), 'ntdst_container() stays.');
        $this->assertTrue(function_exists('ntdst_set'), 'ntdst_set() stays.');
        $this->assertTrue(function_exists('ntdst_get'), 'ntdst_get() stays.');

        $this->assertFalse(
            function_exists('ntdst_make'),
            'ntdst_make() was the global front door to the second resolution path (make()). '
                . 'A caller who wants a fresh object writes `new Foo(...)`.',
        );
    }

    /**
     * 4. THE KEPT BEHAVIOUR — singleton resolution, with autowiring.
     *
     * This is the assertion the four above are only safe next to: the trim
     * removes ways to ASK, never the answer.
     */
    public function testASetClassResolvesOnceAndArrivesAutowired(): void
    {
        ntdst_set(ContainerSurfaceProbe::class);

        $first = ntdst_get(ContainerSurfaceProbe::class);
        $second = ntdst_get(ContainerSurfaceProbe::class);

        $this->assertInstanceOf(ContainerSurfaceProbe::class, $first);
        $this->assertSame($first, $second, 'ntdst_get() is the singleton path: two calls, one instance.');
        $this->assertInstanceOf(
            ContainerSurfaceProbeDep::class,
            $first->dep,
            'The constructor dependency must arrive autowired — that is what the container is for.',
        );
    }

    /**
     * 5. RELATIONFIELD IS NOT A SERVICE, and stops saying it is (FR-10).
     */
    public function testRelationFieldDoesNotClaimToBeAService(): void
    {
        // The shipped file mounts `add_action(...)` at FILE level and neither the
        // bootstrap nor an autoloader defines add_action — Brain Monkey does,
        // from setUp(). So it is required HERE and not at load time.
        require_once __DIR__ . '/../../admin/RelationField.php';

        $class = new ReflectionClass(NTDST_RelationField::class);

        $this->assertFalse(
            $class->hasMethod('metadata'),
            'RelationField is not on any service list — it mounts itself from the bottom '
                . 'of its own file. metadata() was a claim Bootstrap never read.',
        );
        $this->assertNotContains(
            'NTDST_Service_Meta',
            $class->getInterfaceNames(),
            'A class that looks registered and is not is worse than one that says nothing.',
        );
    }
}
