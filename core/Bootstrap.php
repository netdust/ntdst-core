<?php

declare(strict_types=1);

/**
 * NTDST Bootstrap
 *
 * Orchestrates service registration and initialization with clear lifecycle phases
 * Generic infrastructure - works with any theme configuration
 *
 * Lifecycle:
 * 1. Register    - Services added to DI container (immediate)
 * 2. Boot Core   - Critical services initialized (after_setup_theme:5)
 * 3. Boot Theme  - Theme setup (after_setup_theme:10)
 * 4. Boot Features - Remaining services initialized (after_setup_theme:15)
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
 * RETIRED NAMES. The config filter was spelled `netdust_{slug}_config` before
 * S9/T02, and carried the option prefix before 5.0.0 renamed it to the
 * `ntdst/*` convention above (core-trim FR-11). There is no shim: a listener on
 * either retired spelling is silently inert. The full mapping lands in
 * `docs/architecture/ntdst-core-migration-S9-S10.md` (S9/T13, not yet written);
 * until it exists, this paragraph is the record.
 *
 * THE ENABLE SWITCH IS GONE (5.0.0, core-trim FR-2). The per-service option and
 * the per-service DENY filter that sat beside this one are removed, and so is
 * the `isServiceEnabled()` helper that read them. That filter FAILED OPEN — a
 * filter nobody answers returns true — so a typo in the slug was a service the
 * site owner believed was off and was not, the wart `docs/philosophy.md` §4
 * records. A service is off in exactly two ways now: it declares
 * `metadata()['enabled'] => false`, or its `services.conditional` entry's
 * condition returns false. A site that kept a service off through the retired
 * option or the retired filter will find it booting.
 *
 * A service's config override is declared at
 * `config['services']['overrides'][$slug]`. It nests under the existing
 * `services` key rather than replacing it — `services.core`, `services.admin`
 * and `services.conditional` already live there.
 *
 * AN OVERRIDE KEY THAT ANSWERS TO NO SERVICE IS REFUSED (5.0.0, core-trim
 * FR-2) — once, at `register()`, with `_doing_it_wrong()` naming the full
 * dotted key, because that is the string the site owner greps their config for.
 * Until 5.0.0 it was silently inert, which is a config that lies to its reader.
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
 * FIXED 2026-08-20 (F7). Until this release that precedence was not real. The
 * slug resolver took an optional metadata argument, and the enable check ran
 * first WITHOUT it — deriving from the class name and caching that — so a
 * declared name reached the config filter only if nothing had warmed the cache
 * before it, which in the real registration flow was never. The slug is now a
 * pure function of the class, so the answer no longer depends on call order.
 * 5.0.0 deleted the enable check itself, which removes the collision at its
 * source; the purity stays, because getServiceMetadata() still runs first and
 * getServiceSlug() still caches.
 *
 * RETIRED DERIVATION (S9/T14, 2026-08). Before this fix a `_` was inserted
 * before EVERY internal capital, so consecutive capitals each got their own
 * separator: `AdminUIService -> admin_u_i`, `APIRouterService -> a_p_i_router`.
 * Every slug that contained no consecutive capitals is UNMOVED. There is no
 * shim: an override keyed on a mangled slug is inert — and since 5.0.0 it is
 * also LOUD, because a key no listed service answers to is refused at
 * register() instead of ignored. This was harmless internal plumbing until T02
 * promoted the slug into the user-facing extension key, at which point the
 * mangling became a broken public API: nobody guesses
 * `ntdst/service/admin_u_i/config` on the first try.
 *
 * Boot order with equal priority is best-effort (PHP's uasort isn't stable).
 * If two services depend on each other, set their priorities — never rely on
 * registration order.
 */
class NTDST_Bootstrap
{
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

        // The consumer lists services under three keys, and core registers
        // exactly what it finds there. Nothing else in the `services` array is
        // read: a key core consults and then ignores is a promise read back as
        // a maybe (INV-10).

        // Register explicitly configured core services
        foreach ($this->config['services']['core'] ?? [] as $service) {
            $this->registerService($service, 'core');
        }

        // Register admin services (conditionally)
        if (is_admin()) {
            foreach ($this->config['services']['admin'] ?? [] as $service) {
                $this->registerService($service, 'admin');
            }
        }

        // Register conditional services
        foreach ($this->config['services']['conditional'] ?? [] as $key => $spec) {
            if (isset($spec['condition']) && is_callable($spec['condition']) && $spec['condition']()) {
                $this->registerService($spec['service'], 'conditional');
            }
        }

        // An overrides key that answers to no LISTED service is refused here,
        // inside the `servicesRegistered` latch, so one typo is one notice
        // however many times register() is called (AF-14). A notice that
        // repeats turns a config typo into a log flood, and with
        // WP_DEBUG_DISPLAY on, into repeated output on the page.
        $this->refuseOverridesThatNameNoService();

        // Always log registration summary
        ntdst_log()->debug('NTDST Bootstrap: Registered ' . count($this->services) . ' services');

        $this->servicesRegistered = true;

        do_action('ntdst/services_registered', $this);

        return $this;
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

        // `class_exists()` is the whole admission test, and its autoload pass
        // is the consumer's loader, never one core installed.
        if (!class_exists($class)) {
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

        // PERFORMANCE: Pre-compute slug and cache the service's config override
        // This replaces the per-service filter with direct config lookup.
        // Overrides nest UNDER the existing `services` key (beside `core`,
        // `admin` and `conditional`) rather than occupying it, which is
        // already taken.
        $slug = $this->getServiceSlug($class);
        if (isset($this->config['services']['overrides'][$slug])) {
            $this->serviceConfigCache[$slug] = $this->config['services']['overrides'][$slug];
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
     * Refuse every `services.overrides` key that no LISTED service answers to.
     *
     * The consumer's half of the rename risk. `services.overrides.securty` used
     * to reach nothing and say nothing: the site owner edited a config,
     * reloaded, saw no change and had nothing to grep for. The notice carries
     * the FULL dotted key for exactly that reason.
     *
     * LISTED, not REGISTERED — see the class docblock. The set below is every
     * class the consumer named in the three sector keys, whatever this request
     * did with it. A check written against `$this->services` instead would
     * refuse an admin service's override on every anonymous page view.
     *
     * @return void
     */
    private function refuseOverridesThatNameNoService(): void
    {
        $overrides = $this->config['services']['overrides'] ?? [];

        if (!is_array($overrides) || $overrides === []) {
            return;
        }

        $listed = [];
        foreach ($this->listedServiceClasses() as $class) {
            $listed[$this->getServiceSlug($class)] = true;
        }

        foreach (array_keys($overrides) as $key) {
            $key = (string) $key;

            if (isset($listed[$key])) {
                continue;
            }

            _doing_it_wrong(
                __CLASS__ . '::register',
                "Config key services.overrides.{$key} matches no registered service — no class listed in "
                    . "services.core, services.admin or services.conditional answers to the slug \"{$key}\". "
                    . 'Fix the key or drop it: an override nothing reads is a setting that lies to its reader.',
                '5.0.0',
            );
        }
    }

    /**
     * Every class the consumer LISTED, across all three sector keys.
     *
     * Deliberately blind to `is_admin()`, to a conditional's condition and to a
     * class's own `enabled` declaration: this answers "did the consumer name
     * this service at all", which is the only question a dead override key is
     * asking. Non-string entries are dropped rather than refused — a malformed
     * services list is registerService()'s notice to give, not this one's.
     *
     * @return list<string>
     */
    private function listedServiceClasses(): array
    {
        $services = $this->config['services'] ?? [];

        $classes = array_merge(
            array_values((array) ($services['core'] ?? [])),
            array_values((array) ($services['admin'] ?? [])),
        );

        foreach ((array) ($services['conditional'] ?? []) as $spec) {
            if (is_array($spec) && isset($spec['service'])) {
                $classes[] = $spec['service'];
            }
        }

        return array_values(array_filter($classes, 'is_string'));
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

        // Check if service implements metadata interface
        if (method_exists($class, 'metadata')) {
            return array_merge($defaults, $class::metadata());
        }

        return $defaults;
    }

    /**
     * Get human-readable service name from class name
     *
     * @param string $class Class name
     * @return string Service name
     */
    private function getServiceName(string $class): string
    {
        $name = basename(str_replace('\\', '/', $class));
        $name = str_replace('Service', '', $name);
        return ucwords(preg_replace('/(?<!^)[A-Z]/', ' $0', $name));
    }

    /**
     * The slug for a service — a PURE FUNCTION OF THE CLASS, cached.
     *
     * It takes no metadata argument, and that is the fix for F7. It used to,
     * and the answer therefore depended on WHICH question a caller asked
     * first: the enable check (deleted in 5.0.0) resolved the slug with no
     * metadata, cached the class-name derivation, and every later
     * metadata-aware call was served that cached answer. A service could
     * declare `name` and watch its config filter, its DENY filter and its
     * option all keep answering to a name it never chose. A consumer wrote a
     * five-line comment about the surprise rather than a `name`. A slug that
     * depends on call order cannot be pinned by anything, so the argument is
     * gone and the declaration is read here.
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
            $name = basename(str_replace('\\', '/', $class));
            $name = str_replace('Service', '', $name);

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
        if (!method_exists($class, 'metadata')) {
            return '';
        }

        $declared = $class::metadata();

        if (!is_array($declared) || !isset($declared['name']) || !is_string($declared['name'])) {
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
