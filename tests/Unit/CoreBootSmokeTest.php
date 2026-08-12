<?php // tests/Unit/CoreBootSmokeTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use PHPUnit\Framework\TestCase;

final class CoreBootSmokeTest extends TestCase
{
    public function test_core_classes_load(): void
    {
        $this->assertTrue(class_exists('NTDST_Container'));
        $this->assertTrue(class_exists('NTDST_Data_Model'));
        $this->assertTrue(class_exists('NTDST_MetaboxGenerator'));
    }
}
