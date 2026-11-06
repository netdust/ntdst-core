<?php // tests/Unit/LintTriageMetaboxRenderTest.php
// A hostile stored value renders escaped exactly once on the edit screen
// (core-lint-gate, threat model attacks 1 and 6). Every control that prints a
// stored string is driven through render_metabox() with `<b>&"x` in the store.
//
// No media control here: the picker assets are emitted once per process, and
// MetaboxGeneratorRenderTest needs to be the first media render (its promise 6).
// A media control prints an absint() id, never a stored string.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';
require_once __DIR__ . '/../../admin/MetaboxGenerator.php';

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public int $ID = 0;
        public string $post_type = '';
        public string $post_status = 'publish';
    }
}

final class LintTriageMetaboxRenderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const POST_ID = 297051;

    private const HOSTILE = '<b>&"x';

    private const ESCAPED = '&lt;b&gt;&amp;&quot;x';

    /** @var array<string, mixed> What get_post_meta() answers. */
    private array $meta = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->meta = [];

        Functions\when('esc_attr')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_html')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_textarea')->alias(static fn($t) => htmlspecialchars((string) $t, ENT_QUOTES));
        Functions\when('esc_url')->alias(static function ($url) {
            $url = str_replace(' ', '%20', ltrim((string) $url));
            $url = (string) preg_replace('/[^a-z0-9\-~+_.?#=!&;,\/:%@$|*\'()\[\]\x80-\xff]/i', '', $url);

            return str_replace(['&', "'"], ['&#038;', '&#039;'], $url);
        });
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('wp_kses_post')->alias(static fn($v) => 'kses:' . (string) $v);
        Functions\when('wp_editor')->alias(static function ($content, $editor_id, $settings = []) {
            echo '<!--wp_editor:' . $editor_id . ':' . ($settings['textarea_name'] ?? '') . '-->';
        });

        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $this->meta[$key] ?? '');
        Functions\when('get_post_type')->alias(static fn($post = null) => 'post');
        Functions\when('get_the_title')->alias(static fn($id) => 'Post ' . (int) $id);
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_users')->justReturn([]);
        Functions\when('admin_url')->alias(static fn($p = '') => 'https://example.org/wp-admin/' . $p);

        Functions\when('wp_nonce_field')->justReturn('');
        Functions\when('wp_enqueue_media')->justReturn(null);
        Functions\when('add_action')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('apply_filters')->returnArg(2);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: mixed, 2: int}>
     *         declaration, stored value, how many times the value is printed
     */
    public static function controlsPrintingAStoredString(): array
    {
        return [
            'text'              => ['text', self::HOSTILE, 1],
            'email'             => ['email', self::HOSTILE, 1],
            'url'               => ['url', self::HOSTILE, 1],
            'date'              => ['date', self::HOSTILE, 1],
            'int'               => ['int', self::HOSTILE, 1],
            'float'             => ['float', self::HOSTILE, 1],
            'textarea'          => ['textarea', self::HOSTILE, 1],
            'json'              => ['json', self::HOSTILE, 1],
            'readonly text'     => [['type' => 'text', 'readonly' => true], self::HOSTILE, 2],
            'readonly select'   => [
                ['type' => 'select', 'readonly' => true, 'options' => [self::HOSTILE => 'Hostile']],
                self::HOSTILE,
                2,
            ],
            'a repeater cell'   => [
                ['type' => 'repeater', 'sub_fields' => ['label' => 'text']],
                [['label' => self::HOSTILE]],
                1,
            ],
        ];
    }

    #[DataProvider('controlsPrintingAStoredString')]
    public function testAHostileStoredValueRendersEscapedAndNeverRaw(
        string|array $declaration,
        mixed $stored,
        int $printed,
    ): void {
        $this->meta['hostile'] = $stored;

        $html = $this->render(['hostile' => $declaration]);

        $this->assertStringNotContainsString('<b>', $html, 'A stored value reached the edit screen unescaped.');
        $this->assertStringNotContainsString('&amp;lt;', $html, 'A stored value was escaped twice.');
        $this->assertSame($printed, substr_count($html, self::ESCAPED), 'The escaped value is printed as often as the control shows it.');
    }

    /** @param array<string, mixed> $fields */
    private function render(array $fields): string
    {
        $post = new WP_Post();
        $post->ID = self::POST_ID;
        $post->post_type = 'note';

        $generator = (new ReflectionClass('NTDST_MetaboxGenerator'))->newInstanceWithoutConstructor();

        ob_start();

        try {
            $generator->render_metabox($post, [
                'args' => ['model_name' => 'note', 'fields' => $fields],
            ]);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }
}
