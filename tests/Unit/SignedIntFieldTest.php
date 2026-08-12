<?php // tests/Unit/SignedIntFieldTest.php

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
    /**
     * RED before the fix: getDefaultSanitizer() has no 'signed_int' arm, so
     * setupSanitizers() — called from the constructor itself — throws
     * InvalidArgumentException("Unknown field type 'signed_int'") before this
     * test body ever reaches an assertion. That uncaught throw during
     * construction IS the watched RED.
     *
     * GREEN after the fix: construction succeeds and the sanitizer keeps the
     * sign — absint() (the original Stride 744b5b05 bug) would have stripped
     * it.
     */
    public function test_signed_int_is_a_known_type_and_keeps_its_sign(): void
    {
        $model = new SignedIntFieldTestModel(
            'vad_session',
            ['price_modifier' => 'signed_int'],
            '_ntdst_',
        );

        $this->assertSame(-250, $model->sanitizePublic('price_modifier', -250));
        $this->assertSame(-250, $model->sanitizePublic('price_modifier', '-250'));
        $this->assertSame(0, $model->sanitizePublic('price_modifier', 'abc'));
        $this->assertSame(0, $model->sanitizePublic('price_modifier', ['x']));
    }

    /**
     * Same RED source as above (construction throws before the fix). GREEN
     * proves the read side (formatMeta()) got the matching arm — a value
     * sanitised as signed_int must also read back as int, not string.
     */
    public function test_formatted_read_returns_signed_int_as_int(): void
    {
        $model = new SignedIntFieldTestModel(
            'vad_session',
            ['price_modifier' => 'signed_int'],
            '_ntdst_',
        );

        $out = $model->formatPublic(['_ntdst_price_modifier' => '-250']);

        $this->assertSame(-250, $out['price_modifier']);
    }

    /**
     * Additive edge case (not in the brief): the fail-fast guard for a
     * GENUINELY unknown type must survive adding signed_int as a known one.
     * This is the permanent, always-green proof of the denial path the RED
     * above could only demonstrate transiently (once signed_int is known,
     * those two tests stop exercising the throw).
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
