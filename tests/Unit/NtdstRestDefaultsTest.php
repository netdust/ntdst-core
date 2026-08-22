<?php // tests/Unit/NtdstRestDefaultsTest.php
// SPLIT RED — specs/core-shape T04. Written by the independent test-author
// BEFORE api/Rest.php carries the behaviour, and IMMUTABLE from here: the
// implementer greens it without weakening an assertion. Adding a missing
// WordPress function stub to setUp() is fine; relaxing, re-scoping or deleting
// an assertion is an escalation, not an edit.
//
// Contract source: spec.md FR-4 and SC-2; plan.md threat model items 4 and 5.
//
// THE PROPERTY UNDER TEST — the permission DEFAULT of every route in this
// package and its consumers moves from "refused" to "logged in":
//
//   absent / null  → the STRING 'is_user_logged_in'
//   ->public()     → the STRING '__return_true'
//   'public'       → the STRING '__return_true'
//   'logged_in'    → the STRING 'is_user_logged_in'
//   a capability   → a closure that answers current_user_can($cap)
//   a callable     → as given
//
// WHY THE TWO SHORTHANDS MUST REGISTER AS LITERAL STRINGS AND NOT AS CLOSURES:
// `rest_get_server()->get_routes()` is the only place a site can read back what
// it published. A closure there is opaque — `fn() => true` and a real gate have
// the same type — so "is anything on this site anonymous?" stops being a
// question code can answer. The two shorthands therefore arrive at
// register_rest_route() as the strings themselves, and this file asserts that
// literally (`=== 'is_user_logged_in'`, `=== '__return_true'`). A capability
// still registers a closure, because there is no core function that names the
// capability; that closure is DRIVEN here and must defer to current_user_can().
//
// WHY THE WRITE-VERB RULE IS ASSERTED AS ABSENCE AND NEVER AS A 403:
// threat model item 4. On a site with open registration, "logged in" is
// "anyone", so an unnamed POST /purge would be a world-writable endpoint. The
// promise is that such a route is NEVER handed to register_rest_route() — it
// does not exist, rather than existing and denying. A 403 test would pass
// against a registered route, which is the weaker property. A write verb
// (POST, PUT, PATCH, DELETE) registers only when it NAMES a capability or
// hands over a callable of its own; absent, 'logged_in' and 'public' — however
// they arrive, per-route, through defaults() or through ->public() — are all
// refused with exactly one _doing_it_wrong.
//
// WHAT "PENDING" MEANS, AND WHY BOTH ORDERS ARE HERE (threat model item 5):
// register_rest_route() is _doing_it_wrong before rest_api_init and the hook
// never fires again after, so a declaration made before the hook is QUEUED and
// flushed when it fires. ->public() marks that pending declaration. Once the
// registration has actually RUN, there is nothing left to mark: WordPress holds
// the route and the callback cannot be swapped, so ->public() refuses with
// _doing_it_wrong and changes nothing. Both orders are asserted, because the
// dangerous one is silent — a route the author believes is anonymous that is
// internal, or worse, the reverse.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Rest.php';

final class NtdstRestDefaultsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The fake WP route table: '/ns/route' => every arg-array handed to register_rest_route(). */
    private array $routeTable = [];

    /** Callbacks the wrapper hung on a WP hook, so a deferred registration can be flushed. */
    private array $hooked = [];

    /** Every _doing_it_wrong() call this test provoked: [function, message, version]. */
    private array $wrongs = [];

    /** Every capability current_user_can() was asked about, in order. */
    private array $capsAsked = [];

    /** Drives did_action('rest_api_init') — 0 while declarations are pending, 1 after the flush. */
    private bool $restApiInitFired = false;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->routeTable       = [];
        $this->hooked           = [];
        $this->wrongs           = [];
        $this->capsAsked        = [];
        $this->restApiInitFired = false;

        // NTDST_Rest caches wrappers and de-duplicates refusals per PROCESS.
        // Without this reset the second test to use a namespace would inherit
        // the first one's wrapper defaults, and a refusal already reported
        // would be silently swallowed — making a _doing_it_wrong count assert
        // the test ORDER instead of the behaviour.
        $this->resetRestStatics();

        // WP core's registrar. Recording it is how absence becomes observable:
        // a route the wrapper refuses never reaches this stub, so its key never
        // appears in the table.
        Functions\when('register_rest_route')->alias(
            function ($namespace, $route, $args = [], $override = false) {
                $key = '/' . trim((string) $namespace, '/') . '/' . ltrim((string) $route, '/');
                $this->routeTable[$key][] = $args;
                return true;
            },
        );

        Functions\when('rest_get_server')->alias(function () {
            $table = $this->routeTable;
            return new class($table) {
                public function __construct(private array $table) {}
                public function get_routes(): array { return $this->table; }
            };
        });

        // Do NOT define add_action as a plain function — SchedulerTest patches
        // it and Patchwork throws DefinedTooEarly if a plain definition wins the
        // race. Recording through Brain Monkey lets a queued registration be
        // flushed by fireRestApiInit().
        Functions\when('add_action')->alias(function ($hook, $cb = null, $priority = 10, $args = 1) {
            $this->hooked[(string) $hook][] = $cb;
            return true;
        });

        // The whole point of this file's timing cases: BEFORE the flush the hook
        // has not fired, so a declaration is pending and ->public() can still
        // mark it. After the flush it has fired and nothing is pending.
        Functions\when('did_action')->alias(fn($hook) => $this->restApiInitFired ? 1 : 0);

        // Refusals are observable as _doing_it_wrong() calls, counted and read.
        Functions\when('_doing_it_wrong')->alias(function ($function = '', $message = '', $version = '') {
            $this->wrongs[] = [(string) $function, (string) $message, (string) $version];
        });

        // The capability sentinel: only 'manage_options' is held. A permission
        // closure that hardcodes true, or that asks about the wrong capability,
        // fails here.
        Functions\when('current_user_can')->alias(function ($cap) {
            $this->capsAsked[] = (string) $cap;
            return $cap === 'manage_options';
        });

        // Ambient WP functions a thin wrapper may touch. Stubbed generously so
        // the implementer is never blocked by a harness gap.
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_json_encode')->alias(fn($v) => json_encode($v));
        Functions\when('apply_filters')->alias(fn($hook, $value, ...$rest) => $value);
        Functions\when('doing_it_wrong_run')->justReturn(null);
        Functions\when('get_current_user_id')->justReturn(0);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // Harness helpers
    // =====================================================================

    /** Clear the per-process caches so each test starts on a clean surface. */
    private function resetRestStatics(): void
    {
        $reflection = new ReflectionClass(NTDST_Rest::class);

        // Only properties that exist are touched: T05 deletes $surface, and a
        // hard reference to it would turn this file into a tripwire for a task
        // it does not test.
        foreach (['instances' => [], 'reported' => [], 'limits' => [], 'surface' => []] as $name => $empty) {
            if (!$reflection->hasProperty($name)) {
                continue;
            }

            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue(null, $empty);
        }
    }

    /** Fire rest_api_init once, flushing every pending declaration. */
    private function fireRestApiInit(): void
    {
        $queued                 = $this->hooked['rest_api_init'] ?? [];
        $this->hooked['rest_api_init'] = [];
        $this->restApiInitFired = true;

        foreach ($queued as $callback) {
            $callback(rest_get_server());
        }
    }

    /** @return list<array<string, mixed>> every registration WP received for this route key */
    private function registrationsFor(string $key): array
    {
        return $this->routeTable[$key] ?? [];
    }

    /** The permission_callback WordPress was handed for a route, exactly as given. */
    private function permissionCallbackOf(string $key): mixed
    {
        $registrations = $this->registrationsFor($key);

        $this->assertNotEmpty($registrations, "control: {$key} must have been registered.");

        return $registrations[0]['permission_callback'] ?? null;
    }

    private function routeKeys(): array
    {
        return array_keys($this->routeTable);
    }

    /** Messages of every refusal, for a readable failure. */
    private function wrongMessages(): array
    {
        return array_map(static fn(array $w) => $w[1], $this->wrongs);
    }

    // =====================================================================
    // DENIAL — a write verb that names no capability does not exist
    // (FR-4, SC-2, threat model item 4)
    // =====================================================================

    /** @return array<string, array{0: string}> */
    public static function writeVerbProvider(): array
    {
        return [
            'post'   => ['post'],
            'put'    => ['put'],
            'patch'  => ['patch'],
            'delete' => ['delete'],
        ];
    }

    /**
     * @dataProvider writeVerbProvider
     */
    public function testAWriteVerbWithNoCapabilityIsAbsentFromTheRouteTable(string $verb): void
    {
        // WHY: threat model item 4. "Logged in" on a site with open registration
        // is "anyone"; an unnamed write endpoint would be world-writable. The
        // route must never reach register_rest_route() at all — asserted as
        // absence and as a call count of ZERO for that key, never as a 403.
        $rest = ntdst_rest('wv/v1');
        $rest->get('/control', fn() => ['ok' => true]);
        $rest->{$verb}('/unnamed', fn() => ['written' => true]);

        $this->fireRestApiInit();

        $this->assertContains(
            '/wv/v1/control',
            $this->routeKeys(),
            'control: an unnamed GET is internal, not refused — this absence assertion must not pass vacuously.',
        );
        $this->assertNotContains(
            '/wv/v1/unnamed',
            $this->routeKeys(),
            "{$verb}() with no capability must NEVER be handed to register_rest_route().",
        );
        $this->assertCount(
            0,
            $this->registrationsFor('/wv/v1/unnamed'),
            'zero registrations for the refused route — not one that denies.',
        );
        $this->assertCount(
            1,
            $this->wrongs,
            'the refusal is LOUD and fires _doing_it_wrong exactly once: ' . implode(' | ', $this->wrongMessages()),
        );
    }

    public function testLoggedInOnAWriteVerbIsRefused(): void
    {
        // WHY: threat model item 4 names the RESOLVED permission, not the
        // spelling. Writing 'logged_in' out loud reaches the identical posture
        // as omitting it, so it is refused identically. A wrapper that refused
        // only the absent case would leave the same hole one keyword away.
        $rest = ntdst_rest('wv2/v1');
        $rest->post('/control', fn() => [], ['permission' => 'edit_posts']);
        $rest->post('/spelled-out', fn() => [], ['permission' => 'logged_in']);

        $this->fireRestApiInit();

        $this->assertContains('/wv2/v1/control', $this->routeKeys(), 'control: a named capability still registers.');
        $this->assertNotContains(
            '/wv2/v1/spelled-out',
            $this->routeKeys(),
            "'logged_in' resolves to is_user_logged_in — on a write verb that is refused, exactly like omitting it.",
        );
        $this->assertCount(1, $this->wrongs, 'one refusal, reported once.');
    }

    public function testPublicOnAWriteVerbIsRefused(): void
    {
        // WHY: ->public() is the loudest way to say "anyone", and on a write
        // verb it is the threat itself rather than an exception to it. A write
        // route opens only by naming a capability or handing over its own
        // callable — there is no shorthand that publishes a write.
        $rest = ntdst_rest('wv3/v1');
        $rest->get('/control', fn() => [])->public();
        $rest->delete('/wipe', fn() => ['deleted' => true])->public();

        $this->fireRestApiInit();

        $this->assertContains('/wv3/v1/control', $this->routeKeys(), 'control: public() on a GET registers.');
        $this->assertNotContains(
            '/wv3/v1/wipe',
            $this->routeKeys(),
            'public() must not publish a DELETE — a write verb needs a capability or a callable.',
        );
        $this->assertCount(0, $this->registrationsFor('/wv3/v1/wipe'), 'zero registrations for the refused write.');
        $this->assertCount(
            1,
            $this->wrongs,
            'the refused public() write reports once: ' . implode(' | ', $this->wrongMessages()),
        );
    }

    public function testANamespaceDefaultOfPublicCannotOpenAWriteVerb(): void
    {
        // WHY: defaults() is declared once, far from the route that inherits
        // it — the exact distance at which a permission stops being read. A
        // namespace-wide 'public' opens its GETs and must still refuse its
        // writes, so a later ->post() added under that default cannot be
        // published by a line its author never looked at.
        $rest = ntdst_rest('nsdef/v1')->defaults(['permission' => 'public']);
        $rest->get('/read', fn() => ['ok' => true]);
        $rest->post('/write', fn() => ['written' => true]);

        $this->fireRestApiInit();

        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/nsdef/v1/read'),
            "the namespace default publishes the GET as the literal '__return_true'.",
        );
        $this->assertNotContains(
            '/nsdef/v1/write',
            $this->routeKeys(),
            'a namespace default of "public" must NOT publish a write verb.',
        );
        $this->assertCount(1, $this->wrongs, 'the refused write reports once.');
    }

    // =====================================================================
    // The write verbs that DO register
    // =====================================================================

    public function testAWriteVerbWithANamedCapabilityRegisters(): void
    {
        // WHY: the positive control for the whole write-verb rule. Without it
        // every absence above would pass against a wrapper that refuses all
        // writes outright, which is a different (and useless) framework.
        ntdst_rest('wok/v1')->post('/named', fn() => ['written' => true], ['permission' => 'edit_posts']);

        $this->fireRestApiInit();

        $callback = $this->permissionCallbackOf('/wok/v1/named');

        $this->assertIsCallable($callback, 'a named capability registers a real permission_callback.');
        $this->assertSame([], $this->wrongs, 'a write verb naming a capability is not a refusal.');

        $this->assertFalse(
            (bool) $callback(null),
            'the capability closure must ASK current_user_can — the sentinel holds only manage_options.',
        );
        $this->assertContains('edit_posts', $this->capsAsked, 'the declared capability is the one checked.');
    }

    public function testAWriteVerbWithItsOwnCallableRegisters(): void
    {
        // WHY: a callable is the escape hatch for a write that no capability
        // describes (a signed webhook, a token gate). The rule refuses the
        // SHORTHANDS, not authorship — refusing a declared callable would leave
        // consumers with nowhere to put a real gate.
        $gate = static fn($request = null): bool => true;

        ntdst_rest('wcb/v1')->put('/hooked', fn() => ['written' => true], ['permission' => $gate]);

        $this->fireRestApiInit();

        $this->assertContains('/wcb/v1/hooked', $this->routeKeys(), 'a write verb with its own callable registers.');
        $this->assertIsCallable($this->permissionCallbackOf('/wcb/v1/hooked'));
        $this->assertSame([], $this->wrongs, 'no refusal for a declared callable.');
        $this->assertTrue((bool) $this->permissionCallbackOf('/wcb/v1/hooked')(null), 'the declared gate decides.');
    }

    // =====================================================================
    // The default itself — the two literal strings (FR-4, SC-2)
    // =====================================================================

    public function testAnUnnamedGetRegistersWithTheIsUserLoggedInString(): void
    {
        // WHY: SC-2. The default is INTERNAL, and it is readable as such from
        // get_routes(). A closure here would satisfy "it denies anonymous" and
        // still destroy the only introspection a site has over its own surface.
        ntdst_rest('def/v1')->get('/thing', fn() => ['ok' => true]);

        $this->fireRestApiInit();

        $this->assertContains('/def/v1/thing', $this->routeKeys(), 'an unnamed GET registers — it is internal, not refused.');
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/def/v1/thing'),
            "the default permission_callback is the STRING 'is_user_logged_in', not a closure over it.",
        );
        $this->assertSame([], $this->wrongs, 'the new default is silent — an unnamed GET is a supported declaration.');
    }

    public function testLoggedInSpelledOutOnAGetRegistersAsTheSameString(): void
    {
        // WHY: one concept, one registered value. If the shorthand and the
        // default produced different callbacks, a site auditing get_routes()
        // would have two spellings to learn for one posture.
        ntdst_rest('def2/v1')->get('/thing', fn() => [], ['permission' => 'logged_in']);

        $this->fireRestApiInit();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/def2/v1/thing'),
            "'logged_in' registers as the same literal string the default does.",
        );
    }

    public function testPublicOnAGetRegistersWithTheReturnTrueString(): void
    {
        // WHY: SC-2. Anonymous is the one posture that must be impossible to
        // reach by accident and trivial to read back: the literal
        // '__return_true' is what a site's "nothing is anonymous" audit greps.
        ntdst_rest('pub/v1')->get('/open', fn() => ['ok' => true])->public();

        $this->fireRestApiInit();

        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/pub/v1/open'),
            "public() registers the STRING '__return_true' so get_routes() shows the route is anonymous.",
        );
        $this->assertSame([], $this->wrongs, 'public() on a GET is a supported declaration, not a refusal.');
    }

    public function testPublicSpelledAsAnOptionOnAGetRegistersAsTheSameString(): void
    {
        ntdst_rest('pub2/v1')->get('/open', fn() => [], ['permission' => 'public']);

        $this->fireRestApiInit();

        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/pub2/v1/open'),
            "'public' as an option registers the same literal '__return_true' that public() does.",
        );
    }

    public function testACapabilityRegistersAClosureThatDefersToCurrentUserCan(): void
    {
        // WHY: a capability has no core function to name, so this one case IS a
        // closure — and a closure is exactly what cannot be read back, so it is
        // DRIVEN here instead. It must answer current_user_can() with the
        // declared capability and nothing else; a closure returning a hardcoded
        // true would pass an is_callable check and publish the route.
        $rest = ntdst_rest('cap/v1');
        $rest->get('/admin', fn() => ['ok' => true], ['permission' => 'manage_options']);
        $rest->get('/editor', fn() => ['ok' => true], ['permission' => 'edit_posts']);

        $this->fireRestApiInit();

        $held   = $this->permissionCallbackOf('/cap/v1/admin');
        $unheld = $this->permissionCallbackOf('/cap/v1/editor');

        $this->assertIsCallable($held, 'a capability permission registers a callable.');
        $this->assertIsNotString($held, 'a capability is NOT one of the two literal-string shorthands.');

        $this->assertTrue((bool) $held(null), 'the sentinel holds manage_options, so the closure must allow.');
        $this->assertFalse((bool) $unheld(null), 'the sentinel does not hold edit_posts, so that closure must deny.');
        $this->assertSame(
            ['manage_options', 'edit_posts'],
            $this->capsAsked,
            'each closure asks current_user_can about ITS OWN capability, once, in order.',
        );
    }

    public function testShowInIndexIsForwardedToWordPressUntouched(): void
    {
        // WHY: FR-4's last clause. The wrapper owns permission and nothing else
        // — an option WordPress understands must arrive as written, or hiding a
        // route from the index silently stops working.
        ntdst_rest('idx/v1')->get('/hidden', fn() => [], [
            'permission'    => 'manage_options',
            'show_in_index' => false,
        ]);

        $this->fireRestApiInit();

        $args = $this->registrationsFor('/idx/v1/hidden')[0] ?? [];

        $this->assertArrayHasKey('show_in_index', $args, 'show_in_index must reach register_rest_route().');
        $this->assertFalse($args['show_in_index'], 'show_in_index is forwarded untouched.');
    }

    // =====================================================================
    // DENIAL — public() lands on the right route, or on nothing at all
    // (threat model item 5)
    // =====================================================================

    public function testPublicMarksOnlyTheMostRecentPendingRoute(): void
    {
        // WHY: threat model item 5. public() is fluent, so it reads as if it
        // belongs to the whole chain. It must reach exactly ONE declaration —
        // the one immediately before it. A wrapper-wide flag would publish the
        // sibling declared first, which is the silent version of this bug.
        $rest = ntdst_rest('mark/v1');
        $rest->get('/internal', fn() => ['private' => true]);
        $rest->get('/open', fn() => ['ok' => true])->public();
        $rest->get('/also-internal', fn() => ['private' => true]);

        $this->fireRestApiInit();

        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/mark/v1/open'),
            'public() marks the declaration it follows.',
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/mark/v1/internal'),
            'the route declared BEFORE public() must stay internal.',
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/mark/v1/also-internal'),
            'the route declared AFTER public() must stay internal — public() does not latch.',
        );
    }

    public function testPublicDoesNotReachAcrossToAnotherNamespacesPendingRoute(): void
    {
        // WHY: two modules declare in the same request. public() on one
        // namespace must never publish another's pending route.
        ntdst_rest('a/v1')->get('/theirs', fn() => ['private' => true]);
        ntdst_rest('b/v1')->get('/mine', fn() => ['ok' => true])->public();

        $this->fireRestApiInit();

        $this->assertSame('__return_true', $this->permissionCallbackOf('/b/v1/mine'), "the caller's own route opens.");
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/a/v1/theirs'),
            "another namespace's pending route must not be published by this one's public().",
        );
    }

    public function testPublicAfterRestApiInitHasFiredRefusesAndChangesNothing(): void
    {
        // WHY: threat model item 5, the misuse order. Once rest_api_init has
        // fired, the declaration ran and WordPress holds the route — there is
        // nothing pending to mark. public() must say so out loud rather than
        // return $this and let the author believe the route is anonymous.
        $this->fireRestApiInit(); // the hook has already fired: declarations now register immediately

        $rest = ntdst_rest('late/v1');
        $rest->get('/thing', fn() => ['ok' => true]);

        $this->assertCount(
            1,
            $this->registrationsFor('/late/v1/thing'),
            'control: after rest_api_init the declaration registers immediately.',
        );
        $this->assertSame([], $this->wrongs, 'control: registering after the hook is not itself a refusal.');

        $returned = $rest->public();

        $this->assertCount(
            1,
            $this->wrongs,
            'public() with the registration already run must report _doing_it_wrong exactly once.',
        );
        $this->assertCount(
            1,
            $this->registrationsFor('/late/v1/thing'),
            'no second registration — public() must not re-register the route behind WordPress.',
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/late/v1/thing'),
            'the already-registered route keeps the permission it registered with; public() changes NOTHING.',
        );
        $this->assertSame($rest, $returned, 'a refusing public() still returns the wrapper so the chain cannot fatal.');
    }

    public function testPublicWithNothingPendingRefuses(): void
    {
        // WHY: the other half of item 5 — public() called on a wrapper that has
        // declared nothing. Silence here trains the author to believe public()
        // is harmless wherever it lands.
        $rest = ntdst_rest('nopending/v1');

        $returned = $rest->public();

        $this->assertCount(1, $this->wrongs, 'public() with no pending declaration must report _doing_it_wrong once.');
        $this->assertSame([], $this->routeKeys(), 'public() must not conjure a route of its own.');
        $this->assertSame($rest, $returned, 'public() is chainable even when it refuses.');
    }
}
