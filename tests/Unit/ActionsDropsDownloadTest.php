<?php // tests/Unit/ActionsDropsDownloadTest.php
// Class D — the download dispatch surface is removed.
//
// It had ZERO consumers: no `add_filter('ntdst/api_download/…')` registration
// exists on any site, on the package stack or the legacy one. The fleet's only
// large download — daan's press kit ZIP — borrows
// NTDST_Response::downloadHeaders() and emits its own bytes, which is what that
// method exists for. An abstraction whose consumers have gone is not neutral.
//
// These assertions fail against the version that still carries the surface.
defined('ABSPATH') || exit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Response.php';
require_once __DIR__ . '/../../api/Actions.php';

final class ActionsDropsDownloadTest extends TestCase
{
    /** The two public entry points are gone. */
    public function testTheDownloadEntryPointsDoNotExist(): void
    {
        foreach (['handle_download', 'check_download_permission'] as $method) {
            $this->assertFalse(
                method_exists(NTDST_Actions::class, $method),
                sprintf('%s() dispatches a surface nothing registers against.', $method),
            );
        }
    }

    /** No dispatch filter, no route, no constant — in code, not merely in comments. */
    public function testNoDownloadDispatchIdentifierRemains(): void
    {
        $code = self::codeWithoutComments(__DIR__ . '/../../api/Actions.php');

        $this->assertStringNotContainsString('api_download', $code);
        $this->assertStringNotContainsString('DOWNLOAD_FILTER', $code);
        $this->assertStringNotContainsString("'/download'", $code);
    }

    /**
     * T2 — the Origin/CSRF check must not be collapsed away with the flag that
     * let `/download` opt out of it. `/action` is the only door left and it
     * verifies Origin unconditionally.
     */
    public function testTheOriginCheckSurvivesTheFlagItShared(): void
    {
        $code = self::codeWithoutComments(__DIR__ . '/../../api/Actions.php');

        $this->assertStringContainsString('verifyOrigin()', $code, 'the CSRF gate must still be called');
        $this->assertStringNotContainsString('$verifyOrigin', $code, 'the opt-out flag has one caller left; it goes');
    }

    private static function codeWithoutComments(string $path): string
    {
        return implode('', array_map(
            static fn($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)
                ? ''
                : (is_array($t) ? $t[1] : $t),
            token_get_all((string) file_get_contents($path)),
        ));
    }
}
