<?php // tests/Unit/DataDropsExposureTest.php
// Class D — the data layer stops deciding what may leave.
//
// "Public" is not a property of a row: the same row can be public on the
// website, absent from the API, and visible only to a signed-in reader
// elsewhere. Only the consumer knows which applies, so the projection moves to
// the exposure surface and core keeps none of it.
//
// These assertions fail against the version that still carries the mechanism.
defined('ABSPATH') || exit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/Data.php';

final class DataDropsExposureTest extends TestCase
{
    /** The three public entry points are gone. */
    public function testTheModelExposesNoPublicShapeApi(): void
    {
        foreach (['publicRows', 'publicRow', 'getPublicShape'] as $method) {
            $this->assertFalse(
                method_exists(NTDST_Data_Model::class, $method),
                sprintf(
                    '%s() decides what may leave, which is the consumer\'s question, '
                    . 'not the data layer\'s.',
                    $method,
                ),
            );
        }
    }

    /** The constructor no longer takes a shape to hold. */
    public function testTheConstructorAcceptsNoPublicShape(): void
    {
        $names = array_map(
            static fn(ReflectionParameter $p): string => $p->getName(),
            (new ReflectionMethod(NTDST_Data_Model::class, '__construct'))->getParameters(),
        );

        $this->assertNotContains('public_shape', $names);
        $this->assertSame(['post_type', 'schema', 'meta_prefix', 'scopes'], $names);
    }

    /** No trace of the property survives in code — comments may still explain the history. */
    public function testNoPublicShapeIdentifierRemainsInCode(): void
    {
        $code = self::codeWithoutComments(__DIR__ . '/../../api/Data.php');

        $this->assertStringNotContainsString('public_shape', $code);
    }

    /**
     * The key is gone entirely — not stripped, not deprecated, not warned
     * about. Four declarations across two sites were counted before deciding
     * this (daan 1, netdust 3), and adjusting four call sites is a smaller job
     * than carrying a vestige in the framework forever. A site that keeps
     * declaring it hands register_post_type() an argument WordPress ignores.
     */
    public function testNoPublicFieldsVestigeRemains(): void
    {
        $code = self::codeWithoutComments(__DIR__ . '/../../api/Data.php');

        $this->assertStringNotContainsString('public_fields', $code);
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
