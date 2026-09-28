<?php // tests/Unit/DataWhereRelatedTest.php
// 5.3.0 — "which posts point at this one?", from the chain.
//
// A relation is stored as ONE meta row holding a serialized list of ints
// (FieldTypes::ids(), update_post_meta): [12, 34] is `a:2:{i:0;i:12;i:1;i:34;}`.
// A reverse lookup therefore has to find a VALUE in that string, and the naive
// LIKE '%i:12;%' also hits the INDEX keys: `i:1;` is both "value 1" and "the
// second slot", and `i:12;i:1;` contains `i:1;` on the boundary. The only
// admin-side reader (RelationField::findReferringPosts) narrows with LIKE and
// then re-checks every row in PHP; a public query cannot.
//
// whereRelated() anchors a REGEXP at the start of the list and walks it as
// key/value PAIRS, so the id is only ever compared against a value position.
//
// WHAT THIS FILE ASSERTS:
//   1. The clause WP_Query is handed: prefixed key, REGEXP, the anchored pattern.
//   2. The pattern's semantics on real serialize() output — index keys and
//      longer ids never match (MariaDB's REGEXP is proven in a consumer's
//      integration tier; this pins the pattern itself).
//   3. It composes inside whereGroup(), which is how "credited under ANY role"
//      is asked.
defined('ABSPATH') || exit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../api/FieldTypes.php';
require_once __DIR__ . '/../../api/Data.php';

// Records the argument bag; serves no rows. What this file asks is what
// WordPress was ASKED, which is the only place a meta_query is observable
// without reaching into the builder's protected state.
if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var array<int, object> */
        public array $posts = [];

        public int $found_posts = 0;

        public function __construct(array $args = [])
        {
            $GLOBALS['_ntdst_test_wp_query_args'][] = $args;

            $this->posts = $GLOBALS['_ntdst_test_wp_query_posts'] ?? [];
            $this->found_posts = count($this->posts);
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $msg = '', private mixed $data = null) {}
        public function get_error_code(): string { return $this->code; }
    }
}

final class DataWhereRelatedTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const PREFIX = '_x_';

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['_ntdst_test_wp_query_args'] = [];
        $GLOBALS['_ntdst_test_wp_query_posts'] = [];

        Functions\when('wp_parse_args')->alias(
            static fn($args, $defaults = []) => array_merge((array) $defaults, (array) $args),
        );
        Functions\when('is_wp_error')->alias(static fn($v) => $v instanceof WP_Error);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_whereRelated_builds_anchored_regexp_clause(): void
    {
        $this->model()->whereRelated('credit_direction', 12)->get();

        $this->assertSame(
            [[
                'key'     => '_x_credit_direction',
                'value'   => '^a:[0-9]+:\\{(i:[0-9]+;i:[0-9]+;)*i:[0-9]+;i:12;',
                'compare' => 'REGEXP',
            ]],
            $this->metaQuery(),
        );
    }

    /**
     * @return array<string, array{0: list<int>, 1: int, 2: bool}>
     */
    public static function lists(): array
    {
        return [
            'a value in the first slot'           => [[12, 34], 12, true],
            'a value in a later slot'             => [[12, 34], 34, true],
            'an id equal to an index key'         => [[12, 34], 1, false],
            'an id equal to index key zero'       => [[12, 34], 0, false],
            'a longer id containing the id'       => [[112], 12, false],
            'the id containing a shorter value'   => [[12], 112, false],
            'a stored id longer than the id'      => [[123], 12, false],
            'an empty list'                       => [[], 12, false],
        ];
    }

    /**
     * @dataProvider lists
     * @param list<int> $stored
     */
    public function test_the_pattern_matches_only_a_value_position(array $stored, int $id, bool $expected): void
    {
        $this->model()->whereRelated('credit_direction', $id)->get();
        $pattern = $this->metaQuery()[0]['value'];

        $this->assertSame($expected, preg_match('/' . $pattern . '/', serialize($stored)) === 1);
    }

    public function test_whereRelated_composes_inside_a_group(): void
    {
        $this->model()
            ->whereGroup('OR', static function (NTDST_Data_Model $g): void {
                $g->whereRelated('credit_direction', 7)->whereRelated('credit_music', 7);
            })
            ->get();

        $group = $this->metaQuery()[0];
        $this->assertSame('OR', $group['relation']);
        $this->assertSame('_x_credit_direction', $group[0]['key']);
        $this->assertSame('_x_credit_music', $group[1]['key']);
    }

    private function model(): NTDST_Data_Model
    {
        return new NTDST_Data_Model(
            'probe',
            [
                'credit_direction' => ['type' => 'relation', 'post_type' => 'person'],
                'credit_music'     => ['type' => 'relation', 'post_type' => 'person'],
            ],
            self::PREFIX,
        );
    }

    private function metaQuery(): array
    {
        $this->assertCount(1, $GLOBALS['_ntdst_test_wp_query_args'], 'One read is one query.');

        return $GLOBALS['_ntdst_test_wp_query_args'][0]['meta_query'] ?? [];
    }
}
