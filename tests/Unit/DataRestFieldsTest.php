<?php // tests/Unit/DataRestFieldsTest.php
// A field leaves only if it says so, using WordPress's own key and default.
//
// This is a read of the FIELD DESCRIPTION — the same family as getSchema() and
// getMetaPrefix(). It reports what the schema declares. It does not narrow rows
// and it does not decide what an exposure emits.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Data.php';

final class DataRestFieldsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        foreach (['sanitize_text_field', 'sanitize_textarea_field', 'esc_url_raw', 'sanitize_email', 'wp_kses_post'] as $fn) {
            Functions\when($fn)->returnArg(1);
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed> $fields */
    private function model(array $fields): NTDST_Data_Model
    {
        return new NTDST_Data_Model('probe', $fields, '_probe_');
    }

    /** WordPress's default, kept: a field nobody named does not leave. */
    public function testAnUnmarkedFieldDoesNotLeave(): void
    {
        $model = $this->model([
            'venue' => ['type' => 'text', 'show_in_rest' => true],
            'promo_budget' => ['type' => 'float'],
        ]);

        $this->assertSame(['venue'], $model->restFields());
    }

    public function testShowInRestFalseIsAsAbsentAsSayingNothing(): void
    {
        $model = $this->model([
            'venue' => ['type' => 'text', 'show_in_rest' => true],
            'cost' => ['type' => 'float', 'show_in_rest' => false],
        ]);

        $this->assertSame(['venue'], $model->restFields());
    }

    public function testNothingLeavesAModelThatNamedNothing(): void
    {
        $model = $this->model(['venue' => ['type' => 'text'], 'cost' => ['type' => 'float']]);

        $this->assertSame([], $model->restFields());
    }

    /**
     * A repeater is ONE declared field. restFields() reports the names a module
     * declared, so a repeater appears once under its own name and its
     * sub-fields are not names of their own — whether or not they said
     * `show_in_rest => true`.
     *
     * The second read one level down (restSubFields()) is deleted by
     * field-types FR-4: it had zero shipped readers, and while it existed it
     * was a second way to ask what a field publishes, beside the one
     * convergence point (INV-1).
     */
    public function testADeclaredRepeaterIsOneNameAndItsSubFieldsAreNotNames(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'show_in_rest' => true,
                'sub_fields' => [
                    'year' => ['type' => 'text', 'show_in_rest' => true],
                    'sale_price' => ['type' => 'float'],
                ],
            ],
        ]);

        $this->assertSame(['provenance'], $model->restFields());
    }

    /** An unnamed parent takes its children with it, however they are marked. */
    public function testAnUnnamedRepeaterDoesNotLeaveEvenWhenASubFieldNamedItself(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'sub_fields' => ['year' => ['type' => 'text', 'show_in_rest' => true]],
            ],
        ]);

        $this->assertSame([], $model->restFields());
    }
}
