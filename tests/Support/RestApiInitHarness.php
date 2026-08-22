<?php // tests/Support/RestApiInitHarness.php
// The rest_api_init harness, once. Three test files drove WordPress's hook with
// three hand-copied versions of the same code (NtdstRestDefaultsTest,
// RestInternalByDefaultTest, NtdstRestCorsTest); a fix to one of them fixed one
// third of the suite. This trait is the single copy, and tests/bootstrap.php
// requires it so the two REAL functions below are defined before Patchwork can
// see them.
//
// WHAT THIS MODELS, AND WHY EACH PIECE IS THE CONTRACT RATHER THAN CONVENIENCE:
//
// 1. THE THREE HOOK STATES. did_action('rest_api_init') reads 1 from the moment
//    the hook STARTS and forever after, so it cannot tell "inside the hook" —
//    the place WordPress documents for route registration — from "long after
//    it". doing_action() is the second bit. The three states a consumer can
//    declare from are therefore (0,false) before, (1,true) inside, (1,false)
//    after, and every timing decision in the framework has to hold in all
//    three.
// 2. WP_Hook WALKS ITS CALLBACK LIST LIVE. A flush a declaration mounts from
//    inside the running hook still runs, so the list is re-read after every
//    callback. A callback mounted at a priority the iteration has ALREADY
//    PASSED never runs — WordPress does not walk backwards — so a wrapper that
//    defers to an earlier priority shows up here as an absent route instead of
//    hiding behind a helper that replays everything.
// 3. CORE'S CORS EMITTER STARTS ON THE BUS. rest_send_cors_headers() is mounted
//    at priority 10 on rest_pre_serve_request by WordPress itself, and it
//    reflects ANY origin back with credentials. Seeding it here is what makes
//    "core's handler is off and ours is on" an assertion instead of a hope: a
//    declaration that widens the site-wide allow-list while that handler is
//    still mounted has opened the site, and the harness can see it.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

// wp_is_serving_rest_request() must be a REAL function, for the reason
// tests/bootstrap.php gives for add_filter and ntdst_log: shipped code guards
// on function_exists(), and a Brain Monkey stub defines the name PROCESS-WIDE
// from the first test that uses it — after which every later test file that
// does NOT stub it fails with "not defined nor mocked in this test", in a file
// that changed nothing. Defined here it answers a global instead, so a test
// says which kind of request it is by setting that global and nothing leaks.
//
// The DEFAULT is true — serving a REST request — because that is the context
// every route and CORS assertion in this suite is written about. A test that
// wants admin-ajax, admin-post or the customizer says so with
// servingRestRequest(false).
if (!function_exists('wp_is_serving_rest_request')) {
    function wp_is_serving_rest_request(): bool
    {
        return (bool) ($GLOBALS['_ntdst_test_serving_rest'] ?? true);
    }
}

// wp_is_json_request() exists here for ONE reason: it is the trap that makes
// "a string permission is a capability" worth testing. It is a real WordPress
// function, it is TRUE for every REST client, and it is callable — so a
// wrapper that treats a string as a callable when a function of that name
// happens to exist turns 'wp_is_json_request' into "allow everyone". It must
// never be called by the framework; a test asserts current_user_can() was asked
// instead.
if (!function_exists('wp_is_json_request')) {
    function wp_is_json_request(): bool
    {
        $GLOBALS['_ntdst_test_json_request_calls'] = ($GLOBALS['_ntdst_test_json_request_calls'] ?? 0) + 1;

        return true;
    }
}

trait RestApiInitHarness
{
    /** Callbacks hung on a WP hook, keyed by hook then PRIORITY, in mount order. */
    private array $hooked = [];

    /** @var list<array{0: string, 1: mixed}> Every remove_filter() call: [hook, callback]. */
    private array $removedFilters = [];

    /** @var list<string|null> Every origin the code put to is_allowed_http_origin(), in order. */
    private array $askedWordPress = [];

    /** did_action('rest_api_init') — 0 before the hook, 1 from the moment it starts. */
    private int $restApiInitDid = 0;

    /** doing_action('rest_api_init') — true only WHILE the callbacks run. */
    private bool $restApiInitDoing = false;

    /**
     * What get_allowed_http_origins() builds before the filter runs, for a site
     * whose home and admin share a host. Every list assertion is exact and
     * order-preserving against this: WordPress's entries first, in WordPress's
     * order, then whatever was declared.
     *
     * @var list<string>
     */
    private array $wpDefaultOrigins = ['http://site.test', 'https://site.test'];

    // =====================================================================
    // Setting the world back to zero
    // =====================================================================

    /**
     * Clear every per-process cache the class keeps, by REFLECTION over the
     * statics it declares — not by a hand-written list. A hand-written list
     * silently stops covering a static the moment one is added, and the
     * symptom is a test that reads the previous test's declaration and passes
     * on evidence it never produced.
     */
    private function resetRestStatics(): void
    {
        foreach ((new ReflectionClass(NTDST_Rest::class))->getProperties(ReflectionProperty::IS_STATIC) as $property) {
            if (!$property->hasDefaultValue()) {
                continue;
            }

            $property->setAccessible(true);
            $property->setValue(null, $property->getDefaultValue());
        }
    }

    /** Statics, recorders, hook globals and the request kind — one call from setUp(). */
    private function resetRestHarness(): void
    {
        $this->hooked           = [];
        $this->removedFilters   = [];
        $this->askedWordPress   = [];
        $this->restApiInitDid   = 0;
        $this->restApiInitDoing = false;

        // tests/bootstrap.php defines add_filter and ntdst_log as REAL
        // recorders for the whole suite, so an earlier test file's mounts and
        // log lines would otherwise be read as this test's evidence. Only the
        // hooks these files drive are cleared — the suite shares one process
        // and other files' load-time mounts must survive.
        $this->forgetRecordedHooks();

        $GLOBALS['_ntdst_test_log']          = [];
        $GLOBALS['_ntdst_test_http_origin']  = '';
        $GLOBALS['_ntdst_test_serving_rest'] = true;
        $GLOBALS['_ntdst_test_json_request_calls'] = 0;

        $this->resetRestStatics();
    }

    /** Every hook these tests drive, cleared out of the process-wide recorders. */
    private function forgetRecordedHooks(): void
    {
        foreach (['rest_api_init', 'allowed_http_origins', 'allowed_http_origin', 'rest_pre_serve_request'] as $hook) {
            unset($GLOBALS['_ntdst_test_filters'][$hook], $GLOBALS['_ntdst_test_filters_at'][$hook]);
        }

        // WordPress mounts rest_send_cors_headers() itself, at priority 10 on
        // rest_pre_serve_request. It is on the bus before this package says a
        // word, and it reflects any origin back with credentials.
        $GLOBALS['_ntdst_test_filters_at']['rest_pre_serve_request'][10] = 'rest_send_cors_headers';
    }

    /** Drop a callback from the process-wide recorders — what remove_filter() does. */
    private function forgetRecordedFilter($hook, $callback): void
    {
        $hook = (string) $hook;

        foreach (($GLOBALS['_ntdst_test_filters_at'][$hook] ?? []) as $priority => $mounted) {
            if ($mounted === $callback) {
                unset($GLOBALS['_ntdst_test_filters_at'][$hook][$priority]);
            }
        }

        if (($GLOBALS['_ntdst_test_filters'][$hook] ?? null) === $callback) {
            unset($GLOBALS['_ntdst_test_filters'][$hook]);
        }
    }

    // =====================================================================
    // The three states, and how a declaration reaches each of them
    // =====================================================================

    /** @return array<string, array{0: string}> before / inside / after — the three a consumer can declare from. */
    public static function hookStateProvider(): array
    {
        return [
            'declared before rest_api_init'          => ['before'],
            'declared inside a rest_api_init callback' => ['inside'],
            'declared after rest_api_init finished'  => ['after'],
        ];
    }

    /** Put the world in one of the three states without firing anything. */
    private function restApiInitState(string $state): void
    {
        $this->restApiInitDid   = $state === 'before' ? 0 : 1;
        $this->restApiInitDoing = $state === 'inside';
    }

    /**
     * Run a declaration in the given hook state, the way a site would reach it.
     *
     * 'inside' mounts at priority 20 on purpose: PAST the priority a deferred
     * mount would use, so a declaration that schedules its own work for an
     * earlier priority is not quietly rescued by this helper.
     */
    private function declareInHookState(string $state, callable $declare): void
    {
        $this->restApiInitState('before');

        if ($state === 'inside') {
            add_action('rest_api_init', static function () use ($declare): void {
                $declare();
            }, 20);
            $this->fireRestApiInit();

            return;
        }

        if ($state === 'after') {
            $this->fireRestApiInit();
            $declare();

            return;
        }

        $declare();
        $this->fireRestApiInit();
    }

    /** Say which kind of request this is: REST, or admin-ajax / admin-post / the customizer. */
    private function servingRestRequest(bool $serving): void
    {
        $GLOBALS['_ntdst_test_serving_rest'] = $serving;
    }

    // =====================================================================
    // Firing the hook
    // =====================================================================

    /**
     * Every callback mounted on rest_api_init, keyed by priority, read FRESH.
     *
     * Two recorders exist: the test file's Brain Monkey add_action stub, and
     * the REAL add_filter tests/bootstrap.php defines — in WordPress add_action
     * IS add_filter, so a wrapper that mounts through either one must be
     * flushed by this harness.
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
     * Fire rest_api_init once, the way WP_Hook::apply_filters() fires it:
     * did_action() becomes 1 BEFORE the callbacks run, doing_action() is true
     * only while they run, the callback list is re-read after every callback,
     * and a callback mounted at a priority the iteration has already passed
     * never runs.
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

    // =====================================================================
    // WordPress's allow-list, and who is emitting CORS headers
    // =====================================================================

    /**
     * Run WordPress's `allowed_http_origins` filter over its own defaults.
     *
     * @return list<string>
     */
    private function allowList(): array
    {
        $this->assertArrayHasKey(
            'allowed_http_origins',
            $GLOBALS['_ntdst_test_filters'] ?? [],
            'cors() must add the declared origins to WordPress\'s own allow-list '
            . '(INV-5: core keeps no table WordPress already keeps).',
        );

        $list = ($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])($this->wpDefaultOrigins);

        $this->assertIsArray($list, 'The allowed_http_origins filter must return the list, not a scalar.');

        return array_values($list);
    }

    /**
     * The same list, without requiring that anything be mounted. A declaration
     * that adds no origin has nothing to mount, and whether it mounts an inert
     * callback anyway is not a promise worth pinning. What the list CONTAINS is.
     *
     * @return list<string>
     */
    private function allowListIfMounted(): array
    {
        $list = isset($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])
            ? ($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])($this->wpDefaultOrigins)
            : $this->wpDefaultOrigins;

        return array_values((array) $list);
    }

    /**
     * `is_allowed_http_origin()`, reproduced from wp-includes/http.php:480–500 —
     * the filtered list, a STRICT in_array, then the `allowed_http_origin`
     * result filter. This is the question the site really asks.
     */
    private function wordPressAllows(string $origin): bool
    {
        $GLOBALS['_ntdst_test_http_origin'] = $origin;

        $list = isset($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])
            ? ($GLOBALS['_ntdst_test_filters']['allowed_http_origins'])($this->wpDefaultOrigins)
            : $this->wpDefaultOrigins;

        $result = in_array($origin, (array) $list, true) ? $origin : '';

        if (isset($GLOBALS['_ntdst_test_filters']['allowed_http_origin'])) {
            $result = ($GLOBALS['_ntdst_test_filters']['allowed_http_origin'])($result, $origin);
        }

        return $result !== '';
    }

    /**
     * Stub `is_allowed_http_origin()` — WordPress returns THE ORIGIN when it is
     * allowed and an EMPTY STRING when it is not, never a bool.
     */
    private function wordPressAllowsOnly(string ...$allowed): void
    {
        Brain\Monkey\Functions\when('is_allowed_http_origin')->alias(
            function ($origin = null) use ($allowed) {
                $this->askedWordPress[] = $origin;
                return in_array($origin, $allowed, true) ? (string) $origin : '';
            },
        );
    }

    /** Is WordPress's own reflect-any-origin emitter still on rest_pre_serve_request? */
    private function coreCorsEmitterIsMounted(): bool
    {
        return in_array(
            'rest_send_cors_headers',
            array_values($GLOBALS['_ntdst_test_filters_at']['rest_pre_serve_request'] ?? []),
            true,
        );
    }

    /** The priority this package's emitter went on at, or null if it never did. */
    private function ntdstCorsEmitterPriority(): ?int
    {
        foreach (($GLOBALS['_ntdst_test_filters_at']['rest_pre_serve_request'] ?? []) as $priority => $callback) {
            if ($callback === [NTDST_Rest::class, 'sendCors']) {
                return (int) $priority;
            }
        }

        return null;
    }

    /**
     * Entries the real ntdst_log() recorder took, by channel and level.
     * tests/bootstrap.php stores [channel, level, message].
     *
     * @return list<string>
     */
    private function logMessages(string $channel, string $level): array
    {
        return array_values(array_map(
            static fn(array $entry): string => (string) ($entry[2] ?? ''),
            array_filter(
                $GLOBALS['_ntdst_test_log'] ?? [],
                static fn(array $entry): bool => ($entry[0] ?? '') === $channel && ($entry[1] ?? '') === $level,
            ),
        ));
    }
}
