<?php // tests/Unit/LintTriageOutputTest.php
// A caller's argument reaches an exception message escaped (core-lint-gate
// review, Should fix 3; Stefan 2026-11-06). The query builder's scope() and
// whereGroup() name the value they refused, and a consumer may forward a
// request value into either; an uncaught exception is shown by display_errors.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

final class LintTriageOutputTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const HOSTILE = '<script>x</script>';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\when('esc_html')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('wp_parse_args')->alias(
            static fn($args, $defaults = []) => array_merge((array) $defaults, (array) $args),
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testAnUnknownScopeIsNamedEscaped(): void
    {
        $message = $this->messageOf(fn() => $this->model()->scope(self::HOSTILE));

        $this->assertStringNotContainsString('<script>', $message);
        $this->assertStringContainsString("Unknown query scope '&lt;script&gt;x&lt;/script&gt;'", $message);
    }

    public function testAnUnknownGroupRelationIsNamedEscaped(): void
    {
        $message = $this->messageOf(
            fn() => $this->model()->whereGroup(self::HOSTILE, static function (NTDST_Data_Model $g): void {}),
        );

        $this->assertStringNotContainsString('<SCRIPT>', $message);
        $this->assertStringContainsString("'&lt;SCRIPT&gt;X&lt;/SCRIPT&gt;' given", $message);
    }

    private function messageOf(callable $call): string
    {
        try {
            $call();
        } catch (InvalidArgumentException $e) {
            return $e->getMessage();
        }

        $this->fail('The builder must refuse the value.');
    }

    private function model(): NTDST_Data_Model
    {
        return new NTDST_Data_Model('probe', ['status' => ['type' => 'text']], '_probe_');
    }
}
