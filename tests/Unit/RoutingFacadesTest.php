<?php // tests/Unit/RoutingFacadesTest.php
// Cluster A behaviour RED — the package exposes ntdst_pages(), and no longer
// exposes the v2 facades or the v3 command facade.
//
// v3.0.0 was a CLEAN BREAK and 5.0.0 is another: no aliases, no class_alias, no
// deprecation forwarders (FR-6, FR-7). An adopter that still calls a retired
// facade must fail loudly at the call site rather than silently ride a shim,
// which is exactly why this asserts absence rather than behaviour.
//
// `ntdst_actions()` sits in the SAME list as the v2 facades now, and the flip
// is the point: this file used to assert it existed. 5.0.0 deleted the command
// dispatcher — a command is a `ntdst_rest()->post()` route — so the facade that
// v3 introduced is a retired name like the two it replaced.
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
            'ntdst_actions' => ['ntdst_actions'],
        ];
    }

    /**
     * @dataProvider removedFunctionProvider
     */
    public function testRemovedFacadesAreGone(string $fn): void
    {
        $this->assertFalse(
            function_exists($fn),
            "{$fn}() is a retired facade — no aliases, no forwarders (FR-6, FR-7).",
        );
    }

    public function testRemovedClassIsGone(): void
    {
        $this->assertFalse(
            class_exists('NTDST_Router', false),
            'NTDST_Router is renamed to NTDST_Pages with no class_alias (FR-6).',
        );
        $this->assertFalse(
            class_exists('NTDST_Actions', false),
            'NTDST_Actions is DELETED in 5.0.0, not renamed — a command is an ntdst_rest() route (FR-7).',
        );
    }

    public function testPagesFacadeExistsAndIsASingleton(): void
    {
        $this->assertTrue(function_exists('ntdst_pages'), 'ntdst_pages() is the v3 page-routing facade.');
        $this->assertInstanceOf(NTDST_Pages::class, ntdst_pages());
        $this->assertSame(ntdst_pages(), ntdst_pages(), 'ntdst_pages() must return one shared instance.');
    }
}
