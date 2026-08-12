<?php

declare(strict_types=1);

/**
 * NTDST Dependency Injection Container
 * Minimal, chainable, fast - inspired by Simple DIC
 * WordPress-native with zero dependencies
 *
 * Usage Examples:
 *
 * // Register primitive values
 * ntdst_set('api_key', 'abc123');
 * ntdst_set('posts_limit', 10);
 *
 * // Register classes (auto-resolved)
 * ntdst_set(PaymentGateway::class);
 *
 * // Register with factory
 * ntdst_set(Logger::class, function($c) {
 *     return new Logger($c->get('log_path'));
 * });
 *
 * // Get as singleton (cached)
 * $gateway = ntdst_get(PaymentGateway::class);
 * $gateway2 = ntdst_get(PaymentGateway::class); // Same instance
 *
 * // Make fresh instance (not cached)
 * $logger1 = ntdst_make(Logger::class);
 * $logger2 = ntdst_make(Logger::class); // Different instance
 *
 * // Autowiring (dependencies auto-resolved)
 * class OrderService {
 *     public function __construct(PaymentGateway $gateway, Logger $logger) {}
 * }
 * $orders = ntdst_get(OrderService::class); // Dependencies injected!
 *
 * // Method injection
 * ntdst_container()->call([$service, 'method'], ['param' => 'value']);
 */

defined('ABSPATH') || exit;

/**
 * Conventions:
 * - Register bindings before the `ntdst/features_ready` hook. After that, only
 *   mutate the container from tests.
 * - Rebinding an ID does NOT invalidate consumers that already resolved it.
 *   If a test needs fresh resolution after rebinding, call flush().
 */
class NTDST_Container
{
    protected array $services = [];
    protected array $resolved = [];
    protected array $reflections = [];

    /**
     * PERFORMANCE: Cache for method/function reflections
     * Uses object hash for closures, string key for methods
     */
    protected array $callableReflections = [];

    /**
     * PERFORMANCE: Cache for factory reflection results
     * Stores whether factory expects container injection
     */
    protected array $factoryCache = [];

    /**
     * Tracks IDs currently being resolved to detect circular dependencies.
     */
    protected array $resolving = [];

    public function __construct()
    {
        // Auto-register container itself
        $this->services[self::class] = $this;
        $this->resolved[self::class] = $this;
    }

    /**
     * Register a service (value, class, or factory)
     *
     * Passing no second argument means "register the ID as its own class".
     * Passing an explicit null stores null as the value.
     */
    public function set(string $id, mixed $value = null): self
    {
        $this->services[$id] = (func_num_args() < 2) ? $id : $value;
        unset($this->resolved[$id]); // Clear cache
        return $this;
    }

    /**
     * Get service as singleton (cached after first call)
     */
    public function get(string $id): mixed
    {
        // Return cached if exists. array_key_exists so a resolved null
        // value still hits the cache.
        if (array_key_exists($id, $this->resolved)) {
            return $this->resolved[$id];
        }

        // Resolve and cache
        $resolved = $this->resolve($id);
        $this->resolved[$id] = $resolved;

        return $resolved;
    }

    /**
     * Create fresh instance (autowired, not cached)
     *
     * Keys in $params must match constructor parameter names. Unknown keys
     * throw so typos surface immediately rather than silently autowiring.
     */
    public function make(string $id, array $params = []): object
    {
        if (!class_exists($id)) {
            throw new RuntimeException("Class {$id} does not exist");
        }

        if ($params) {
            $constructor = $this->getReflection($id)->getConstructor();
            $known = $constructor
                ? array_map(fn($p) => $p->getName(), $constructor->getParameters())
                : [];
            $unknown = array_diff(array_keys($params), $known);
            if ($unknown) {
                throw new RuntimeException(
                    "Unknown parameter(s) for {$id}: " . implode(', ', $unknown),
                );
            }
        }

        return $this->resolveClass($id, $params);
    }

    /**
     * Check if get($id) will resolve without throwing (PSR-11 semantics).
     *
     * Returns true for registered IDs AND for unregistered classes that exist
     * and can be autowired.
     */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->services)
            || array_key_exists($id, $this->resolved)
            || class_exists($id);
    }

    /**
     * Resolve a service
     */
    protected function resolve(string $id): mixed
    {
        if (isset($this->resolving[$id])) {
            $chain = implode(' -> ', array_keys($this->resolving)) . " -> {$id}";
            throw new RuntimeException("Circular dependency detected: {$chain}");
        }

        $this->resolving[$id] = true;
        try {
            // If not registered, try to resolve as class. array_key_exists so a
            // registered null value isn't treated as "not registered".
            if (!array_key_exists($id, $this->services)) {
                if (class_exists($id)) {
                    return $this->resolveClass($id);
                }
                throw new RuntimeException("Service {$id} not found");
            }

            $service = $this->services[$id];

            // If closure (factory), execute it
            if ($service instanceof Closure) {
                return $this->resolveFactory($service);
            }

            // If string: resolve as class. Distinguish "non-class string value"
            // from "typo'd class binding" so misconfigured bindings fail loud.
            if (is_string($service) && $service !== $id && $this->looksLikeClassName($service)) {
                if (!class_exists($service)) {
                    throw new RuntimeException("Binding {$id} points to non-existent class {$service}");
                }
                return $this->get($service);
            }

            if (is_string($service) && class_exists($service)) {
                return $service === $id ? $this->resolveClass($service) : $this->get($service);
            }

            // Return as-is (primitive value)
            return $service;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Heuristic: does this string look like it's meant to be a class name?
     * Used so that primitive string values aren't mistaken for typo'd bindings.
     */
    protected function looksLikeClassName(string $value): bool
    {
        return str_contains($value, '\\') || (isset($value[0]) && ctype_upper($value[0]));
    }

    /**
     * Resolve factory closure
     * PERFORMANCE: Caches reflection analysis to avoid repeated introspection
     *
     * If the factory's first parameter is untyped or typed as the container,
     * the container is passed. Otherwise the factory is called with no args.
     *   - function (NTDST_Container $c) { ... }   // container passed
     *   - function ($c) { ... }                   // container passed
     *   - function (int $count = 5) { ... }       // called with no args
     */
    protected function resolveFactory(Closure $factory): mixed
    {
        // PERFORMANCE: Use object hash as cache key for closures
        $hash = spl_object_hash($factory);

        if (!isset($this->factoryCache[$hash])) {
            $reflection = new ReflectionFunction($factory);
            $params = $reflection->getParameters();

            $passContainer = false;
            if (isset($params[0])) {
                $type = $params[0]->getType();
                $passContainer = $type === null
                    || (!$type->isBuiltin() && is_a(self::class, $type->getName(), true));
            }

            $this->factoryCache[$hash] = $passContainer;
        }

        // Use cached result
        return $this->factoryCache[$hash] ? $factory($this) : $factory();
    }

    /**
     * Resolve class with autowiring
     */
    protected function resolveClass(string $class, array $params = []): object
    {
        $reflection = $this->getReflection($class);

        if (!$reflection->isInstantiable()) {
            throw new RuntimeException("Class {$class} is not instantiable");
        }

        $constructor = $reflection->getConstructor();

        // No constructor - simple instantiation
        if ($constructor === null) {
            return new $class();
        }

        // Resolve constructor dependencies
        $dependencies = $this->resolveParameters($constructor->getParameters(), $params);

        return $reflection->newInstanceArgs($dependencies);
    }

    /**
     * Resolve constructor/method parameters
     */
    protected function resolveParameters(array $parameters, array $primitives = []): array
    {
        $dependencies = [];

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            // Use provided primitive parameter
            if (array_key_exists($name, $primitives)) {
                $dependencies[] = $primitives[$name];
                continue;
            }

            // Resolve type-hinted dependency
            if ($type && !$type->isBuiltin()) {
                $className = $type->getName();
                $dependencies[] = $this->get($className);
                continue;
            }

            // Use default value if available
            if ($parameter->isDefaultValueAvailable()) {
                $dependencies[] = $parameter->getDefaultValue();
                continue;
            }

            // Optional parameter
            if ($parameter->allowsNull()) {
                $dependencies[] = null;
                continue;
            }

            $where = $parameter->getDeclaringClass()?->getName() ?? '<global>';
            $typeLabel = $type ? (string) $type : 'untyped';
            throw new RuntimeException("Cannot resolve parameter: {$name} ({$typeLabel}) in {$where}");
        }

        return $dependencies;
    }

    /**
     * Get cached reflection or create new
     */
    protected function getReflection(string $class): ReflectionClass
    {
        if (!isset($this->reflections[$class])) {
            $this->reflections[$class] = new ReflectionClass($class);
        }

        return $this->reflections[$class];
    }

    /**
     * Call method/function with dependency injection
     * PERFORMANCE: Caches reflection for repeated calls to same callable
     */
    public function call(callable $callback, array $params = []): mixed
    {
        // Generate cache key based on callable type
        if (is_array($callback)) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            $cacheKey = $class . '::' . $callback[1];
        } elseif ($callback instanceof Closure) {
            $cacheKey = spl_object_hash($callback);
        } elseif (is_string($callback)) {
            $cacheKey = $callback;
        } else {
            // For other callables, skip caching
            $cacheKey = null;
        }

        // PERFORMANCE: Use cached reflection if available
        if ($cacheKey !== null && isset($this->callableReflections[$cacheKey])) {
            $reflection = $this->callableReflections[$cacheKey];
        } else {
            if (is_array($callback)) {
                $reflection = new ReflectionMethod($callback[0], $callback[1]);
            } else {
                $reflection = new ReflectionFunction($callback);
            }

            // Cache for future calls
            if ($cacheKey !== null) {
                $this->callableReflections[$cacheKey] = $reflection;
            }
        }

        $dependencies = $this->resolveParameters($reflection->getParameters(), $params);

        return $callback(...$dependencies);
    }

    /**
     * Remove service from container
     */
    public function forget(string $id): self
    {
        unset($this->services[$id], $this->resolved[$id], $this->reflections[$id]);
        return $this;
    }

    /**
     * Clear all services (except container itself)
     */
    public function flush(): self
    {
        $container = $this->services[self::class];

        $this->services = [self::class => $container];
        $this->resolved = [self::class => $container];
        $this->reflections = [];
        $this->callableReflections = [];
        $this->factoryCache = [];

        return $this;
    }

    /**
     * Get all registered service IDs
     */
    public function keys(): array
    {
        return array_keys($this->services);
    }
}

/**
 * Global helper - get container instance (singleton)
 */
if (!function_exists('ntdst_container')) {
    function ntdst_container(): NTDST_Container
    {
        static $container = null;
        return $container ??= new NTDST_Container();
    }
}

/**
 * Quick register helper.
 *
 * Mirrors NTDST_Container::set() — passing one arg means "register the ID as
 * its own class"; passing two args (even null) stores the second argument.
 */
if (!function_exists('ntdst_set')) {
    function ntdst_set(string $id, mixed $value = null): NTDST_Container
    {
        return func_num_args() < 2
            ? ntdst_container()->set($id)
            : ntdst_container()->set($id, $value);
    }
}

/**
 * Quick get helper (singleton)
 */
if (!function_exists('ntdst_get')) {
    function ntdst_get(string $id): mixed
    {
        return ntdst_container()->get($id);
    }
}

/**
 * Quick make helper (fresh instance)
 */
if (!function_exists('ntdst_make')) {
    function ntdst_make(string $id, array $params = []): object
    {
        return ntdst_container()->make($id, $params);
    }
}
