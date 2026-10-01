<?php // tests/Unit/ThemeTextdomainTest.php
declare(strict_types=1);
// 5.4.1 — a child theme's text domain loads from the child theme's own
// languages/ folder. get_template_directory() is the PARENT under a child
// theme, so the child's .mo files were never found.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../core/Theme.php';

final class ThemeTextdomainTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->root = sys_get_temp_dir() . '/ntdst-textdomain-' . uniqid();
        mkdir($this->root . '/parent/languages', 0777, true);
        mkdir($this->root . '/child', 0777, true);

        Functions\when('get_template_directory')->justReturn($this->root . '/parent');
        Functions\when('get_stylesheet_directory')->justReturn($this->root . '/child');
        Functions\when('register_nav_menus')->justReturn(null);
    }

    protected function tearDown(): void
    {
        @rmdir($this->root . '/child/languages');
        @rmdir($this->root . '/child');
        @rmdir($this->root . '/parent/languages');
        @rmdir($this->root . '/parent');
        @rmdir($this->root);
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_a_child_theme_loads_its_own_languages_folder(): void
    {
        mkdir($this->root . '/child/languages');

        Functions\expect('load_theme_textdomain')
            ->once()
            ->with('laika', $this->root . '/child/languages');

        (new NTDST_Theme(['textdomain' => 'laika']))->setup_theme();
        $this->addToAssertionCount(1);
    }

    public function test_without_a_child_languages_folder_the_parent_is_used(): void
    {
        Functions\expect('load_theme_textdomain')
            ->once()
            ->with('laika', $this->root . '/parent/languages');

        (new NTDST_Theme(['textdomain' => 'laika']))->setup_theme();
        $this->addToAssertionCount(1);
    }
}
