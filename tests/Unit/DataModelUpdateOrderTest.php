<?php // tests/Unit/DataModelUpdateOrderTest.php
// update() writes a record's fields before its post, so the revision WordPress
// takes with the post holds them (model-revisions R6). Observed through the
// public write path, by the order the writers are called in.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

final class DataModelUpdateOrderTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> */
    private array $stored = [];

    /** @var list<string> */
    private array $calls = [];

    private bool $metaWrites = true;

    private mixed $postResult = 7;

    /** @var list<int> */
    private array $revisions = [1];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->stored = ['_p_headline' => 'old'];
        $this->calls = [];
        $this->metaWrites = true;
        $this->postResult = 7;
        $this->revisions = [1];
        $GLOBALS['_ntdst_test_log'] = [];

        Functions\when('sanitize_text_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('do_action')->justReturn();
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('maybe_serialize')->alias(static fn($v) => is_scalar($v) ? (string) $v : serialize($v));
        Functions\when('get_post_type')->justReturn('p');
        Functions\when('get_post')->alias(static fn($id) => (object) [
            'ID' => (int) $id, 'post_type' => 'p', 'post_status' => 'publish', 'post_title' => 'old title',
        ]);
        Functions\when('metadata_exists')->alias(fn($t, $id, $key) => array_key_exists($key, $this->stored));
        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $this->stored[$key] ?? '');
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->calls[] = "meta:$key";
            if (!$this->metaWrites) {
                return false;
            }
            $this->stored[$key] = $value;

            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            $this->calls[] = "unmeta:$key";
            unset($this->stored[$key]);
        });
        Functions\when('wp_update_post')->alias(function ($data) {
            $this->calls[] = 'post';

            return $this->postResult;
        });
        Functions\when('wp_cache_get')->alias(fn($id = null, $group = '') => $group === 'post_meta'
            ? array_map(static fn($v) => [$v], $this->stored)
            : false);
        Functions\when('post_type_supports')->justReturn(true);
        Functions\when('wp_get_post_revisions')->alias(fn() => $this->revisions);
        Functions\when('wp_save_post_revision')->alias(function ($id) {
            $this->calls[] = "revision:$id";

            return 0;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function model(): NTDST_Data_Model
    {
        return new NTDST_Data_Model('p', ['headline' => 'text'], '_p_');
    }

    public function testFieldsAreWrittenBeforeThePost(): void
    {
        $this->model()->update(7, ['title' => 'new', 'headline' => 'x']);

        $this->assertSame(['meta:_p_headline', 'post'], $this->calls);
    }

    public function testAFieldsOnlyUpdateTakesARevision(): void
    {
        $this->model()->update(7, ['headline' => 'x']);

        $this->assertSame(['meta:_p_headline', 'revision:7'], $this->calls);
    }

    public function testAColumnsUpdateTakesNoExtraRevision(): void
    {
        $this->model()->update(7, ['title' => 'new', 'headline' => 'x']);

        $this->assertNotContains('revision:7', $this->calls);
    }

    public function testARecordWithNoRevisionYetHasItsOriginalSavedFirst(): void
    {
        $this->revisions = [];

        $this->model()->update(7, ['title' => 'new', 'headline' => 'x']);

        $this->assertSame(['revision:7', 'meta:_p_headline', 'post'], $this->calls);
    }

    public function testAFailedPostWriteRestoresTheFields(): void
    {
        $error = new WP_Error('boom', 'no');
        $this->postResult = $error;

        $result = $this->model()->update(7, ['title' => 'new', 'headline' => 'x']);

        $this->assertSame($error, $result);
        $this->assertSame('old', $this->stored['_p_headline']);
    }

    public function testAFailedFieldWriteWritesNoPost(): void
    {
        $this->metaWrites = false;

        $result = $this->model()->update(7, ['title' => 'new', 'headline' => 'x']);

        $this->assertInstanceOf(WP_Error::class, $result);
        $this->assertNotContains('post', $this->calls);
    }
}
