<?php // tests/Unit/DataPublicFieldsTest.php
// Fields are public by default; `private => true` is the ceiling.
//
// The model does not decide what an exposure emits — it decides what may NEVER
// leave. A surface picks from what is left. That split is why `fields: all` can
// be safe: a field added next year is emitted, unless somebody marked it.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Data.php';

final class DataPublicFieldsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // The constructor wires a sanitizer per declared type, and those are
        // WordPress functions this suite does not load.
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

    public function testEveryFieldIsPublicUntilSomebodySaysOtherwise(): void
    {
        $model = $this->model([
            'title' => ['type' => 'text'],
            'venue' => ['type' => 'text'],
        ]);

        $this->assertSame(['title', 'venue'], $model->publicFields());
    }

    public function testAPrivateFieldIsNeverPublic(): void
    {
        $model = $this->model([
            'venue' => ['type' => 'text'],
            'supplier_cost' => ['type' => 'float', 'private' => true],
        ]);

        $this->assertSame(['venue'], $model->publicFields());
    }

    /** The trap a flat list cannot reach: sensitive data one level down. */
    public function testAPrivateSubFieldIsRemovedFromInsideItsParent(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'sub_fields' => [
                    'year' => ['type' => 'text'],
                    'sale_price' => ['type' => 'float', 'private' => true],
                ],
            ],
        ]);

        $this->assertSame(['provenance' => ['year']], $model->publicSubFields());
    }

    /** A parent marked private takes its children with it. */
    public function testAPrivateParentTakesItsSubFieldsWithIt(): void
    {
        $model = $this->model([
            'provenance' => [
                'type' => 'repeater',
                'private' => true,
                'sub_fields' => ['year' => ['type' => 'text']],
            ],
        ]);

        $this->assertSame([], $model->publicFields());
        $this->assertSame([], $model->publicSubFields());
    }

    public function testProjectKeepsTheDeclaredOrderNotTheRowsOrder(): void
    {
        $rows = [['venue' => 'X', 'id' => 1, 'title' => 'T']];

        $this->assertSame(
            [['id' => 1, 'title' => 'T', 'venue' => 'X']],
            NTDST_Data_Model::project($rows, ['id', 'title', 'venue']),
        );
    }

    public function testProjectOmitsAKeyTheRowDoesNotCarry(): void
    {
        $this->assertSame(
            [['id' => 1]],
            NTDST_Data_Model::project([['id' => 1]], ['id', 'absent']),
        );
    }

    /**
     * The fail-open this replaces. `publicRows()` returned every field when a
     * model declared no shape, so forgetting the declaration published the row.
     * An empty projection is a programming error and yields nothing.
     */
    public function testProjectingThroughNoKeysYieldsNothingRatherThanEverything(): void
    {
        $this->assertSame([[]], NTDST_Data_Model::project([['id' => 1, 'secret' => 'x']], []));
    }

    /** Nested values are projected too, or the ceiling stops at the top level. */
    public function testProjectNarrowsInsideARepeater(): void
    {
        $rows = [[
            'id' => 1,
            'meta' => ['provenance' => [['year' => '2024', 'sale_price' => 9000]]],
        ]];

        $this->assertSame(
            [['id' => 1, 'meta' => ['provenance' => [['year' => '2024']]]]],
            NTDST_Data_Model::project($rows, ['id', 'meta'], ['provenance' => ['year']]),
        );
    }
}
