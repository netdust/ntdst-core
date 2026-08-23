<?php

declare(strict_types=1);

/**
 * NTDST Bootstrap
 *
 * Orchestrates service registration and initialization with clear lifecycle phases
 * Generic infrastructure - works with any theme configuration
 *
 * Lifecycle:
 * 1. Register      - Services added to DI container (immediate)
 * 2. Boot Core     - Services with priority < 10 (after_setup_theme:5)
 * 3. Boot Features - Remaining services initialized (after_setup_theme:15)
 *
 * @package ntdst-core
 */

defined('ABSPATH') || exit;

/**
 * Hook + filter naming conventions:
 *  - Actions: `ntdst/*` (e.g. `ntdst/core_ready`)
 *  - Filters: `ntdst/service/{slug}/config` — the ONE per-service extension
 *    point, keyed by service slug.
 *
 * WHAT REGISTRATION READS. `register()` walks `services.core`, `services.admin`
 * and `services.conditional` ONCE and registers exactly what it finds there.
 * Nothing else in the `services` array is read: a key core consults and then
 * ignores is a promise read back as a maybe (INV-10).
 *
 * EVERY ENTRY IS A VALUE A HUMAN TYPED, so each malformed shape has the same
 * answer — ONE `_doing_it_wrong()` naming the value and the config key it came
 * from, that entry skipped, the rest of the boot untouched:
 *  - a non-string or empty entry (a stray `0`, a `null` left by a trailing
 *    comma), which used to be a TypeError out of `register()`;
 *  - a name that is not a legal PHP class name — refused BEFORE `class_exists()`,
 *    because that function hands whatever string it is given to every registered
 *    autoloader and a PSR-4 autoloader turns a string into a FILE PATH;
 *  - a `services.conditional` entry with no string `service`, or whose
 *    `condition` is not a Closure or an [object|class, method] array — a STRING
 *    is a function NAME, so `is_callable()` alone would let a config value
 *    choose a function core calls;
 *  - a class nothing loaded. That one is ALSO written at error level through
 *    `ntdst_log()`, because `_doing_it_wrong()` is WP_DEBUG-gated and a missing
 *    service on a live site would otherwise be silent;
 *  - a second class claiming a slug another service already holds;
 *  - a `services.overrides` value that is not an array.
 * A class the consumer listed twice, in one sector or across two, is refused
 * ONCE — one defect is one notice and one log line.
 *
 * `register()` IS SPENT THE MOMENT IT STARTS. The latch is set BEFORE the walk,
 * so a consumer's `metadata()` that throws mid-walk cannot leave a second call
 * free to re-register the first pass's services and reprint its refusals.
 * Registration has no try/catch and that is deliberate: a declaration that
 * throws is a broken deployment, not a warning.
 *
 * A SERVICE IS OFF IN EXACTLY TWO WAYS: it declares `metadata()['enabled'] =>
 * false`, or its `services.conditional` condition returns false. `metadata()`
 * is a DECLARATION, not a query — it must be `static`, it is read ONCE per class
 * per boot, and an instance method by that name draws a notice and declares
 * nothing at all.
 *
 * A service's config override is declared at
 * `config['services']['overrides'][$slug]`. It nests under the existing
 * `services` key rather than replacing it — `services.core`, `services.admin`
 * and `services.conditional` already live there.
 *
 * AN OVERRIDE KEY THAT ANSWERS TO NO SERVICE IS REFUSED — once, at `register()`,
 * with `_doing_it_wrong()` naming the full dotted key, because that is the
 * string the site owner greps their config for.
 *
 * "Answers to no service" is judged over the classes LISTED in `services.core`,
 * `services.admin` and `services.conditional` — ALL of them, including admin
 * entries this request skipped because `is_admin()` is false, conditionals
 * whose condition returned false, and classes their own `metadata()` switched
 * off. It is NOT judged over the registry. An override for a service the
 * consumer listed and this request did not register is a correct config that
 * is merely not in effect: refusing there would print a notice on nearly every
 * anonymous page view of a site that has admin services, which trains the
 * reader to ignore the one notice that matters. The cost of drawing the line
 * at LISTED: a stale override for a still-listed but disabled service stays
 * silent. The service's own declaration is the switch — the override is not.
 *
 * The REGISTERED slugs are asked FIRST, and the listed classes' slugs are
 * resolved only when a key matches none of them. Resolving a slug autoloads the
 * class and runs its `metadata()`, so the eager form pulled every admin
 * service's file into memory on every anonymous page view of any site that had
 * an override at all.
 *
 * SLUG DERIVATION — read this before writing an override. `{slug}` above is NOT
 * the class name lowercased. When a service declares no `metadata()['name']`,
 * `getServiceSlug()` strips EVERY occurrence of `Service` — not just a
 * trailing one — and converts the rest from camelCase to snake_case, keeping a
 * run of consecutive capitals together as ONE token:
 *
 *     AdminUIService     -> admin_ui
 *     APIRouterService   -> api_router
 *     CacheHeadersService-> cache_headers
 *     ProfileService     -> profile
 *
 * "Every occurrence" is literal (`str_replace`, not an anchored `/Service$/`),
 * and it is stated because the difference only shows on names nobody has
 * written yet: `ServiceRegistryService -> registry`, `MyServiceHandlerService ->
 * my_handler`, and `ServiceService -> ''` — an EMPTY slug, i.e. the filter
 * `ntdst/service//config`, which no consumer will ever guess. Declare
 * `metadata()['name']` on any such class rather than relying on the derivation.
 *
 * To pin a slug that the derivation would not produce, declare it —
 * `metadata()['name']` takes precedence over the derivation entirely (it is
 * whitespace-split and lowercased, never collapsed). The name must be DECLARED
 * by the class: the `name` key in a metadata array is defaulted to a
 * human-readable label, so it is always populated and cannot say whether the
 * service meant anything by it.
 *
 * Boot order with equal priority is best-effort (PHP's uasort isn't stable).
 * If two services depend on each other, set their priorities — never rely on
 * registration order.
 *
 * WHAT MOVED AND WHAT BREAKS. The retired filter and option spellings, the
 * deleted per-service enable switch, the retired slug derivation
 * (`admin_u_i`) and the F7 call-order fix are recorded once, in README's
 * `#### Core-trim — what left the package` migration table. That is where a
 * consumer looks after a fatal; a migration log inside a class docblock is read
 * by nobody upgrading and by everybody maintaining.
 */
class NTDST_Bootstrap
{
    /**
     * What a PHP class name may look like, checked BEFORE `class_exists()`.
     *
     * `class_exists()` is not a validator: it hands the string it was given to
     * every registered autoloader, and a PSR-4 autoloader's whole job is to turn
     * that string into a file path. PHP refuses to autoload some malformed
     * shapes on its own and forwards others — an empty namespace segment
     * (`Acme\\Evil`) and a digit-initial one (`Acme\1Evil`) both reach the
     * loader — so core cannot rely on it. This is PHP's own grammar for a
     * namespaced name, high bytes included: three of five consumer sites load
     * their services with a plain `require_once` and no Composer map, and an
     * underscore-carrying, digit-carrying namespaced name is ordinary on the
     * fleet. A check one character too strict is a site down at boot.
     */
    private const CLASS_NAME_SHAPE =
        '/^\\\\?[A-Za-z_\x80-\xff][\w\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)*$/';

    private array $config;
    private array $services = [];
    private array $bootedServices = [];
    private bool $servicesRegistered = false;
    private bool $coreBooted = false;
    private bool $featuresBooted = false;

    /**
     * PERFORMANCE: Cache for service slugs to avoid repeated regex operations
     */
    private array $slugCache = [];

    /**
     * What each class DECLARED, or null when it declared nothing readable.
     *
     * `metadata()` is a consumer's method and core asks two questions of it —
     * the metadata array, and whether a name was declared. This is what makes
     * that ONE call: a declaration read twice is a declaration free to answer
     * differently the second time, and anything a service does in there (a
     * translation call, a `get_option()`) happened twice per request.
     */
    private array $metadataCache = [];

    /**
     * slug => the class that holds it. One slug is one service.
     */
    private array $registeredSlugs = [];

    /**
     * Classes already refused, so one defect is one notice.
     *
     * `$this->services` cannot answer this: a class that never loaded never got
     * in there, so the entries with something to report are exactly the ones its
     * dedupe misses.
     */
    private array $refusedClasses = [];

    /**
     * PERFORMANCE: Pre-merged per-service config overrides (avoids filter overhead)
     */
    private array $serviceConfigCache = [];

    /**
     * Constructor
     *
     * @param array $config Configuration array from theme config.php
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // Always log bootstrap creation
        ntdst_log()->debug('NTDST Bootstrap: Instance created with ' . count($config) . ' config keys');
    }

    // ========================================
    // PHASE 1: REGISTRATION
    // ========================================

    /**
     * Register all services in the DI container
     * Called immediately, no hooks
     *
     * @return self
     */
    public function register(): self
    {
        if ($this->servicesRegistered) {
            return $this;
        }

        // The latch closes FIRST. register() is spent the moment it starts,
        // however it ends: a consumer's metadata() that throws mid-walk used to
        // leave the latch down, so the next call re-registered everything the
        // first pass had already registered and printed its refusals again.
        $this->servicesRegistered = true;

        // ONE pass over the three keys. It registers as it goes AND collects the
        // names it saw, and those names are what the override check is judged
        // against. The second traversal this replaced kept its own idea of what
        // an entry was, and the two drifted: it dropped a non-string silently
        // where the registration walk turned the same value into a TypeError.
        //
        // Nothing else in the `services` array is read: a key core consults and
        // then ignores is a promise read back as a maybe (INV-10).
        $listed = [];

        foreach ($this->config['services']['core'] ?? [] as $entry) {
            $class = $this->listedClassName($entry, 'core');

            if ($class === null) {
                continue;
            }

            $listed[$class] = true;
            $this->registerService($class, 'core');
        }

        // An admin entry is COLLECTED on every request and RESOLVED only on an
        // admin one. Collecting it is what keeps its override key silent on the
        // front end; resolving it there would ask an autoloader for a class this
        // request has no use for.
        $isAdmin = is_admin();

        foreach ($this->config['services']['admin'] ?? [] as $entry) {
            $class = $this->listedClassName($entry, 'admin');

            if ($class === null) {
                continue;
            }

            $listed[$class] = true;

            if ($isAdmin) {
                $this->registerService($class, 'admin');
            }
        }

        // A conditional is listed whatever its condition answers — the consumer
        // named the class, which is the only question a dead override key asks.
        foreach ($this->config['services']['conditional'] ?? [] as $spec) {
            $class = $this->listedClassName(
                is_array($spec) ? ($spec['service'] ?? null) : null,
                'conditional',
            );

            if ($class === null) {
                continue;
            }

            $listed[$class] = true;

            if ($this->conditionHolds((array) $spec)) {
                $this->registerService($class, 'conditional');
            }
        }

        // An overrides key that answers to no LISTED service is refused here,
        // inside the `servicesRegistered` latch, so one typo is one notice
        // however many times register() is called (AF-14). A notice that
        // repeats turns a config typo into a log flood, and with
        // WP_DEBUG_DISPLAY on, into repeated output on the page.
        $this->refuseUnusableOverrides($listed);

        // Always log registration summary
        ntdst_log()->debug('NTDST Bootstrap: Registered ' . count($this->services) . ' services');

        do_action('ntdst/services_registered', $this);

        return $this;
    }

    /**
     * The class name a list entry names, or null when the entry is malformed.
     *
     * Two refusals, both fail-closed, both one notice naming the value and the
     * SECTOR it came from — a fleet config lists services in three places, and
     * "one of your entries is malformed" sends the reader hunting through all
     * of them.
     *
     * The SHAPE check is the second one and it runs here, before the caller
     * reaches `class_exists()`, because that function is an autoloader
     * argument: see self::CLASS_NAME_SHAPE. A `services.conditional` entry
     * arrives as its `service` value, so a spec that lost that key is the same
     * defect as a `null` in `services.core` and gets the same sentence.
     *
     * @param mixed  $entry  The value the consumer wrote
     * @param string $sector The config key it came from: core|admin|conditional
     * @return string|null The class name, or null when the entry was refused
     */
    private function listedClassName(mixed $entry, string $sector): ?string
    {
        if (!is_string($entry) || trim($entry) === '') {
            _doing_it_wrong(
                __CLASS__ . '::register',
                "services.{$sector} contains a non-string entry — list fully-qualified class names only",
                '5.0.0',
            );

            return null;
        }

        if (preg_match(self::CLASS_NAME_SHAPE, $entry) !== 1) {
            _doing_it_wrong(
                __CLASS__ . '::register',
                "services.{$sector} lists \"{$entry}\", which is not a legal PHP class name. It is refused "
                    . 'here, before any autoloader is asked to resolve it — a PSR-4 loader turns a class '
                    . 'name into a file path.',
                '5.0.0',
            );

            return null;
        }

        return $entry;
    }

    /**
     * Does a `services.conditional` entry's condition hold?
     *
     * A condition is a Closure or an [object|class, method] array, and nothing
     * else. `is_callable()` alone accepts a STRING, and a string is a function
     * NAME — `'condition' => 'phpinfo'` would make a config value choose a
     * function core calls during registration, which turns the config file into
     * a call site. An absent condition is not a misconfiguration; it simply
     * does not hold.
     *
     * @param array $spec The conditional entry
     * @return bool
     */
    private function conditionHolds(array $spec): bool
    {
        $condition = $spec['condition'] ?? null;

        if ($condition instanceof Closure || (is_array($condition) && is_callable($condition))) {
            return (bool) $condition();
        }

        if ($condition !== null) {
            _doing_it_wrong(
                __CLASS__ . '::register',
                'services.conditional holds an entry whose `condition` is neither a Closure nor an '
                    . '[object|class, method] array. A condition is code the consumer wrote; a name core '
                    . 'looks up and calls is a config value choosing a function.',
                '5.0.0',
            );
        }

        return false;
    }

    /**
     * Register a single service
     *
     * The class must already be resolvable — by the consumer's own
     * `require_once`, by Composer, or by any autoloader the consumer
     * installed. Core loads nothing: it neither scans a directory nor derives
     * a file path from a class name (INV-10). A writable directory on a
     * scanned path was code execution, and a name in a config array is not a
     * safe path.
     *
     * @param string $class  Fully qualified class name
     * @param string $sector The config key the name came from: core|admin|conditional
     * @return void
     */
    private function registerService(string $class, string $sector): void
    {

        // Skip if already registered
        if (isset($this->services[$class])) {
            return;
        }

        // One class is one refusal, however many times the config names it —
        // in one sector or across two. See $refusedClasses.
        if (isset($this->refusedClasses[$class])) {
            return;
        }

        // The name reached here already shaped like a class name, so
        // `class_exists()` is the whole admission test, and its autoload pass
        // is the consumer's loader, never one core installed.
        if (!class_exists($class)) {
            $this->refusedClasses[$class] = true;

            // The site owner reads this notice, so it names BOTH the class they
            // listed and the key they listed it under — a fleet config lists
            // services in three places. `_doing_it_wrong()` is the WordPress
            // channel for "the code calling me is wrong"; the old debug line
            // was a file nobody opens, so a service went missing in silence.
            // The function named is the PUBLIC entry the consumer called, not
            // this private helper, because that is what WordPress prints.
            _doing_it_wrong(
                __CLASS__ . '::register',
                "Service class {$class} (services.{$sector}) is not loaded — require it or autoload it before register()",
                '5.0.0',
            );

            // The notice above is for a developer at their desk: WordPress
            // decides whether _doing_it_wrong() prints, and with WP_DEBUG off it
            // does nothing at all. The failure it would hide is the expensive
            // one — a service the config lists, missing, on production, with no
            // trace anywhere. bootService() already logs a failed CONSTRUCTION
            // at error level; a service that never reached construction earns
            // the same line.
            ntdst_log()->error(
                "NTDST Bootstrap: listed service {$class} (services.{$sector}) is not loaded",
            );

            return;
        }

        // Get metadata if available
        $metadata = $this->getServiceMetadata($class);

        // A service is off when it says so. There is no second reader between
        // the class's own declaration and this decision, and no third switch
        // at all: 5.0.0 removed the per-service option and the DENY filter
        // that failed open (FR-2). The other surviving way off is the
        // `services.conditional` condition, read in register().
        if (isset($metadata['enabled']) && !$metadata['enabled']) {
            return;
        }

        // Check admin context
        if (($metadata['admin_only'] ?? false) && !is_admin()) {
            return;
        }

        $slug = $this->getServiceSlug($class);

        // One slug is one service. The slug is the PUBLIC extension key, so two
        // classes holding it means the site owner's override reaches whichever
        // registered first while the second reads a config that belongs to
        // something else — with nothing anywhere saying why. The notice names
        // BOTH: naming only the loser leaves the reader looking for a conflict
        // they cannot see.
        if (isset($this->registeredSlugs[$slug])) {
            // It lost the slug for this boot, so a second listing of the same
            // class is the same defect and says nothing new.
            $this->refusedClasses[$class] = true;

            _doing_it_wrong(
                __CLASS__ . '::register',
                "Two services answer to the slug \"{$slug}\": {$this->registeredSlugs[$slug]} holds it, so "
                    . "{$class} (services.{$sector}) is refused. The slug is the key of the one per-service "
                    . "filter (ntdst/service/{$slug}/config), so a shared slug delivers one service's "
                    . "override to the other. Declare a metadata()['name'] on one of them.",
                '5.0.0',
            );

            return;
        }

        $this->registeredSlugs[$slug] = $class;

        // PERFORMANCE: Pre-compute slug and cache the service's config override
        // This replaces the per-service filter with direct config lookup.
        // Overrides nest UNDER the existing `services` key (beside `core`,
        // `admin` and `conditional`) rather than occupying it, which is
        // already taken.
        //
        // array_key_exists, and the value has to BE an array: a `null` override
        // is invisible to isset() and a `true` one mounts a callback that runs
        // `array_merge($defaults, true)` inside the service's constructor,
        // where the TypeError is swallowed as "failed to boot". Both are
        // refused by refuseUnusableOverrides(), which sees every key including
        // the ones no service claimed; nothing broken is mounted here.
        $overrides = $this->configuredOverrides();

        if (array_key_exists($slug, $overrides) && is_array($overrides[$slug])) {
            $this->serviceConfigCache[$slug] = $overrides[$slug];
        }

        // Register in DI container
        ntdst_set($class);

        // Track service
        $this->services[$class] = [
            'class' => $class,
            'metadata' => $metadata,
            'booted' => false,
            'priority' => $metadata['priority'] ?? 10,
        ];
    }

    /**
     * The `services.overrides` array as configured, whatever the consumer put there.
     *
     * @return array<array-key, mixed>
     */
    private function configuredOverrides(): array
    {
        $overrides = $this->config['services']['overrides'] ?? [];

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * Refuse every `services.overrides` entry no service can use.
     *
     * Two ways an override is unusable, one notice each, both carrying the FULL
     * dotted key — that is the string the site owner greps their config for.
     *
     * A KEY NO SERVICE ANSWERS TO. The consumer's half of the rename risk:
     * `services.overrides.securty` used to reach nothing and say nothing, so
     * the site owner edited a config, reloaded, saw no change and had nothing
     * to look for. Judged over LISTED classes, not REGISTERED ones — see the
     * class docblock — but the REGISTERED slugs are asked first, because they
     * are already in hand. Resolving a listed class's slug autoloads it and
     * runs its `metadata()`, so the eager form loaded the whole admin tree on
     * every anonymous page view of a site whose config was perfectly correct.
     * The fallback is built once, and only for classes this request did not
     * register.
     *
     * A VALUE THAT IS NOT AN ARRAY. `overrides.security => true` is what a
     * consumer writes when they think the key is a switch; it used to mount a
     * callback that ran `array_merge($defaults, true)` INSIDE the service's
     * constructor, where bootService() caught the TypeError and logged "failed
     * to boot" against a constructor that had nothing wrong with it. `null` is
     * the same mistake wearing a different mask — `isset()` reads it as absent.
     * A broken override costs the site its OVERRIDE, never its SERVICE.
     *
     * @param array<string, true> $listed Every class the walk collected, as keys
     * @return void
     */
    private function refuseUnusableOverrides(array $listed): void
    {
        $overrides = $this->configuredOverrides();

        if ($overrides === []) {
            return;
        }

        $listedSlugs = null;

        foreach ($overrides as $key => $value) {
            $key = (string) $key;

            if (!isset($this->registeredSlugs[$key])) {
                if ($listedSlugs === null) {
                    $listedSlugs = [];

                    foreach (array_keys($listed) as $class) {
                        if (!isset($this->services[$class])) {
                            $listedSlugs[$this->getServiceSlug($class)] = true;
                        }
                    }
                }

                if (!isset($listedSlugs[$key])) {
                    _doing_it_wrong(
                        __CLASS__ . '::register',
                        "Config key services.overrides.{$key} matches no registered service — no class listed "
                            . 'in services.core, services.admin or services.conditional answers to the slug '
                            . "\"{$key}\". Fix the key or drop it: an override nothing reads is a setting that "
                            . 'lies to its reader.',
                        '5.0.0',
                    );

                    continue;
                }
            }

            if (!is_array($value)) {
                _doing_it_wrong(
                    __CLASS__ . '::register',
                    "Config key services.overrides.{$key} must be an array of config values, and this one is "
                        . gettype($value) . '. It is not applied: the service boots on its own defaults.',
                    '5.0.0',
                );
            }
        }
    }

    // ========================================
    // PHASE 2: BOOT CORE
    // ========================================

    /**
     * Boot core services (critical services that must run early)
     * Hook: after_setup_theme:5
     *
     * @return self
     */
    public function bootCore(): self
    {
        if ($this->coreBooted) {
            return $this;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            ntdst_log()->debug('NTDST Bootstrap: Starting bootCore()');
            ntdst_log()->debug('NTDST Bootstrap: Registered services: ' . count($this->services));
        }

        // Sort by priority so within-core ordering is deterministic.
        uasort($this->services, fn($a, $b) => $a['priority'] <=> $b['priority']);

        // Boot services with priority < 10 (critical services)
        foreach ($this->services as $class => $service) {
            if ($service['priority'] < 10 && !$service['booted']) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    ntdst_log()->debug("NTDST Bootstrap: Booting core service {$class} (priority: {$service['priority']})");
                }
                $this->bootService($class);
            }
        }

        $this->coreBooted = true;

        do_action('ntdst/core_ready', $this);

        return $this;
    }

    // ========================================
    // PHASE 3: BOOT FEATURES
    // ========================================

    /**
     * Boot feature services (all remaining services)
     * Hook: after_setup_theme:15
     *
     * @return self
     */
    public function bootFeatures(): self
    {
        if ($this->featuresBooted) {
            return $this;
        }

        if (defined('WP_DEBUG') && WP_DEBUG) {
            ntdst_log()->debug('NTDST Bootstrap: Starting bootFeatures()');
            ntdst_log()->debug('NTDST Bootstrap: Unbooted services: ' . count(array_filter($this->services, fn($s) => !$s['booted'])));
        }

        // Sort services by priority
        uasort($this->services, fn($a, $b) => $a['priority'] <=> $b['priority']);

        // Boot all unbooted services
        foreach ($this->services as $class => $service) {
            if (!$service['booted']) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    ntdst_log()->debug("NTDST Bootstrap: Booting feature service {$class} (priority: {$service['priority']})");
                }
                $this->bootService($class);
            }
        }

        $this->featuresBooted = true;

        if (defined('WP_DEBUG') && WP_DEBUG) {
            ntdst_log()->debug('NTDST Bootstrap: All services booted. Total: ' . count($this->bootedServices));
        }

        do_action('ntdst/features_ready', $this);

        return $this;
    }

    /**
     * Boot a single service
     *
     * @param string $class Service class name
     * @return void
     */
    private function bootService(string $class): void
    {
        if (!isset($this->services[$class]) || $this->services[$class]['booted']) {
            return;
        }

        try {
            // Fire before hook
            do_action("ntdst/service_before_boot/{$class}", $this);

            // PERFORMANCE: Register config filter only when service boots (lazy loading)
            // This ensures filters are only added for services that actually instantiate
            $this->registerServiceConfigFilter($class);

            // Instantiate service (constructor runs init logic)
            $instance = ntdst_get($class);

            // Mark as booted
            $this->services[$class]['booted'] = true;
            $this->bootedServices[] = $class;

            // Debug logging
            if (defined('WP_DEBUG') && WP_DEBUG) {
                ntdst_log()->debug("NTDST Bootstrap: Successfully booted {$class}");
            }

            // Fire after hook
            do_action("ntdst/service_after_boot/{$class}", $instance, $this);

        } catch (\Throwable $e) {
            // Log at error level so failures are visible without WP_DEBUG.
            // Catches Error subclasses (TypeError, RuntimeException) too, so a
            // service whose constructor throws fails loudly instead of silently
            // disappearing from the boot list.
            ntdst_log()->error("NTDST Bootstrap: Failed to boot service {$class}: " . $e->getMessage());
            ntdst_log()->debug($e->getTraceAsString());

            // In debug mode, rethrow so the error surfaces in dev environments.
            if (defined('WP_DEBUG') && WP_DEBUG) {
                throw $e;
            }
        }
    }

    // ========================================
    // METADATA & CONFIGURATION
    // ========================================

    /**
     * Get service metadata
     *
     * @param string $class Service class name
     * @return array Metadata array
     */
    private function getServiceMetadata(string $class): array
    {
        $defaults = [
            'name' => $this->getServiceName($class),
            'description' => '',
            'admin_only' => false,
            'enabled' => true,
            'priority' => 10,
        ];

        $declared = $this->declaredMetadata($class);

        return $declared === null ? $defaults : array_merge($defaults, $declared);
    }

    /**
     * What a class DECLARED in `metadata()`, read ONCE, or null.
     *
     * `metadata()` MUST BE STATIC. `method_exists()` answers true for an
     * instance method too, and `$class::metadata()` on one is a fatal `Error`
     * out of `register()` on every request — a page that never renders because
     * a consumer forgot one keyword in their own service. The answer is the
     * same as everywhere else in registration: name it, skip it, keep booting.
     * A declaration core cannot read declares NOTHING, so such a class takes the
     * slug derived from its class name and the `name` in the unreadable
     * declaration pins nothing.
     *
     * READ ONCE. The result is cached because two questions are asked of it —
     * the metadata array and whether a name was declared — and it is a
     * consumer's method: a translation call, a `get_option()` or a lazy build in
     * there used to happen twice per request, and a declaration read twice is
     * free to answer differently the second time.
     *
     * @param string $class Class name
     * @return array|null The declared array, or null when there is none to read
     */
    private function declaredMetadata(string $class): ?array
    {
        if (array_key_exists($class, $this->metadataCache)) {
            return $this->metadataCache[$class];
        }

        $declared = null;

        if (method_exists($class, 'metadata')) {
            if ((new ReflectionMethod($class, 'metadata'))->isStatic()) {
                $value = $class::metadata();
                $declared = is_array($value) ? $value : null;
            } else {
                _doing_it_wrong(
                    __CLASS__ . '::register',
                    "Service class {$class} declares metadata() as an instance method. metadata() is a "
                        . 'declaration read off the class, so it must be static; this one is ignored and the '
                        . 'service registers with core\'s defaults.',
                    '5.0.0',
                );
            }
        }

        $this->metadataCache[$class] = $declared;

        return $declared;
    }

    /**
     * Get human-readable service name from class name
     *
     * @param string $class Class name
     * @return string Service name
     */
    private function getServiceName(string $class): string
    {
        return ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $this->shortClassName($class)));
    }

    /**
     * The class's own name, without its namespace and without `Service`.
     *
     * The one derivation behind both the human-readable name and the derived
     * slug. They were two copies of the same two lines, and a copy is a thing
     * that can disagree: a change to what `Service` means here has to reach both
     * answers or the label and the extension key stop describing one service.
     *
     * @param string $class Class name
     * @return string
     */
    private function shortClassName(string $class): string
    {
        return str_replace('Service', '', basename(str_replace('\\', '/', $class)));
    }

    /**
     * The slug for a service — a PURE FUNCTION OF THE CLASS, cached.
     *
     * It takes no metadata argument: the answer may not depend on which
     * question a caller asked first. The derivation rules, and when to declare
     * a name instead, are in the class docblock.
     *
     * Only a DECLARED name pins the slug — see declaredServiceName(). The
     * `name` in a metadata ARRAY is not the same thing: getServiceMetadata()
     * defaults it to a human-readable label derived from the class
     * (`AdminUIService` -> `Admin U I`), so honouring that would rename every
     * service on the fleet to the retired `admin_u_i` mangling.
     *
     * PERFORMANCE: caches the slug to avoid repeated regex operations.
     *
     * @param string $class Class name
     * @return string Slug
     */
    private function getServiceSlug(string $class): string
    {
        if (isset($this->slugCache[$class])) {
            return $this->slugCache[$class];
        }

        $declared = $this->declaredServiceName($class);

        if ($declared !== '') {
            $slug = strtolower(preg_replace('/\s+/', '_', $declared));
        } else {
            $name = $this->shortClassName($class);

            // Standard camelCase -> snake_case, in this order. Rule 1 splits a
            // run of capitals only before its LAST one, so an acronym stays one
            // token and hands the following word back (APIRouter -> API_Router);
            // rule 2 then breaks the ordinary lower/digit-to-upper boundaries
            // (Admin_UI, Cache_Headers, T02_Probe). A trailing acronym is
            // covered by rule 2 alone, since rule 1 needs a lowercase after the
            // run (AdminUI -> Admin_UI). `\d` is in rule 2's first class so a
            // digit closes a token the way a lowercase does (S3Storage ->
            // S3_Storage).
            $name = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $name);
            $name = preg_replace('/([a-z\d])([A-Z])/', '$1_$2', $name);

            $slug = strtolower($name);
        }

        $this->slugCache[$class] = $slug;
        return $slug;
    }

    /**
     * The name a service DECLARED, or '' when it declared none.
     *
     * The distinction getServiceMetadata() cannot make: its `name` default is
     * always populated, so `!empty($metadata['name'])` is true for every
     * service in the package and says nothing about intent. This asks the
     * class itself, and only a non-empty string counts.
     *
     * @param string $class Class name
     * @return string Declared name, trimmed, or ''
     */
    private function declaredServiceName(string $class): string
    {
        $declared = $this->declaredMetadata($class);

        if ($declared === null || !isset($declared['name']) || !is_string($declared['name'])) {
            return '';
        }

        return trim($declared['name']);
    }

    /**
     * Register config filter for a service (lazy loading)
     * PERFORMANCE: Only registers filter when service actually boots
     *
     * @param string $class Service class name
     * @return void
     */
    private function registerServiceConfigFilter(string $class): void
    {
        $slug = $this->getServiceSlug($class);

        // Skip if no config override exists for this service
        if (!isset($this->serviceConfigCache[$slug])) {
            return;
        }

        $serviceConfig = $this->serviceConfigCache[$slug];

        // Register the filter with cached config (no closure over $this->config)
        add_filter("ntdst/service/{$slug}/config", function ($defaults) use ($serviceConfig) {
            return array_merge($defaults, $serviceConfig);
        }, 1);
    }

    /**
     * Get configuration value
     *
     * @param string|null $key Configuration key (dot notation supported)
     * @param mixed $default Default value
     * @return mixed
     */
    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->config;
        }

        // Support dot notation: 'services.overrides.barba.animationDuration'
        // array_key_exists so a literal null value round-trips through config().
        $keys = explode('.', $key);
        $value = $this->config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }
}
