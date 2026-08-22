<?php // tests/Unit/SignedIntFieldTest.php
// An int keeps its sign — under the CANONICAL name.
//
// The promise this file has always made is FR-5's: a price modifier in cents is
// a negative number, and absint() (the original Stride 744b5b05 bug) strips the
// sign on the way in. v5.0.0 keeps the promise and retires the name that carried
// it: `signed_int` folds into a signed `int` (spec D4), so these two cases are
// re-pointed at `int` and a third case pins the retirement itself.
//
// The file name stays: it is the name of the BUG this test exists to catch.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Test-only subclass exposing NTDST_Data_Model's PROTECTED sanitizeField()
 * and formatMeta() through public wrappers, so this test can exercise the
 * real sanitizer/formatter arms without adding test-only public methods to
 * production code (api/Data.php).
 */
final class SignedIntFieldTestModel extends NTDST_Data_Model
{
    public function sanitizePublic(string $field, $value)
    {
        return $this->sanitizeField($field, $value);
    }

    public function formatPublic(array $meta): array
    {
        return $this->formatMeta($meta);
    }
}

final class SignedIntFieldTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Real-equivalents. absint() is here because it is what the model binds
        // TODAY for `int` — without it the RED below would be an
        // undefined-function error instead of the wrong ANSWER, and a sign that
        // gets stripped is a wrong answer.
        Functions\when('absint')->alias(static fn($value) => abs((int) $value));
        Functions\when('sanitize_text_field')->alias(static fn($value) => trim(strip_tags((string) $value)));
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * FR-5: `int` is signed. The same promise `signed_int` used to carry, under
     * the one canonical name — and the same four answers, including the two
     * that are not numbers at all.
     */
    public function test_int_keeps_its_sign(): void
    {
        $model = new SignedIntFieldTestModel(
            'vad_session',
            ['price_modifier' => 'int'],
            '_ntdst_',
        );

        $this->assertSame(-250, $model->sanitizePublic('price_modifier', -250));
        $this->assertSame(-250, $model->sanitizePublic('price_modifier', '-250'));
        $this->assertSame(0, $model->sanitizePublic('price_modifier', 'abc'));
        $this->assertSame(0, $model->sanitizePublic('price_modifier', ['x']));
    }

    /**
     * The read side owes the same answer: a value sanitised as a signed int must
     * read back as a negative int, not as a string and not as its absolute value.
     */
    public function test_formatted_read_returns_int_as_signed_int(): void
    {
        $model = new SignedIntFieldTestModel(
            'vad_session',
            ['price_modifier' => 'int'],
            '_ntdst_',
        );

        $out = $model->formatPublic(['_ntdst_price_modifier' => '-250']);

        $this->assertSame(-250, $out['price_modifier']);
    }

    /**
     * D3/D4: no aliases. `signed_int` is not a name any more — the one stride
     * field that used it renames, and a site that did not rename learns at init
     * which word to write instead (threat row #7).
     */
    public function test_signed_int_is_no_longer_a_name(): void
    {
        try {
            new SignedIntFieldTestModel(
                'vad_session',
                ['price_modifier' => 'signed_int'],
                '_ntdst_',
            );
            $this->fail("Expected InvalidArgumentException for the retired type 'signed_int'.");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString("Field 'price_modifier'", $e->getMessage());
            $this->assertStringContainsString("Use 'int'.", $e->getMessage());
        }
    }

    /**
     * The permanent proof of the denial path: a genuinely unknown type still
     * fails loudly, naming the field.
     */
    public function test_a_truly_unknown_type_still_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/Unknown field type 'not_a_real_type'/");

        new SignedIntFieldTestModel(
            'vad_session',
            ['price_modifier' => 'not_a_real_type'],
            '_ntdst_',
        );
    }
}
