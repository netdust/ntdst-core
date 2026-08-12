<?php // tests/Unit/CoreBootSmokeTest.php

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
