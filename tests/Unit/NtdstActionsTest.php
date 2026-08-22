<?php // tests/Unit/NtdstActionsTest.php
// T02 — NTDST_Endpoints becomes NTDST_Actions, and ntdst_api_action() becomes
// ntdst_actions()->register().
//
// This is the COMMAND service: one dispatch endpoint (/ntdst/v1/action), a
// nonce, and a capability floor. It is renamed, not redesigned — the
// `ntdst/api_data/*` FILTER NAME is deliberately unchanged, because adopters'
// handlers hang off it and a rename would silently unmount every one of them.
// (`ntdst/api_download/*` was the sibling dispatch filter; that surface had no
// consumers on any site and was removed.)
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; the bootstrap defines it under phpunit

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

// add_filter is defined once in tests/bootstrap.php and RECORDS what was
// mounted — see the note there for why it cannot be a Brain Monkey stub.
require_once __DIR__ . '/../../api/Response.php';
require_once __DIR__ . '/../../api/Actions.php';

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $msg = '', private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->msg; }
        public function get_error_data(): mixed { return $this->data; }
    }
}

final class NtdstActionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        Functions\when('__')->returnArg(1);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testRegisterMountsTheUnchangedApiDataFilterName(): void
    {
        $GLOBALS['_ntdst_test_filters'] = [];

        ntdst_actions()->register('x', static fn() => ['ok' => true], ['capability' => 'manage_options']);

        $this->assertArrayHasKey(
            'ntdst/api_data/x',
            $GLOBALS['_ntdst_test_filters'],
            'The filter NAME must stay ntdst/api_data/{action} — adopters hang handlers off it.',
        );
    }

    public function testRegisterFloorsOnTheDeclaredCapability(): void
    {
        $GLOBALS['_ntdst_test_filters'] = [];
        Functions\when('current_user_can')->justReturn(false);

        $ran = 0;
        ntdst_actions()->register('y', static function () use (&$ran) {
            $ran++;
            return ['ok' => true];
        }, ['capability' => 'manage_options']);

        $result = ($GLOBALS['_ntdst_test_filters']['ntdst/api_data/y'])([], []);

        $this->assertInstanceOf(WP_Error::class, $result, 'A caller lacking the capability must be denied.');
        $this->assertSame(0, $ran, 'The handler must not run when the capability floor denies.');
    }

    public function testActionsFacadeIsASingleton(): void
    {
        $this->assertTrue(function_exists('ntdst_actions'), 'ntdst_actions() is the v3 command facade.');
        $this->assertInstanceOf(NTDST_Actions::class, ntdst_actions());
        $this->assertSame(ntdst_actions(), ntdst_actions());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function removedSymbolProvider(): array
    {
        return [
            'ntdst_api_action' => ['ntdst_api_action'],
            'ntdst_endpoints' => ['ntdst_endpoints'],
        ];
    }

    /**
     * @dataProvider removedSymbolProvider
     */
    public function testRemovedFacadesAreGone(string $fn): void
    {
        $this->assertFalse(
            function_exists($fn),
            "{$fn}() is removed in v3.0.0 — no aliases, no forwarders (FR-6).",
        );
    }

    public function testRemovedClassNamesAreGone(): void
    {
        $this->assertFalse(class_exists('NTDST_Endpoints', false), 'Renamed to NTDST_Actions, no class_alias (FR-6).');
        $this->assertFalse(class_exists('Endpoints', false), 'The unprefixed back-compat alias goes too — a clean break has one name.');
    }
}
