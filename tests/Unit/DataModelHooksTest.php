<?php // tests/Unit/DataModelHooksTest.php
// The six model lifecycle hooks are spelled `ntdst/model/*`.
//
// RED contract for core-trim T06 (Tier A, stakes standard). It asserts spec
// FR-11 and SC-4: the six `ntdst_model_{create,update,delete}_{before,after}`
// actions are renamed to `ntdst/model/{creating,created,updating,updated,
// deleting,deleted}` and carry the SAME arguments they carry today.
//
// WHY A BEHAVIOURAL TEST AND NOT A GREP. A rename is only done when the new
// name FIRES on the real write path with the real payload. A grep over
// api/Data.php proves the string was retyped; it cannot tell a `created` fired
// after the meta writes from one fired before them, and it cannot see that the
// payload still carries the post type, the id and the sanitized data a
// listener needs. Both halves matter to the fleet: daan's PressKitService
// listens on the OLD names and prunes on the payload, so a listener that
// receives the wrong arity is as inert as one listening on a dead name.
//
// The old names are pinned with `->never()`. A rename that ADDS the new
// do_action beside the old one would satisfy every positive assertion in this
// file while leaving the duplicate spelling core-trim exists to remove — the
// negative half is what makes this a rename rather than an addition. It rides
// in the SAME case as the positive half, on the same single write: one call
// per path, both halves observed on it, which is what "renamed" means. Split
// across two cases the negative half runs a second write to assert on — twice
// the setup for a claim the first write already carries.
//
// HOW IT OBSERVES. Through the public write path only — create(), update(),
// delete(). WordPress's writers are stubbed to SUCCEED (wp_insert_post,
// wp_update_post, wp_delete_post) so each call reaches its "after" hook;
// get_post() answers with a row of this model's post type so update() and
// delete() clear their existence guard and reach the hooks at all. do_action()
// is Brain Monkey's, which is what makes the hook name and its arguments
// observable without a WordPress runtime.
//
// The sanitizers are plain real-equivalents here (no tagging): this file asks
// WHICH HOOK FIRED WITH WHAT, not which sanitizer ran, and a tagged value
// would only obscure the payload the assertions pin.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

final class DataModelHooksTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** The six spellings FR-11 retires. None may fire again. */
    private const RETIRED_HOOKS = [
        'ntdst_model_create_before',
        'ntdst_model_create_after',
        'ntdst_model_update_before',
        'ntdst_model_update_after',
        'ntdst_model_delete_before',
        'ntdst_model_delete_after',
    ];

    /** @var array<string, mixed> */
    private array $stored = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->stored = [];
        $GLOBALS['_ntdst_test_log'] = [];

        // ---- sanitizers: real-equivalent, untagged ----
        Functions\when('sanitize_text_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('sanitize_textarea_field')->alias(static fn($v) => trim(strip_tags((string) $v)));
        Functions\when('wp_kses_post')->returnArg(1);
        Functions\when('sanitize_title')->alias(
            static fn($v) => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $v)),
        );
        Functions\when('absint')->alias(static fn($v) => abs((int) $v));
        Functions\when('wp_validate_boolean')->alias(static fn($v) => (bool) $v);
        Functions\when('get_post_type')->justReturn('p');

        // ---- the store: a row of THIS model's post type, so update() and
        //      delete() pass their existence guard and reach the hooks ----
        Functions\when('get_post')->alias(static fn($id) => (object) [
            'ID'          => (int) $id,
            'post_type'   => 'p',
            'post_status' => 'publish',
            'post_title'  => 'a row',
        ]);
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            $this->stored[$key] = $value;

            return true;
        });
        Functions\when('get_post_meta')->alias(fn($id, $key = '', $single = false) => $this->stored[$key] ?? '');
        Functions\when('delete_post_meta')->alias(function ($id, $key) {
            unset($this->stored[$key]);

            return true;
        });
        Functions\when('metadata_exists')->alias(fn($t, $id, $key) => array_key_exists($key, $this->stored));

        // ---- the writers, stubbed to SUCCEED ----
        Functions\when('wp_insert_post')->justReturn(42);
        Functions\when('wp_update_post')->alias(static fn($data) => (int) ($data['ID'] ?? 7));
        Functions\when('wp_delete_post')->justReturn(true);
        Functions\when('wp_trash_post')->justReturn(true);

        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
        Functions\when('maybe_unserialize')->returnArg(1);
        Functions\when('maybe_serialize')->alias(static fn($v) => is_scalar($v) ? (string) $v : serialize($v));
        Functions\when('wp_cache_get')->alias(fn($id = null, $group = '') => $group === 'post_meta'
            ? array_map(static fn($v) => [$v], $this->stored)
            : false);
        Functions\when('register_post_meta')->justReturn(true);
        Functions\when('user_can')->justReturn(true);
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

    /** Pin all six retired spellings as never fired, for any write path. */
    private function expectNoRetiredHook(): void
    {
        foreach (self::RETIRED_HOOKS as $retired) {
            Actions\expectDone($retired)->never();
        }
    }

    // --------------------------------------------------------------- create

    public function testCreateFiresCreatingThenCreatedAndNoRetiredName(): void
    {
        Actions\expectDone('ntdst/model/creating')->once()->ordered()->with('p', ['headline' => 'hello']);
        Actions\expectDone('ntdst/model/created')->once()->ordered()->with('p', 42, ['headline' => 'hello']);
        $this->expectNoRetiredHook();

        $this->model()->create(['headline' => 'hello']);
    }

    // --------------------------------------------------------------- update

    public function testUpdateFiresUpdatingThenUpdatedAndNoRetiredName(): void
    {
        Actions\expectDone('ntdst/model/updating')->once()->ordered()->with('p', 7, ['headline' => 'edited']);
        Actions\expectDone('ntdst/model/updated')->once()->ordered()->with('p', 7, ['headline' => 'edited']);
        $this->expectNoRetiredHook();

        $this->model()->update(7, ['headline' => 'edited']);
    }

    // --------------------------------------------------------------- delete

    public function testDeleteFiresDeletingThenDeletedAndNoRetiredName(): void
    {
        Actions\expectDone('ntdst/model/deleting')->once()->ordered()->with('p', 7);
        Actions\expectDone('ntdst/model/deleted')->once()->ordered()->with('p', 7);
        $this->expectNoRetiredHook();

        $this->model()->delete(7, true);
    }

}
