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

    /**
     * Callbacks hung on a WP hook, keyed by hook then PRIORITY, so a deferred
     * registration can be flushed in the order WordPress would flush it:
     * ['rest_api_init' => [10 => [cb, cb], 11 => [cb]]].
     */
    private array $hooked = [];

    /** Every _doing_it_wrong() call this test provoked: [function, message, version]. */
    private array $wrongs = [];

    /** Every capability current_user_can() was asked about, in order. */
    private array $capsAsked = [];

    /**
     * Drives did_action('rest_api_init') — 0 while declarations are pending, 1
     * from the moment the hook STARTS firing (this is what WordPress does: the
     * counter is incremented before the callbacks run).
     */
    private int $restApiInitDid = 0;

    /**
     * Drives doing_action('rest_api_init') — true only WHILE the callbacks run.
     * Together with $restApiInitDid this gives the three states a consumer can
     * declare from: before the hook (0/false), inside it (1/true), after it
     * (1/false). The middle state is the idiomatic WordPress registration point
     * and is indistinguishable from the last one by did_action() alone.
     */
    private bool $restApiInitDoing = false;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->routeTable       = [];
        $this->hooked           = [];
        $this->wrongs           = [];
        $this->capsAsked        = [];
        $this->restApiInitDid   = 0;
        $this->restApiInitDoing = false;

        // tests/bootstrap.php defines add_filter as a REAL recording function
        // (it must be, for the whole suite), and in WordPress add_action IS
        // add_filter. Anything this file's namespace mounted in an earlier test
        // survives in those globals, so the hook is cleared here — otherwise a
        // stale flush from the previous test would run against this test's
        // route table.
        unset(
            $GLOBALS['_ntdst_test_filters']['rest_api_init'],
            $GLOBALS['_ntdst_test_filters_at']['rest_api_init'],
        );

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
        //
        // The PRIORITY is recorded, because a flush mounted from inside the
        // running hook only reaches WordPress if it is mounted at a priority
        // the iteration has not passed yet.
        Functions\when('add_action')->alias(function ($hook, $cb = null, $priority = 10, $args = 1) {
            $this->hooked[(string) $hook][(int) $priority][] = $cb;
            return true;
        });

        // The whole point of this file's timing cases: BEFORE the hook fires a
        // declaration is pending and ->public() can still mark it. AFTER it has
        // finished, the declaration registered immediately and nothing is
        // pending. did_action() alone CANNOT tell "inside the hook" from
        // "after the hook" — it reads 1 in both — which is why doing_action()
        // is stubbed alongside it.
        Functions\when('did_action')->alias(fn($hook = null) => $this->restApiInitDid);

        Functions\when('doing_action')->alias(function ($hook = null) {
            if ($hook === null || (string) $hook === 'rest_api_init') {
                return $this->restApiInitDoing;
            }

            return false;
        });

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
        unset(
            $GLOBALS['_ntdst_test_filters']['rest_api_init'],
            $GLOBALS['_ntdst_test_filters_at']['rest_api_init'],
        );

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

    /**
     * Every callback mounted on rest_api_init, keyed by priority, read fresh.
     *
     * Two sources are merged because two recorders exist: this file's Brain
     * Monkey add_action stub, and the REAL add_filter that tests/bootstrap.php
     * defines (in WordPress add_action IS add_filter, so a wrapper that mounts
     * through either one must be flushed by this harness).
     *
     * @return array<int, list<callable>> priority => callbacks, in mount order
     */
    private function restApiInitCallbacks(): array
    {
        $byPriority = $this->hooked['rest_api_init'] ?? [];

        foreach (($GLOBALS['_ntdst_test_filters_at']['rest_api_init'] ?? []) as $priority => $callback) {
            $priority = (int) $priority;

            if (in_array($callback, $byPriority[$priority] ?? [], true)) {
                continue; // recorded by both stubs; run it once
            }

            $byPriority[$priority][] = $callback;
        }

        ksort($byPriority);

        return $byPriority;
    }

    /**
     * Fire rest_api_init once, the way WP_Hook::apply_filters() fires it.
     *
     * Three things here are the contract, not convenience:
     *
     * 1. did_action() becomes 1 BEFORE the callbacks run, and doing_action()
     *    is true only WHILE they run. A consumer registering from inside the
     *    hook — the idiomatic place — therefore sees did_action() === 1, which
     *    is the same value it reads long after the hook has finished.
     * 2. The recorded callback list is RE-READ after every callback, so a flush
     *    that a declaration mounts from inside the running hook still runs. This
     *    is real WP_Hook behaviour: `$this->callbacks` is walked live.
     * 3. A callback mounted at a priority the iteration has ALREADY PASSED does
     *    NOT run — WordPress never walks backwards. A wrapper that defers to an
     *    earlier priority is broken, and this harness shows it as an absent route
     *    rather than hiding it.
     */
    private function fireRestApiInit(): void
    {
        $this->restApiInitDid   = 1;
        $this->restApiInitDoing = true;

        $ran             = [];   // "priority:index" of every callback already invoked
        $currentPriority = null; // the priority the iteration has reached

        while (true) {
            $next = null;

            foreach ($this->restApiInitCallbacks() as $priority => $callbacks) {
                if ($currentPriority !== null && $priority < $currentPriority) {
                    continue; // already passed — WordPress will not go back for it
                }

                foreach ($callbacks as $index => $callback) {
                    if (isset($ran[$priority . ':' . $index])) {
                        continue;
                    }

                    $next = [$priority, $index, $callback];
                    break 2;
                }
            }

            if ($next === null) {
                break;
            }

            [$priority, $index, $callback] = $next;

            $ran[$priority . ':' . $index] = true;
            $currentPriority               = $priority;

            $callback(rest_get_server());
        }

        $this->restApiInitDoing = false;
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

        $this->assertSame(1, did_action('rest_api_init'), 'control: the hook has fired.');
        $this->assertFalse(
            (bool) doing_action('rest_api_init'),
            'control: the hook has FINISHED — this is the after state, not the inside-the-hook state.',
        );

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

    // =====================================================================
    // FIX ROUND 1 — the idiomatic registration point
    // (T04 review, Important 1)
    // =====================================================================

    public function testPublicWorksWhenDeclaredInsideARestApiInitCallback(): void
    {
        // WHY: `add_action('rest_api_init', ...)` is where WordPress documents
        // route registration, so it is where consumers will write. Inside that
        // callback did_action('rest_api_init') already reads 1 — the same value
        // it reads an hour after the hook finished — so a wrapper that decides
        // "pending or not" on did_action() alone registers immediately here and
        // then refuses the public() that follows. The consumer's endpoint is
        // then INTERNAL while its author reads an anonymous declaration: a
        // silent wrong-way failure, and the reason this case is a denial case.
        //
        // The two states are separated by doing_action(): inside the hook it is
        // true, after it is false.
        $handler = fn() => ['ok' => true];

        add_action('rest_api_init', function () use ($handler) {
            $this->assertSame(1, did_action('rest_api_init'), 'control: WordPress has already counted the hook.');
            $this->assertTrue((bool) doing_action('rest_api_init'), 'control: the hook is RUNNING.');

            $rest = ntdst_rest('hook/v1');
            $rest->get('/prices', $handler)->public();
            $rest->get('/internal', $handler);
        });

        $this->fireRestApiInit();

        $this->assertSame(
            [],
            $this->wrongs,
            'declaring inside rest_api_init is the documented way — it must not be reported as misuse: '
                . implode(' | ', $this->wrongMessages()),
        );
        $this->assertContains('/hook/v1/internal', $this->routeKeys(), 'control: the sibling declaration registered.');
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/hook/v1/internal'),
            'control: an unnamed GET declared inside the hook keeps the internal default.',
        );
        $this->assertCount(
            1,
            $this->registrationsFor('/hook/v1/prices'),
            'the route reaches register_rest_route() exactly ONCE — no register-then-re-register.',
        );
        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/hook/v1/prices'),
            'public() inside a rest_api_init callback must publish the route it follows.',
        );
    }

    public function testDeclaringInsideRestApiInitStillRefusesAnUnnamedWriteVerb(): void
    {
        // WHY: whatever mechanism makes the hook-time declaration pending must
        // not become a bypass for the write-verb rule (threat model item 4). A
        // deferred POST is still a POST.
        $handler = fn() => ['written' => true];

        add_action('rest_api_init', function () use ($handler) {
            $rest = ntdst_rest('hookw/v1');
            $rest->post('/named', $handler, ['permission' => 'edit_posts']);
            $rest->post('/unnamed', $handler);
        });

        $this->fireRestApiInit();

        $this->assertContains('/hookw/v1/named', $this->routeKeys(), 'control: a named capability registers.');
        $this->assertNotContains(
            '/hookw/v1/unnamed',
            $this->routeKeys(),
            'an unnamed write declared inside rest_api_init is refused exactly as one declared before it.',
        );
        $this->assertCount(1, $this->wrongs, 'the refused write reports once.');
    }

    // =====================================================================
    // FIX ROUND 1 — the pending slot belongs to the DECLARATION
    // (T04 review, Important 2; threat model item 5)
    // =====================================================================

    public function testPublicOnTheCachedFacadeFindsNothingPendingAndRefuses(): void
    {
        // WHY: ntdst_rest('shop/v1') hands back a CACHED object, so two modules
        // that never met share it. If the pending slot lived on that object,
        // module B's public() would publish module A's route — and A's author
        // would never see the line that did it. The pending declaration belongs
        // to what the verb returned; the facade holds nothing, so a public()
        // aimed at it refuses and A stays internal.
        $handler = fn() => ['private' => true];

        ntdst_rest('shop/v1')->get('/a', $handler); // module A, pending
        $returned = ntdst_rest('shop/v1')->public(); // module B, later in the request

        $this->fireRestApiInit();

        $this->assertContains('/shop/v1/a', $this->routeKeys(), "control: module A's route still registers.");
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/shop/v1/a'),
            "another module's public() must NOT publish the route this module declared.",
        );
        $this->assertCount(
            1,
            $this->wrongs,
            'public() on the namespace facade has nothing pending to mark and must report once: '
                . implode(' | ', $this->wrongMessages()),
        );
        $this->assertIsObject($returned, 'a refusing public() is still chainable.');
    }

    public function testAChainedVerbMovesThePendingSlotToTheNewDeclaration(): void
    {
        // WHY: the fluent chain reads as one sentence, so public() at its end
        // must mean the LAST declaration and not the first. (Already asserted in
        // spirit by testPublicMarksOnlyTheMostRecentPendingRoute; kept here as
        // the chain-shaped control for the two cases around it.)
        $handler = fn() => ['ok' => true];

        ntdst_rest('chain/v1')->get('/a', $handler)->get('/b', $handler)->public();

        $this->fireRestApiInit();

        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/chain/v1/b'),
            'public() publishes the declaration it directly follows.',
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/chain/v1/a'),
            'the earlier link in the same chain stays internal.',
        );
        $this->assertSame([], $this->wrongs, 'a chained declaration is not misuse.');
    }

    public function testAHeldDeclarationMarksItsOwnRouteAfterASiblingWasDeclared(): void
    {
        // WHY: this is the case that separates "the declaration owns the
        // pending slot" from "the namespace owns the most recent one". A module
        // that keeps the handle in a variable and publishes it a few lines later
        // — with an unrelated declaration in between, possibly from a different
        // file — must publish ITS OWN route. If the slot is per-namespace, the
        // sibling gets published instead: a route nobody wrote public() next to
        // becomes anonymous. Both halves are asserted, because the dangerous
        // half is the one that is silently OPEN.
        $handler = fn() => ['ok' => true];

        $held = ntdst_rest('hold/v1')->get('/a', $handler);
        ntdst_rest('hold/v1')->get('/c', $handler); // an unrelated later declaration
        $held->public();

        $this->fireRestApiInit();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/hold/v1/c'),
            'the sibling declared in between must NOT be published by a public() aimed at another declaration.',
        );
        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/hold/v1/a'),
            'the held declaration publishes the route IT declared.',
        );
        $this->assertSame([], $this->wrongs, 'marking a declaration that is still pending is not misuse.');
    }

    public function testNamespaceDefaultsStillApplyToRoutesDeclaredThroughTheVerbObject(): void
    {
        // WHY: moving the pending slot off the facade must not cut the
        // declaration off from its namespace. defaults() is declared on the
        // facade; a route declared through the verb — and a route chained off
        // that verb's return — must still inherit it, or a namespace-wide
        // permission silently stops covering the routes it was written for.
        $handler = fn() => ['ok' => true];

        ntdst_rest('d/v1')
            ->defaults(['permission' => 'edit_posts'])
            ->post('/w', $handler)
            ->get('/r', $handler);

        $this->fireRestApiInit();

        $this->assertContains('/d/v1/w', $this->routeKeys(), 'the namespace default names a capability, so the write registers.');
        $this->assertContains('/d/v1/r', $this->routeKeys(), 'the route chained off the verb registers too.');
        $this->assertSame([], $this->wrongs, 'an inherited capability is not a refusal.');

        foreach (['/d/v1/w', '/d/v1/r'] as $key) {
            $callback = $this->permissionCallbackOf($key);

            $this->assertIsCallable($callback, "{$key} inherits a real permission_callback from defaults().");
            $this->assertFalse(
                (bool) $callback(null),
                "{$key} must ASK current_user_can('edit_posts') — the sentinel holds only manage_options.",
            );
        }

        $this->assertSame(
            ['edit_posts', 'edit_posts'],
            $this->capsAsked,
            'both routes inherited the capability the namespace declared.',
        );
    }

    // =====================================================================
    // FIX ROUND 1 — a stated capability is a decision, not a suggestion
    // (T04 review, Important 3)
    // =====================================================================

    public function testPublicCannotDowngradeAnExplicitlyNamedCapability(): void
    {
        // WHY: the two lines contradict each other, and only one direction is
        // safe to guess. Silently taking the LAST word would turn a route whose
        // author named a capability into an anonymous one — the open direction,
        // reached by a stray public() a merge left behind. The contradiction is
        // reported, and the stated capability is what registers.
        $handler = fn() => ['ok' => true];

        $returned = ntdst_rest('cap2/v1')->get('/x', $handler, ['permission' => 'edit_posts'])->public();

        $this->fireRestApiInit();

        $this->assertContains('/cap2/v1/x', $this->routeKeys(), 'the route still registers — the capability stands.');

        $callback = $this->permissionCallbackOf('/cap2/v1/x');

        $this->assertNotSame('__return_true', $callback, 'the stated capability must NOT be downgraded to anonymous.');
        $this->assertIsCallable($callback, 'the capability closure is what registers.');
        $this->assertFalse(
            (bool) $callback(null),
            'the registered gate still DENIES — the sentinel does not hold edit_posts.',
        );
        $this->assertSame(['edit_posts'], $this->capsAsked, 'the declared capability is the one checked.');
        $this->assertCount(
            1,
            $this->wrongs,
            'public() over a stated capability is a contradiction and must be reported exactly once: '
                . implode(' | ', $this->wrongMessages()),
        );
        $this->assertIsObject($returned, 'a refusing public() is still chainable.');
    }
}
