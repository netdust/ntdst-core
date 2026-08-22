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
//   ->public()     → the STRING '__return_true' — the ONE way to anonymous
//   'public'       → REFUSED: the route does not register at all
//   'logged_in'    → the STRING 'is_user_logged_in'
//   a capability   → a closure that answers current_user_can($cap)
//   a callable     → as given
//
// THE STRING 'public' NO LONGER OPENS ANYTHING (Stefan, 2026-08-22, "drop the
// string"). ->public() is the named exception in D2a; ['permission' => 'public']
// was a SECOND SPELLING of that same decision, and one decision with two doors
// is how a route ends up anonymous without anybody deciding it. The value is
// now unusable, and an unusable permission refuses its route like any other:
// absent from register_rest_route(), one _doing_it_wrong pointing at ->public(),
// one ntdst_log('api')->error(). Refusal beats a silently-denying capability
// check because a route that quietly 403s reads, in production, exactly like a
// route that was never declared. The threat-model property this file pins is
// stronger than the spelling: NO string a consumer can pass may ever resolve to
// '__return_true'. The anonymous marker is settable only by ->public().
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
// hands over a callable of its own; absent, 'logged_in' and ->public() —
// however they arrive, per-route or through defaults() — are all refused with
// exactly one _doing_it_wrong. The string 'public' is refused on every verb,
// read or write, because the VALUE is unusable and not because of the verb.
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

    // The rest_api_init harness — the three hook states, WP_Hook's live
    // iteration, and the statics reset — lives in ONE place now
    // (tests/Support/RestApiInitHarness.php), shared with
    // RestInternalByDefaultTest and NtdstRestCorsTest. It was copied three
    // times, so a fix to one copy fixed a third of the suite.
    use RestApiInitHarness;

    /** The fake WP route table: '/ns/route' => every arg-array handed to register_rest_route(). */
    private array $routeTable = [];

    /** Every _doing_it_wrong() call this test provoked: [function, message, version]. */
    private array $wrongs = [];

    /** Every capability current_user_can() was asked about, in order. */
    private array $capsAsked = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->routeTable = [];
        $this->wrongs     = [];
        $this->capsAsked  = [];

        // Clears the process-wide recorders tests/bootstrap.php defines, resets
        // EVERY static the class declares (by reflection, so a new static is
        // covered the day it is added), and puts the world in the "before the
        // hook" state. Without it the second test to use a namespace would
        // inherit the first one's defaults, and a refusal already reported
        // would be swallowed — making a _doing_it_wrong count assert the test
        // ORDER instead of the behaviour.
        $this->resetRestHarness();

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
        Functions\when('remove_filter')->justReturn(true);
        Functions\when('is_allowed_http_origin')->justReturn('');
        Functions\when('sanitize_url')->returnArg();
        Functions\when('get_http_origin')->justReturn('');
    }

    protected function tearDown(): void
    {
        $this->forgetRecordedHooks();

        unset($GLOBALS['wp_filter']['rest_api_init']);

        Monkey\tearDown();
        parent::tearDown();
    }

    // =====================================================================
    // Harness helpers
    // =====================================================================

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

    public function testANamespaceDefaultOfPublicOpensNothingAtAll(): void
    {
        // WHY: defaults() is declared once, far from the route that inherits
        // it — the exact distance at which a permission stops being read. This
        // case used to allow the READ half: a namespace-wide 'public' opened
        // its GETs and only its writes were refused.
        //
        // AMENDED by the independent test-author under the Cluster 2 gate
        // ruling, and the spec moved first: revision 3 of specs/core-shape
        // (FR-4, committed e0c2018) says defaults() may set a POSTURE and never
        // an OPENING, because the one line that opens a route has to be the one
        // line next to it. So the opening is refused under its own id and
        // dropped, and both routes fall back: the GET to the internal default,
        // the write to nothing at all. Nothing was relaxed — the read is now
        // pinned CLOSED where it was pinned open, and the refusal count went
        // from one to two.
        $rest = ntdst_rest('nsdef/v1')->defaults(['permission' => 'public']);
        $rest->get('/read', fn() => ['ok' => true]);
        $rest->post('/write', fn() => ['written' => true]);

        $this->fireRestApiInit();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/nsdef/v1/read'),
            'the refused opening is DROPPED, so the GET falls back to the internal default.',
        );
        $this->assertNotContains(
            '/nsdef/v1/write',
            $this->routeKeys(),
            'a namespace default of "public" must NOT publish a write verb.',
        );
        $this->assertCount(
            2,
            $this->wrongs,
            'two faults, two reports — the opening default, and the write that named no capability: '
                . implode(' | ', $this->wrongMessages()),
        );
        $this->assertStringContainsString(
            'defaults:opening',
            $this->wrongsText(),
            'one of the two must name the default that tried to open the namespace. Reported: '
                . $this->wrongsText(),
        );
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

    public function testTheStringPublicOnAGetRefusesTheRouteAndPointsAtPublicMethod(): void
    {
        // AMENDED by the independent test-author under Stefan's Class D ruling
        // of 2026-08-22 ("drop the string"), spec FR-4 revision 3 + the ledger
        // entry. This case used to assert the opposite — that the option string
        // registered the same '__return_true' that ->public() does. That WAS
        // the finding: two spellings of one decision, one of them reachable
        // from any array a consumer builds at runtime.
        //
        // Nothing is relaxed here. The route was pinned OPEN and is now pinned
        // ABSENT, which is the closed end of the same axis, and three new
        // assertions come with it: the refusal is loud, it names the one door
        // that still works, and it survives into a production log where
        // _doing_it_wrong() is silent (core's doing_it_wrong_trigger_error
        // filter is false inside a REST request).
        //
        // The sibling is the anti-blast-radius control: one unusable value
        // refuses ITS OWN declaration, never the namespace around it.
        $rest = ntdst_rest('pub2/v1');
        $rest->get('/sibling', fn() => ['ok' => true]);
        $rest->get('/open', fn() => ['ok' => true], ['permission' => 'public']);

        $this->fireRestApiInit();

        $this->assertNotContains(
            '/pub2/v1/open',
            $this->routeKeys(),
            "'public' as an option value must NEVER be handed to register_rest_route() — ->public() is the one door.",
        );
        $this->assertCount(
            0,
            $this->registrationsFor('/pub2/v1/open'),
            'zero registrations for the refused route — not one that registers and then denies.',
        );
        $this->assertCount(
            1,
            $this->wrongs,
            'the refusal is loud and fires _doing_it_wrong exactly once: ' . $this->wrongsText(),
        );
        $this->assertStringContainsString(
            'public()',
            implode(' | ', $this->wrongMessages()),
            'the message must point at the replacement the author is supposed to write. Reported: '
                . implode(' | ', $this->wrongMessages()),
        );
        $this->assertCount(
            1,
            $this->logMessages('api', 'error'),
            '_doing_it_wrong is invisible inside a REST request, so a refusal that DESTROYS a route '
                . 'must also reach the log at error, exactly once.',
        );

        $this->assertContains(
            '/pub2/v1/sibling',
            $this->routeKeys(),
            'control: the sibling route in the same namespace still registers.',
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/pub2/v1/sibling'),
            'and it keeps the internal default — the refusal does not leak onto its neighbours.',
        );
    }

    // =====================================================================
    // CLASS D — the string is gone, and no string can reach the marker
    // (Stefan 2026-08-22 "drop the string"; FR-4 rev 3; ledger threat model:
    //  the only anonymous resolution is a marker ONLY public() can set)
    // =====================================================================

    public function testTheStringPublicOnAWriteVerbIsRefusedExactlyOnce(): void
    {
        // WHY: two rules meet on one line — the value is unusable, and the
        // write names no capability. The author gets told ONE thing, at the
        // value that is actually wrong. Two reports for one declaration teaches
        // a reader to skim the log, and skimming is how the NEXT refusal (the
        // one that matters) gets missed. The outcome is identical to the GET
        // case, which is the point: the refusal is about the VALUE, and a verb
        // cannot make an unusable permission usable.
        $rest = ntdst_rest('pubw/v1');
        $rest->post('/control', fn() => ['written' => true], ['permission' => 'edit_posts']);
        $rest->post('/wipe', fn() => ['deleted' => true], ['permission' => 'public']);

        $this->fireRestApiInit();

        $this->assertContains('/pubw/v1/control', $this->routeKeys(), 'control: a named capability still registers.');
        $this->assertNotContains(
            '/pubw/v1/wipe',
            $this->routeKeys(),
            "'public' on a write verb must not register — not as anonymous, not as anything.",
        );
        $this->assertCount(
            1,
            $this->wrongs,
            'ONE declaration, ONE refusal — not one per rule it breaks: ' . $this->wrongsText(),
        );
        $this->assertStringContainsString(
            'public()',
            implode(' | ', $this->wrongMessages()),
            'and the one report is the one about the unusable value. Reported: '
                . implode(' | ', $this->wrongMessages()),
        );
    }

    /** @return array<string, array{0: string}> spellings a consumer reaches for when 'public' stops working */
    public static function nearMissPublicSpellingProvider(): array
    {
        return [
            'capitalised' => ['Public'],
            'padded'      => [' public '],
        ];
    }

    /**
     * @dataProvider nearMissPublicSpellingProvider
     */
    public function testANearMissSpellingOfPublicIsACapabilityThatDenies(string $written): void
    {
        // WHY: FR-4 revision 3 — ANY unrecognised string is a capability. That
        // rule is what makes dropping 'public' safe to reason about: there is
        // exactly one recognised opening (->public()), one recognised posture
        // ('logged_in'), one refused word ('public'), and everything else is a
        // capability that current_user_can() answers false for.
        //
        // The failure this forbids is normalisation. A wrapper that trims or
        // lower-cases before matching hands ' public ' the meaning of 'public'
        // — and if the string ever comes back, so does the second door. Worse,
        // silent normalisation makes a capability slug with an accidental space
        // mean something entirely different from what is written. What is
        // written is what is asked.
        ntdst_rest('nearmiss/v1')->get('/probe', fn() => ['ok' => true], ['permission' => $written]);

        $this->fireRestApiInit();

        $callback = $this->permissionCallbackOf('/nearmiss/v1/probe');

        $this->assertNotSame(
            '__return_true',
            $callback,
            "'{$written}' resolved to the anonymous marker — a near miss must never open a route.",
        );
        $this->assertIsCallable($callback, "'{$written}' is a string, so it is a capability, so it is a gate.");
        $this->assertFalse(
            (bool) $callback(null),
            "'{$written}' admitted the caller — the sentinel holds only manage_options.",
        );
        $this->assertContains(
            $written,
            $this->capsAsked,
            "the capability is asked EXACTLY as written — no trim, no case folding. Asked: "
                . implode(', ', $this->capsAsked),
        );
    }

    /** @return array<string, array{0: string}> strings a consumer might hope resolve to "anyone" */
    public static function anonymousMarkerCandidateProvider(): array
    {
        return [
            'the dropped word'         => ['public'],
            'the resolved function'    => ['__return_true'],
            'a guessed marker'         => ['__ntdst_public'],
            'a namespaced marker'      => ['ntdst:public'],
            'a shouted marker'         => ['__NTDST_PUBLIC__'],
            'the word with the arrow'  => ['->public()'],
        ];
    }

    /**
     * @dataProvider anonymousMarkerCandidateProvider
     */
    public function testNoStringAConsumerCanPassEverResolvesToTheAnonymousMarker(string $candidate): void
    {
        // WHY: this is the Class D threat model itself, and it outlives the
        // spelling. Dropping 'public' only helps if the thing it used to mean
        // cannot be NAMED from outside. The anonymous marker must be settable
        // by exactly one gesture — ->public(), on a declaration, in the file
        // that publishes the route — and never by a value that arrives inside
        // an options array. Options arrays get built from config, from
        // constants, from merges, from a variable that came from somewhere
        // else; a marker reachable that way is a marker an attacker or an
        // accident can set.
        //
        // Two outcomes are legal per candidate, and the case accepts either:
        // the declaration is REFUSED (absent, reported), or it registers a gate
        // that DENIES. The single illegal outcome is anonymity. Whatever the
        // implementation picks as its internal marker, this provider must keep
        // failing to reach it.
        ntdst_rest('marker/v1')->get('/probe', fn() => ['ok' => true], ['permission' => $candidate]);

        $this->fireRestApiInit();

        $registrations = $this->registrationsFor('/marker/v1/probe');

        if ($registrations === []) {
            $this->assertNotSame(
                [],
                $this->wrongs,
                "'{$candidate}' was dropped in silence — an unusable permission refuses out loud.",
            );

            return;
        }

        $callback = $registrations[0]['permission_callback'] ?? null;

        $this->assertNotSame(
            '__return_true',
            $callback,
            "'{$candidate}' reached the anonymous marker from an options array. "
                . 'Only ->public() may open a route (FR-4 rev 3, Class D threat model).',
        );
        $this->assertIsCallable($callback, "'{$candidate}' registered something that is not even a gate.");
        $this->assertFalse(
            (bool) $callback(null),
            "'{$candidate}' admitted an anonymous caller — no option value may open a route.",
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
    // =====================================================================
    // FIX WAVE 1 — B3: the flush MARKS the declaration, so a late public()
    // has something to refuse (reviewer L-1/L-4, threat model item 5)
    // =====================================================================

    public function testPublicOnAFlushedDeclarationRefusesFromInsideTheRunningHook(): void
    {
        // WHY: the held handle is the dangerous shape. A module keeps what a
        // verb returned, and publishes it from a later callback — by then the
        // flush has already handed the route to WordPress, which holds the
        // callback and will not swap it. If the declaration does not REMEMBER
        // that it registered, public() finds a pending slot that is still set,
        // marks it, and returns quietly: the author reads an anonymous
        // declaration and the site serves an internal route. Silence in the
        // open direction is the one failure this cluster exists to prevent.
        $handler = fn() => ['ok' => true];

        $held = ntdst_rest('flushed/v1')->get('/thing', $handler);

        // PHP_INT_MAX is where a declaration flushes, and this callback is
        // mounted after that one, so it runs AFTER the route has registered
        // while doing_action('rest_api_init') is still true.
        add_action('rest_api_init', static function () use ($held): void {
            $held->public();
        }, PHP_INT_MAX);

        $this->fireRestApiInit();

        $this->assertCount(
            1,
            $this->registrationsFor('/flushed/v1/thing'),
            'the route registered once — public() must not re-register it behind WordPress.',
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/flushed/v1/thing'),
            'a public() that arrives after the flush changes NOTHING.',
        );
        $this->assertStringContainsString(
            'public:already-registered',
            $this->wrongsText(),
            'the refusal must name its own cause: the declaration had already registered. Reported: '
                . $this->wrongsText(),
        );
    }

    public function testPublicOnAFlushedDeclarationRefusesAfterTheHookHasFinished(): void
    {
        // WHY: the same held handle, one state later. did_action() reads 1 in
        // both states, so a wrapper that only checks the hook counter cannot
        // tell them apart — and the declaration's own "I registered" flag is
        // what makes both refuse for the same stated reason.
        $handler = fn() => ['ok' => true];

        $held = ntdst_rest('flushed2/v1')->get('/thing', $handler);

        $this->fireRestApiInit();

        $returned = $held->public();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/flushed2/v1/thing'),
            'the already-registered route keeps the permission it registered with.',
        );
        $this->assertStringContainsString(
            'public:already-registered',
            $this->wrongsText(),
            'reported: ' . $this->wrongsText(),
        );
        $this->assertIsObject($returned, 'a refusing public() is still chainable.');
    }

    // =====================================================================
    // FIX WAVE 1 — B4: a string permission is a CAPABILITY, never a callable
    // (reviewer I-1, sentinel I1 — the fail-OPEN regression)
    // =====================================================================

    public function testAStringPermissionThatNamesAGlobalFunctionIsStillAskedAsACapability(): void
    {
        // WHY: capability slugs and function names are the same bytes.
        // WordPress itself defines functions called edit_post(), delete_plugins()
        // and activate_plugins() — every one of them a capability slug too, and
        // every one of them loaded on an admin request. A wrapper that asks
        // is_callable() first therefore runs somebody's ADMIN FUNCTION as an
        // authorization check, with whatever side effects it has, and takes its
        // return value as the answer. A string is a capability. Full stop.
        $GLOBALS['_ntdst_probe_cap_calls'] = 0;

        ntdst_rest('capstr/v1')->get('/probe', fn() => ['ok' => true], [
            'permission' => 'ntdst_probe_cap_x',
        ]);

        $this->fireRestApiInit();

        $callback = $this->permissionCallbackOf('/capstr/v1/probe');
        $this->assertIsCallable($callback, 'a string permission still produces a gate.');

        $this->assertFalse(
            (bool) $callback(null),
            'the sentinel holds only manage_options, so the capability check must DENY.',
        );
        $this->assertContains(
            'ntdst_probe_cap_x',
            $this->capsAsked,
            'the string must reach current_user_can() as the capability it is.',
        );
        $this->assertSame(
            0,
            $GLOBALS['_ntdst_probe_cap_calls'],
            'the framework called a global function that merely SHARES the capability name.',
        );
    }

    public function testAStringNamingATrueReturningWordPressFunctionCannotAdmitAnyone(): void
    {
        // WHY: sentinel I1, the concrete exploit. wp_is_json_request() is a real
        // WordPress function, it is callable, and it is TRUE for every REST
        // client alive. Under an is_callable-first reading,
        // ['permission' => 'wp_is_json_request'] is "allow every caller" — a
        // world-writable POST that reads, in the consumer's file, like a gate.
        //
        // Read as a capability it denies everyone, which is where a string that
        // nobody can prove the meaning of has to land.
        $handler = fn() => ['written' => true];

        ntdst_rest('jsonstr/v1')->post('/write', $handler, ['permission' => 'wp_is_json_request']);

        $this->fireRestApiInit();

        $callback = $this->registrationsFor('/jsonstr/v1/write')[0]['permission_callback'] ?? null;

        $this->assertNotSame(
            'wp_is_json_request',
            $callback,
            'the function name itself must never become the permission_callback.',
        );
        $this->assertIsCallable($callback, 'a capability string registers a gate.');
        $this->assertFalse(
            (bool) $callback(null),
            'a POST guarded by "wp_is_json_request" admitted the caller — every REST client passes that function.',
        );
        $this->assertContains(
            'wp_is_json_request',
            $this->capsAsked,
            'the string must be put to current_user_can(), not to PHP.',
        );
        $this->assertSame(
            0,
            $GLOBALS['_ntdst_test_json_request_calls'] ?? -1,
            'the framework CALLED wp_is_json_request() — that is the fail-open.',
        );
    }

    /** @return array<string, array{0: string}> the two shorthand targets, written out as raw strings */
    public static function rawShorthandFunctionNameProvider(): array
    {
        return [
            '__return_true written raw'     => ['__return_true'],
            'is_user_logged_in written raw' => ['is_user_logged_in'],
        ];
    }

    /**
     * @dataProvider rawShorthandFunctionNameProvider
     */
    public function testTheShorthandFunctionNamesWrittenRawAreCapabilitiesThatDeny(string $written): void
    {
        // WHY: 'public' and 'logged_in' are the shorthands; the FUNCTIONS they
        // resolve to are not a second spelling of them. A consumer who writes
        // the function name out reaches the capability rule like any other
        // string, and current_user_can('__return_true') is false for everyone.
        // The alternative — honouring the raw name — makes '__return_true' a
        // second, undocumented way to publish a route anonymously.
        ntdst_rest('raw/v1')->get('/thing', fn() => ['ok' => true], ['permission' => $written]);

        $this->fireRestApiInit();

        $callback = $this->permissionCallbackOf('/raw/v1/thing');

        $this->assertIsNotString($callback, "'{$written}' must not be handed to WordPress as itself.");
        $this->assertFalse(
            (bool) $callback(null),
            "'{$written}' as a capability denies everyone — the sentinel holds only manage_options.",
        );
        $this->assertContains($written, $this->capsAsked, 'the raw name reached current_user_can().');
    }

    // =====================================================================
    // FIX WAVE 1 — B5/B13: defaults are frozen at verb time, and may set a
    // posture but never an OPENING (reviewer I-2, sentinel C3)
    // =====================================================================

    public function testANamespaceDefaultDeclaredAfterARouteDoesNotReachThatRoute(): void
    {
        // WHY: two modules share a namespace, and the second one's defaults()
        // runs later in the same request. If defaults are merged at FLUSH time,
        // module B retroactively rewrites the permission of a route module A
        // already declared — across files, with nothing at either call site to
        // read. The declaration carries the defaults as they stood when the
        // verb ran.
        $handler = fn() => ['ok' => true];

        // The default is a CAPABILITY, not an opening: a default may narrow and
        // never widen (FR-4, spec revision 3), so 'public' would be refused and
        // dropped here and the case would prove nothing about snapshotting.
        // 'logged_in' is indistinguishable from the absent default, which is
        // the other way to make this control vacuous. A capability is visibly
        // different from both.
        $rest = ntdst_rest('snap/v1');
        $rest->get('/before', $handler);
        $rest->defaults(['permission' => 'edit_posts']);
        $rest->get('/after', $handler);

        $this->fireRestApiInit();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/snap/v1/before'),
            'a default declared AFTER a route must not reach back and change it.',
        );

        $inherited = $this->permissionCallbackOf('/snap/v1/after');

        $this->assertIsCallable($inherited, 'control: the default does apply to the route declared after it.');
        $this->assertFalse(
            (bool) $inherited(null),
            "control: the inherited gate ASKS current_user_can('edit_posts') — the sentinel does not hold it.",
        );
        $this->assertSame(['edit_posts'], $this->capsAsked, 'and it asks about the capability the namespace named.');
        $this->assertSame([], $this->wrongs, 'a capability default is a posture — nothing to refuse.');
    }

    public function testANamespaceDefaultOfPublicIsRefusedAndDropped(): void
    {
        // WHY: sentinel C3. defaults() is the most distant place a permission
        // can be written from — one line in a bootstrap file, inherited by
        // every route in the namespace, including routes added months later by
        // someone who never read it. A default may narrow ('logged_in', a
        // capability); it may not OPEN. The other defaults survive, because
        // taking show_in_index away over an unrelated key would punish the
        // wrong line.
        $rest = ntdst_rest('defopen/v1')->defaults([
            'permission'    => 'public',
            'show_in_index' => false,
        ]);
        $rest->get('/read', fn() => ['ok' => true]);

        $this->fireRestApiInit();

        $this->assertStringContainsString(
            'defaults:opening',
            $this->wrongsText(),
            'a namespace default that opens must refuse under its own id. Reported: ' . $this->wrongsText(),
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/defopen/v1/read'),
            'the refused permission default is DROPPED — the route falls back to internal.',
        );
        $this->assertFalse(
            $this->registrationsFor('/defopen/v1/read')[0]['show_in_index'] ?? null,
            'the other defaults are kept: only the opening key is dropped.',
        );
    }

    public function testACallableNamespaceDefaultIsRefusedAndCannotSmuggleAnUnnamedWriteThrough(): void
    {
        // WHY: sentinel C3's second half, and the one that reaches the write
        // gate. A callable default satisfies "the route named a callable of its
        // own", so an unnamed POST anywhere in the namespace would register and
        // be answered by a closure its author never saw. The default is refused
        // before it can vouch for anything.
        $handler = fn() => ['written' => true];

        $rest = ntdst_rest('defcb/v1')->defaults(['permission' => static fn() => true]);
        $rest->get('/read', $handler);
        $rest->post('/write', $handler);

        $this->fireRestApiInit();

        $this->assertNotContains(
            '/defcb/v1/write',
            $this->routeKeys(),
            'an unnamed POST under a callable default must be ABSENT, not anonymous.',
        );
        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/defcb/v1/read'),
            'the read falls back to internal — the callable default vouches for nothing.',
        );
        $this->assertStringContainsString(
            'defaults:opening',
            $this->wrongsText(),
            'reported: ' . $this->wrongsText(),
        );
    }

    public function testANamespaceDefaultOfLoggedInStillApplies(): void
    {
        // WHY: the control that keeps the rule above from becoming "defaults()
        // may not carry a permission at all". A POSTURE is exactly what a
        // namespace default is for; only the opening is refused.
        $handler = fn() => ['ok' => true];

        $rest = ntdst_rest('defposture/v1')->defaults(['permission' => 'logged_in']);
        $rest->get('/read', $handler);
        $rest->post('/write', $handler);

        $this->fireRestApiInit();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/defposture/v1/read'),
            "'logged_in' is a posture, and a namespace may declare it.",
        );
        $this->assertNotContains(
            '/defposture/v1/write',
            $this->routeKeys(),
            'the resolved posture is still is_user_logged_in, so the write is refused (threat model item 4).',
        );
    }

    public function testPublicStillBeatsAnInheritedPermissionDefault(): void
    {
        // WHY: the inherited default is the distant line; ->public() is the
        // local one, written next to the route it publishes. The local
        // declaration wins — and this is the ONLY direction that stays true,
        // because an explicitly stated per-route capability is never
        // downgraded (testPublicCannotDowngradeAnExplicitlyNamedCapability).
        $handler = fn() => ['ok' => true];

        ntdst_rest('defbeat/v1')
            ->defaults(['permission' => 'logged_in'])
            ->get('/open', $handler)
            ->public();

        $this->fireRestApiInit();

        $this->assertSame(
            '__return_true',
            $this->permissionCallbackOf('/defbeat/v1/open'),
            'public() beats a permission INHERITED from the namespace.',
        );
        $this->assertSame([], $this->wrongs, 'beating an inherited default is not a contradiction.');
    }

    // =====================================================================
    // FIX WAVE 1 — B6: the PHP_INT_MAX collision is loud (reviewer L-3)
    // =====================================================================

    public function testADeclarationMadeWhileTheHookRunsAtPhpIntMaxIsRefusedLoudly(): void
    {
        // WHY: a declaration made from inside rest_api_init is flushed at
        // PHP_INT_MAX, and WP_Hook copies the callback list for the priority it
        // is CURRENTLY walking. A consumer that hooks at PHP_INT_MAX itself
        // therefore declares into a flush that will never run: the route
        // vanishes, no error, nothing in the log, and the endpoint 404s in
        // production while every line of the consumer's code looks right. A
        // route that cannot be registered must SAY so.
        $GLOBALS['wp_filter']['rest_api_init'] = new class {
            public function current_priority(): int
            {
                return PHP_INT_MAX;
            }
        };

        $this->declareInHookState('inside', function (): void {
            ntdst_rest('collide/v1')->get('/thing', fn() => ['ok' => true]);
        });

        $this->assertNotSame(
            [],
            $this->wrongs,
            'a declaration that cannot be flushed must be reported, not silently dropped.',
        );
        $this->assertMatchesRegularExpression(
            '/earlier/i',
            $this->wrongsText(),
            'the message must tell the consumer what to do — hook earlier. Reported: ' . $this->wrongsText(),
        );
    }

    public function testADeclarationMadeAtAnOrdinaryPriorityIsNotReportedAsACollision(): void
    {
        // WHY: the control. Without it the case above passes against a wrapper
        // that shouts at every hook-time declaration, which would make the
        // documented registration point unusable.
        $GLOBALS['wp_filter']['rest_api_init'] = new class {
            public function current_priority(): int
            {
                return 10;
            }
        };

        $this->declareInHookState('inside', function (): void {
            ntdst_rest('nocollide/v1')->get('/thing', fn() => ['ok' => true]);
        });

        $this->assertContains('/nocollide/v1/thing', $this->routeKeys(), 'the route registers as usual.');
        $this->assertSame([], $this->wrongs, 'declaring inside the hook at an ordinary priority is not misuse.');
    }

    // =====================================================================
    // FIX WAVE 1 — B8: one refusal per REASON, not one per route
    // =====================================================================

    public function testTheSameRouteRefusedForTwoDifferentReasonsIsReportedTwice(): void
    {
        // WHY: the de-duplication exists so a refusal that repeats on every
        // request does not flood the log. Keyed on the route alone it also
        // swallows the SECOND, different fault on that route — the author fixes
        // the one they were told about, and the route still does not exist,
        // now with no message at all.
        $handler = fn() => ['written' => true];

        $rest = ntdst_rest('twofault/v1');
        $rest->post('/x', $handler);                                              // no capability on a write
        $rest->post('/x', $handler, ['permission' => 'edit_posts', 'corss' => []]); // an option that does not exist

        $this->fireRestApiInit();

        $this->assertNotContains('/twofault/v1/x', $this->routeKeys(), 'control: neither declaration registered.');
        $this->assertCount(
            2,
            $this->wrongs,
            'two different faults on one route are two reports: ' . $this->wrongsText(),
        );
        $this->assertNotSame(
            $this->wrongs[0][1] ?? '',
            $this->wrongs[1][1] ?? '',
            'and they say different things — the second must not be a copy of the first.',
        );
    }

    // =====================================================================
    // FIX WAVE 1 — B9: combined verbs read as the set of verbs they are
    // =====================================================================

    public function testACombinedVerbRouteWithNoCapabilityIsRefusedWholeSale(): void
    {
        // WHY: 'GET,POST' is one route with a write in it. Registering the read
        // half and dropping the write half would publish a route whose declared
        // methods no longer match what the author wrote, and the POST would
        // still be reachable through the same handler entry. The whole
        // declaration goes.
        ntdst_rest('combo/v1')->route('/x', 'GET,POST', fn() => ['ok' => true]);

        $this->fireRestApiInit();

        $this->assertNotContains(
            '/combo/v1/x',
            $this->routeKeys(),
            'a combined declaration containing a write verb and naming no capability must not register at all.',
        );
        $this->assertCount(1, $this->wrongs, 'reported once: ' . $this->wrongsText());
    }

    public function testACombinedVerbRouteWithACapabilityRegistersWithNormalizedMethods(): void
    {
        // WHY: the positive control, and the parsing rule. Whitespace and case
        // are the consumer's business; what reaches WordPress is the canonical
        // set, because WP matches methods by exact string.
        ntdst_rest('combo2/v1')->route('/x', 'get , post', fn() => ['ok' => true], [
            'permission' => 'edit_posts',
        ]);

        $this->fireRestApiInit();

        $this->assertContains('/combo2/v1/x', $this->routeKeys(), 'a named capability registers the combined route.');
        $this->assertSame(
            ['GET', 'POST'],
            $this->methodsOf($this->registrationsFor('/combo2/v1/x')[0] ?? []),
            'the verbs reach WordPress upper-cased and trimmed.',
        );
    }

    // =====================================================================
    // FIX WAVE 1 — B14: a READ ALLOW-LIST, not a write deny-list
    // (sentinel I2)
    // =====================================================================

    /** @return array<string, array{0: string}> the three verbs that may default to a posture */
    public static function readVerbProvider(): array
    {
        return [
            'GET'     => ['GET'],
            'HEAD'    => ['HEAD'],
            'OPTIONS' => ['OPTIONS'],
        ];
    }

    /**
     * @dataProvider readVerbProvider
     */
    public function testAnUnnamedVerbOnTheReadAllowListRegistersAsInternal(string $verb): void
    {
        ntdst_rest('read/v1')->route('/thing', $verb, fn() => ['ok' => true]);

        $this->fireRestApiInit();

        $this->assertSame(
            'is_user_logged_in',
            $this->permissionCallbackOf('/read/v1/thing'),
            "{$verb} is a read — unnamed, it is internal, exactly like GET.",
        );
        $this->assertSame([], $this->wrongs, "{$verb} without a capability is a supported declaration.");
    }

    /** @return array<string, array{0: string}> verbs that are NOT on the read allow-list */
    public static function nonReadVerbProvider(): array
    {
        return [
            'PURGE'  => ['PURGE'],
            'LINK'   => ['LINK'],
            'SEARCH' => ['SEARCH'],
        ];
    }

    /**
     * @dataProvider nonReadVerbProvider
     */
    public function testAnUnnamedVerbOutsideTheReadAllowListIsAbsentAndLoud(string $verb): void
    {
        // WHY: sentinel I2. A deny-list of POST/PUT/PATCH/DELETE reads any
        // OTHER verb as safe, and a custom verb is exactly where a destructive
        // action hides: PURGE empties a cache, and a proxy or a plugin will
        // route it. The rule is an allow-list of the three verbs that cannot
        // change state, and everything else must name a capability.
        $rest = ntdst_rest('nonread/v1');
        $rest->get('/control', fn() => ['ok' => true]);
        $rest->route('/thing', $verb, fn() => ['done' => true]);

        $this->fireRestApiInit();

        $this->assertContains('/nonread/v1/control', $this->routeKeys(), 'control: the read still registers.');
        $this->assertNotContains(
            '/nonread/v1/thing',
            $this->routeKeys(),
            "{$verb} names no capability, and it is not a read — it must not exist.",
        );
        $this->assertCount(1, $this->wrongs, 'refused once: ' . $this->wrongsText());
    }

    // =====================================================================
    // FIX WAVE 1 — B17: a non-fatal refusal is visible on production
    // (sentinel I5)
    // =====================================================================

    public function testTwoPublicMisusesFromTwoCallSitesAreBothReportedAndBothLogged(): void
    {
        // WHY: _doing_it_wrong() is SILENT inside a REST request — core's
        // doing_it_wrong_trigger_error filter is false there — so a public()
        // that refuses tells a production site nothing at all unless it also
        // logs. And a dedup key that is only the namespace reports the first
        // misuse and swallows the second, which is the one in the file the
        // author is actually editing. Two call sites, two reports, two log
        // lines.
        $this->misusePublicFromTheFirstCallSite();
        $this->misusePublicFromTheSecondCallSite();

        $this->assertCount(
            2,
            $this->wrongs,
            'two misuses in one namespace, from two lines, are two reports: ' . $this->wrongsText(),
        );
        $this->assertCount(
            2,
            $this->logMessages('api', 'warning'),
            'a non-fatal refusal must reach the log at warning — _doing_it_wrong is invisible inside REST.',
        );
    }

    private function misusePublicFromTheFirstCallSite(): void
    {
        ntdst_rest('twosite/v1')->public();
    }

    private function misusePublicFromTheSecondCallSite(): void
    {
        ntdst_rest('twosite/v1')->public();
    }

    // =====================================================================
    // Shared readers for the cases above
    // =====================================================================

    /** Every refusal flattened to one string — function, message and version. */
    private function wrongsText(): string
    {
        return implode(' | ', array_map(
            static fn(array $wrong): string => implode(' ', $wrong),
            $this->wrongs,
        ));
    }

    /** @return list<string> the registered methods, normalized from WP's string-or-array shape */
    private function methodsOf(array $args): array
    {
        $methods = $args['methods'] ?? [];
        $methods = is_array($methods) ? $methods : explode(',', (string) $methods);

        return array_values(array_map(static fn($m) => strtoupper(trim((string) $m)), $methods));
    }
}

/**
 * A global function whose name is ALSO a capability slug — the shape WordPress
 * itself ships (edit_post(), delete_plugins(), activate_plugins()). The
 * framework must never call it: a permission string is a capability, and the
 * only thing that may answer it is current_user_can().
 */
function ntdst_probe_cap_x(): bool
{
    $GLOBALS['_ntdst_probe_cap_calls'] = ($GLOBALS['_ntdst_probe_cap_calls'] ?? 0) + 1;

    return true;
}
