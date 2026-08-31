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
        Actions\expectDone('ntdst/model/updated')->once()->ordered()->with(
            'p',
            7,
            ['headline' => 'edited'],
            ['post' => [], 'meta' => ['headline' => ['exists' => false, 'value' => '']]],
        );
        $this->expectNoRetiredHook();

        $this->model()->update(7, ['headline' => 'edited']);
    }

    /**
     * The audit payload: `updated`'s fourth argument carries the before-state
     * of exactly the fields the caller wrote — post columns under 'post'
     * (mapped to their post_* names), meta fields under 'meta' (unprefixed,
     * as ['exists' => bool, 'value' => mixed] snapshots). This is what lets a
     * listener render "changed Status from draft to published" instead of
     * "post 7 updated".
     */
    public function testUpdatedCarriesThePreviousPostAndMetaState(): void
    {
        $this->stored['_p_headline'] = 'old headline';

        Actions\expectDone('ntdst/model/updated')->once()->whenHappen(
            function (string $type, int $id, array $data, array $previous): void {
                $this->assertSame('p', $type);
                $this->assertSame(7, $id);
                $this->assertSame(
                    ['post_title' => 'a row'],
                    $previous['post'],
                    'The written post column carries its pre-write value.',
                );
                $this->assertSame(
                    ['headline' => ['exists' => true, 'value' => 'old headline']],
                    $previous['meta'],
                    'The written meta field carries its pre-write snapshot, unprefixed.',
                );
            },
        );

        $this->model()->update(7, ['title' => 'New title', 'headline' => 'edited']);
    }

    /** A meta write that fails rolls back and must not report a change. */
    public function testUpdatedDoesNotFireWhenAMetaWriteFails(): void
    {
        Functions\when('update_post_meta')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('something else entirely');

        Actions\expectDone('ntdst/model/updating')->once();
        Actions\expectDone('ntdst/model/updated')->never();

        $result = $this->model()->update(7, ['headline' => 'edited']);

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    // --------------------------------------------------------------- delete

    public function testDeleteFiresDeletingThenDeletedAndNoRetiredName(): void
    {
        $snapshots = [];
        $capture = function (string $type, int $id, array $snapshot) use (&$snapshots): void {
            $snapshots[] = [$type, $id, $snapshot];
        };

        Actions\expectDone('ntdst/model/deleting')->once()->ordered()->whenHappen($capture);
        Actions\expectDone('ntdst/model/deleted')->once()->ordered()->whenHappen($capture);
        $this->expectNoRetiredHook();

        $this->stored['_p_headline'] = 'the last headline';

        $this->model()->delete(7, true);

        // Both hooks carry the SAME pre-delete snapshot: the row as it stood
        // ('post') and its schema-formatted fields ('meta', unprefixed) — the
        // only place the deleted content survives to.
        $this->assertCount(2, $snapshots);
        foreach ($snapshots as [$type, $id, $snapshot]) {
            $this->assertSame('p', $type);
            $this->assertSame(7, $id);
            $this->assertSame(7, $snapshot['post']->ID);
            $this->assertSame('a row', $snapshot['post']->post_title);
            $this->assertSame(['headline' => 'the last headline'], $snapshot['meta']);
        }
    }

    // ----------------------------------------------------- meta write hooks

    /**
     * updateMeta() is a write path like any other: invisible to the audit log
     * until it fires. One event, on the success path only, carrying the
     * written value and the before-state snapshot.
     */
    public function testUpdateMetaFiresMetaUpdatedWithThePreviousValue(): void
    {
        $this->stored['_p_headline'] = 'old';

        Actions\expectDone('ntdst/model/meta_updated')->once()->with(
            'p',
            7,
            ['headline' => 'new'],
            ['headline' => ['exists' => true, 'value' => 'old']],
        );

        $this->assertTrue($this->model()->updateMeta(7, 'headline', 'new'));
    }

    public function testUpdateMetaDoesNotFireOnAFailedWrite(): void
    {
        Functions\when('update_post_meta')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('not what was asked for');

        Actions\expectDone('ntdst/model/meta_updated')->never();

        $result = $this->model()->updateMeta(7, 'headline', 'new');

        $this->assertInstanceOf(WP_Error::class, $result);
    }

    /**
     * A batch is ONE caller action, so it is ONE event — all written keys and
     * their snapshots in a single firing, not a wall of per-key rows.
     */
    public function testUpdateMetaBatchFiresMetaUpdatedOnceForTheWholeBatch(): void
    {
        $this->stored['_p_headline'] = 'old headline';

        Actions\expectDone('ntdst/model/meta_updated')->once()->with(
            'p',
            7,
            ['headline' => 'new headline', 'summary' => 'new summary'],
            [
                'headline' => ['exists' => true, 'value' => 'old headline'],
                'summary'  => ['exists' => false, 'value' => ''],
            ],
        );

        $model = new NTDST_Data_Model('p', ['headline' => 'text', 'summary' => 'text'], '_p_');

        $this->assertTrue($model->updateMetaBatch(7, [
            'headline' => 'new headline',
            'summary'  => 'new summary',
        ]));
    }

    /** A batch that fails mid-way rolls back — no change happened, no event. */
    public function testUpdateMetaBatchDoesNotFireWhenAWriteFails(): void
    {
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) {
            if ($key === '_p_summary') {
                return false;
            }
            $this->stored[$key] = $value;

            return true;
        });
        Functions\when('get_post_meta')->alias(
            fn($id, $key = '', $single = false) => $key === '_p_summary'
                ? 'not what was written'
                : ($this->stored[$key] ?? ''),
        );

        Actions\expectDone('ntdst/model/meta_updated')->never();

        $model = new NTDST_Data_Model('p', ['headline' => 'text', 'summary' => 'text'], '_p_');

        $this->assertFalse($model->updateMetaBatch(7, [
            'headline' => 'written then rolled back',
            'summary'  => 'refused',
        ]));
    }

    public function testDeleteMetaFiresMetaDeletedWithThePreviousValue(): void
    {
        $this->stored['_p_headline'] = 'about to go';

        Actions\expectDone('ntdst/model/meta_deleted')->once()->with(
            'p',
            7,
            'headline',
            ['exists' => true, 'value' => 'about to go'],
        );

        $this->assertTrue($this->model()->deleteMeta(7, 'headline'));
    }

    /**
     * delete_post_meta() answers false both for a failure and for a key that
     * was never set — neither is a change, so neither is logged as one.
     */
    public function testDeleteMetaDoesNotFireWhenNothingWasDeleted(): void
    {
        Functions\when('delete_post_meta')->justReturn(false);

        Actions\expectDone('ntdst/model/meta_deleted')->never();

        $this->assertFalse($this->model()->deleteMeta(7, 'headline'));
    }

    /** A refused validation stops the write before any hook fires. */
    public function testNoHookFiresWhenValidationRefusesTheUpdate(): void
    {
        Actions\expectDone('ntdst/model/updating')->never();
        Actions\expectDone('ntdst/model/updated')->never();

        $model = new NTDST_Data_Model('p', ['headline' => ['type' => 'text', 'required' => true]], '_p_');

        // Explicitly blanking a required field is refused on update.
        $result = $model->update(7, ['headline' => '']);

        $this->assertInstanceOf(WP_Error::class, $result);
    }

}
