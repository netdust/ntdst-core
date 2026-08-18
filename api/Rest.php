<?php // api/Rest.php
// SIGNATURE SHELL ONLY — written by the test-author so the split RED in
// tests/Unit/NtdstRestTest.php fails BEHAVIOURALLY instead of taking the whole
// Unit suite down with a "failed opening required" fatal (Cluster A runs in
// parallel and needs `composer gate` to stay runnable).
//
// There is deliberately NO LOGIC here: no registration, no permission gate, no
// memoization, no queue. T03 and T04 fill this in. Every behavioural assertion
// in NtdstRestTest is RED against this file.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit

final class NTDST_Rest
{
    public function __construct(private string $namespace) {}

    public function get(string $route, $handler, array $options = []): self
    {
        return $this; // T03
    }

    public function post(string $route, $handler, array $options = []): self
    {
        return $this; // T03
    }

    public function put(string $route, $handler, array $options = []): self
    {
        return $this; // T03
    }

    public function patch(string $route, $handler, array $options = []): self
    {
        return $this; // T03
    }

    public function delete(string $route, $handler, array $options = []): self
    {
        return $this; // T03
    }
}

/**
 * Global helper — the resource router, one wrapper per namespace.
 */
if (!function_exists('ntdst_rest')) {
    function ntdst_rest(string $namespace): NTDST_Rest
    {
        return new NTDST_Rest($namespace); // T03: one CACHED instance per namespace
    }
}
