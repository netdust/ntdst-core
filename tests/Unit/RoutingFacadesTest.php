<?php // tests/Unit/RoutingFacadesTest.php
// Cluster A behaviour RED — the package exposes ntdst_pages() and ntdst_actions()
// and no longer exposes the v2 facades.
//
// v3.0.0 is a CLEAN BREAK: no aliases, no class_alias, no deprecation
// forwarders (FR-6). An adopter that still calls a v2 facade must fail loudly
// at the call site rather than silently ride a shim, which is exactly why this
// asserts absence rather than behaviour.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Pages.php';

final class RoutingFacadesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function removedFunctionProvider(): array
    {
        return [
            'ntdst_router' => ['ntdst_router'],
            'ntdst_route' => ['ntdst_route'],
        ];
    }

    /**
     * @dataProvider removedFunctionProvider
     */
    public function testRemovedFacadesAreGone(string $fn): void
    {
        $this->assertFalse(
            function_exists($fn),
            "{$fn}() is removed in v3.0.0 — no aliases, no forwarders (FR-6).",
        );
    }

    public function testRemovedClassIsGone(): void
    {
        $this->assertFalse(
            class_exists('NTDST_Router', false),
            'NTDST_Router is renamed to NTDST_Pages with no class_alias (FR-6).',
        );
    }

    public function testPagesFacadeExistsAndIsASingleton(): void
    {
        $this->assertTrue(function_exists('ntdst_pages'), 'ntdst_pages() is the v3 page-routing facade.');
        $this->assertInstanceOf(NTDST_Pages::class, ntdst_pages());
        $this->assertSame(ntdst_pages(), ntdst_pages(), 'ntdst_pages() must return one shared instance.');
    }
}
