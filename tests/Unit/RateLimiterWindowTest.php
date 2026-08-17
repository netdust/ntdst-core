<?php // tests/Unit/RateLimiterWindowTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first


use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

/**
 * The window clamp — added in v2.4.1 after the cluster-2 security audit.
 *
 * v2.4.0 validated `$limit` (<= 0 disables the limit) but passed `$window`
 * straight to set_transient(). Two failures fall out of that, both one filter
 * typo away and both silent:
 *
 *   window 0  -> set_transient() stores with NO expiration, so the bucket never
 *                resets and the caller is denied FOREVER. A permanent lockout
 *                of an IP or a user, removable only by hand from wp_options.
 *   window <0 -> the timeout is written in the past, so get_transient() treats
 *                the counter as expired on every read and the limit is never
 *                enforced at all.
 *
 * `(int)` casting also turns any non-numeric filter return into 0, so
 * `fn() => 'sixty'` reaches this path too. Kept in its own file: the v2.4.0
 * contract in RateLimiterTest.php was written by an independent author and is
 * not mine to extend.
 */
final class RateLimiterWindowTest extends TestCase
{
    /** @var array<string, array{value: mixed, ttl: int}> */
    private array $store = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->store = [];

        Functions\when('get_transient')->alias(
            fn($key) => $this->store[$key]['value'] ?? false
        );
        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) {
            $this->store[$key] = ['value' => $value, 'ttl' => (int) $ttl];

            return true;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * @return array<string, array{int}>
     */
    public static function unusableWindowProvider(): array
    {
        return [
            'zero means no expiry to WordPress' => [0],
            'negative writes a timeout in the past' => [-1],
            'large negative' => [-86400],
        ];
    }

    /**
     * An unusable window is clamped, never stored.
     *
     * @dataProvider unusableWindowProvider
     */
    public function test_an_unusable_window_is_clamped_to_a_window_that_expires(int $window): void
    {
        NTDST_RateLimiter::attempt('ntdst_rate_probe', 3, $window);

        $this->assertNotEmpty($this->store, 'The attempt was allowed, so a counter must have been written.');
        $stored = $this->store['ntdst_rate_probe']['ttl'];

        $this->assertGreaterThan(
            0,
            $stored,
            'A window of ' . $window . ' reached set_transient() unchanged. '
            . 'Zero means NO EXPIRATION in WordPress — the bucket would never reset and '
            . 'the caller would be denied permanently.',
        );
    }

    /**
     * The clamp must not become a backdoor that disables the limit.
     *
     * Clamping could plausibly have been implemented as "unusable window means
     * skip the limiter", which would turn a filter typo into a silently
     * disabled control. It must still deny past the limit.
     */
    public function test_a_clamped_window_still_enforces_the_limit(): void
    {
        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = NTDST_RateLimiter::attempt('ntdst_rate_probe', 3, 0);
        }

        $this->assertSame([true, true, true, false], $results);
    }

    /**
     * A usable window is passed through untouched — the clamp is not a rewrite.
     */
    public function test_a_usable_window_is_stored_verbatim(): void
    {
        NTDST_RateLimiter::attempt('ntdst_rate_probe', 3, 900);

        $this->assertSame(900, $this->store['ntdst_rate_probe']['ttl']);
    }
}
