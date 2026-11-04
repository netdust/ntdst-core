<?php // tests/Unit/DataModelRevisionsTest.php
// An editable model keeps WordPress revisions, its declared fields included.
//
// Observed where WordPress observes it: the support flag and the three hooks
// register() leaves behind, then the callbacks driven the way WordPress drives them.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

final class DataModelRevisionsTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private static int $sequence = 0;

    /** @var list<array{0: string, 1: callable, 2: int}> filters and actions registered */
    private array $hooks = [];

    /** @var list<array{0: string, 1: string}> add_post_type_support() calls */
    private array $supports = [];

    /** @var array<string, array<int, array<string, mixed>>> post meta: [id][key] => values */
    private array $meta = [];

    /** @var array<int, string> post id => post type */
    private array $types = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->hooks = [];
        $this->supports = [];
        $this->meta = [];
        $this->types = [];
        unset($GLOBALS['_ntdst_test_filters_at']);

        Functions\when('is_wp_error')->justReturn(false);
        Functions\when('apply_filters')->returnArg(2);
        Functions\when('do_action')->justReturn();
        Functions\when('register_post_meta')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg(1);
        Functions\when('wp_slash')->alias(static fn($v) => is_string($v) ? addslashes($v) : $v);

        Functions\when('add_action')->alias(function (string $tag, callable $cb, int $priority = 10) {
            $this->hooks[] = [$tag, $cb, $priority];

            return true;
        });
        Functions\when('add_post_type_support')->alias(function (string $type, string $feature) {
            $this->supports[] = [$type, $feature];
        });

        Functions\when('get_post_type')->alias(fn($id) => $this->types[$id] ?? false);
        Functions\when('metadata_exists')->alias(fn($t, $id, $key) => isset($this->meta[$id][$key]));
        Functions\when('get_post_meta')->alias(fn($id, $key) => $this->meta[$id][$key] ?? []);
        Functions\when('add_post_meta')->alias(function ($id, $key, $value) {
            $this->meta[$id][$key][] = stripslashes((string) $value);

            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function registerModel(bool $showUi, array $config = [], ?string $name = null): string
    {
        $name ??= 'rev_probe_' . (++self::$sequence);
        Functions\when('register_post_type')->alias(fn() => (object) ['show_ui' => $showUi]);

        (new NTDST_Data_Manager())->register($name, $config + [
            'label'       => 'Probe',
            'auto_metabox' => false,
            'meta_prefix' => '_p_',
            'fields'      => [
                'tagline' => ['type' => 'text'],
                'config'  => ['type' => 'json'],
                'derived' => ['type' => 'callback', 'get' => static fn() => 1],
            ],
        ]);

        return $name;
    }

    /**
     * Filters come from bootstrap's recorder (add_filter is defined process-wide there),
     * actions from this test's own add_action stub.
     *
     * @return list<callable>
     */
    private function callbacks(string $tag, ?int $priority = null): array
    {
        $found = [];
        foreach ($this->hooks as [$hook, $cb, $prio]) {
            if ($hook === $tag && ($priority === null || $priority === $prio)) {
                $found[] = $cb;
            }
        }
        foreach ($GLOBALS['_ntdst_test_filters_at'][$tag] ?? [] as $prio => $cb) {
            if ($priority === null || $priority === $prio) {
                $found[] = $cb;
            }
        }

        return $found;
    }

    /** @return list<string> */
    private function metaKeysFor(string $postType, array $start = []): array
    {
        $keys = $start;
        foreach ($this->callbacks('wp_post_revision_meta_keys') as $cb) {
            $keys = $cb($keys, $postType);
        }

        return $keys;
    }

    public function testAShowUiModelGetsRevisionSupport(): void
    {
        $name = $this->registerModel(true);

        $this->assertContains([$name, 'revisions'], $this->supports);
    }

    public function testAnOwnSupportsListStillGainsRevisions(): void
    {
        $name = $this->registerModel(true, ['supports' => ['title']]);

        $this->assertContains([$name, 'revisions'], $this->supports);
    }

    public function testRevisionsFalseGivesNone(): void
    {
        $this->registerModel(true, ['revisions' => false]);

        $this->assertSame([], $this->supports);
        $this->assertSame([], $this->hooks);
    }

    public function testAModelWithoutShowUiGetsNone(): void
    {
        $this->registerModel(false);

        $this->assertSame([], $this->supports);
        $this->assertSame([], $this->hooks);
    }

    public function testTheModelsFieldKeysAreRevisioned(): void
    {
        $name = $this->registerModel(true);

        $keys = $this->metaKeysFor($name, ['_other_key']);

        $this->assertContains('_p_tagline', $keys);
        $this->assertContains('_p_config', $keys);
        $this->assertNotContains('_p_derived', $keys, 'a callback field is the consumer\'s storage');
        $this->assertContains('_other_key', $keys);
    }

    public function testAnotherPostTypesKeyListIsUnchanged(): void
    {
        $this->registerModel(true);

        $this->assertSame(['_x'], $this->metaKeysFor('page', ['_x']));
    }

    public function testFiveRevisionsAreKept(): void
    {
        $name = $this->registerModel(true);

        $filters = $this->callbacks("wp_{$name}_revisions_to_keep");

        $this->assertCount(1, $filters);
        $this->assertSame(5, $filters[0](-1, (object) []));
    }

    public function testRestoringARevisionWithoutAFieldKeepsItsValue(): void
    {
        $name = $this->registerModel(true);
        $this->types[7] = $name;
        $current = '{"a":"b\\c"}';
        $this->meta[7]['_p_config'] = [$current];

        // WordPress's own step at priority 10: delete every listed key, copy what the revision holds.
        foreach ($this->callbacks('wp_restore_post_revision', 9) as $cb) {
            $cb(7, 8);
        }
        $this->meta[7] = [];
        foreach ($this->callbacks('wp_restore_post_revision', 11) as $cb) {
            $cb(7, 8);
        }

        $this->assertSame([$current], $this->meta[7]['_p_config']);
    }

    public function testRestoringARevisionWithTheFieldRestoresIt(): void
    {
        $name = $this->registerModel(true);
        $this->types[7] = $name;
        $this->meta[7]['_p_tagline'] = ['now'];
        $this->meta[8]['_p_tagline'] = ['then'];

        foreach ($this->callbacks('wp_restore_post_revision', 9) as $cb) {
            $cb(7, 8);
        }
        $this->meta[7] = ['_p_tagline' => ['then']]; // what WordPress's step at 10 leaves
        foreach ($this->callbacks('wp_restore_post_revision', 11) as $cb) {
            $cb(7, 8);
        }

        $this->assertSame(['then'], $this->meta[7]['_p_tagline']);
    }

    public function testRegisteringTwiceNamesEachKeyOnce(): void
    {
        $name = $this->registerModel(true);
        $once = $this->metaKeysFor($name);

        // Two registrations stack two callbacks on WordPress's filter: the second sees the first's output.
        $twice = $this->metaKeysFor($name, $once);

        $this->assertSame(count($twice), count(array_unique($twice)));
        $this->assertEqualsCanonicalizing($once, $twice);
    }

    public function testAnotherPostTypesRestoreIsUntouched(): void
    {
        $this->registerModel(true);
        $this->types[3] = 'page';
        $this->meta[3]['_p_tagline'] = ['keep'];

        foreach ($this->callbacks('wp_restore_post_revision', 9) as $cb) {
            $cb(3, 4);
        }
        $this->meta[3] = [];
        foreach ($this->callbacks('wp_restore_post_revision', 11) as $cb) {
            $cb(3, 4);
        }

        $this->assertSame([], $this->meta[3]);
    }
}
