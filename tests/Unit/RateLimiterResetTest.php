<?php // tests/Unit/RateLimiterResetTest.php
// F3 — the limiter has no way to clear a bucket.
//
// `NTDST_RateLimiter` shipped with exactly one public method, `attempt()`. That
// is why ntdst-baseline's login lockout cannot converge onto the shared
// primitive: `SecurityService::loginSuccessful()` does `delete_transient(...)`
// — RESET ON SUCCESS, a verb the limiter does not expose.
//
// Ground-truthed, because the first version of this reasoning was wrong: a
// DENIED attempt already consumes nothing and extends no TTL in both
// implementations, so "non-extending TTL" is NOT the difference. Reset-on-
// success is. A failure counter that clears the moment the caller succeeds is
// a different primitive from a request budget that only decays with its
// window, and `reset(string $key): void` is the whole gap between them.
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class RateLimiterResetTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    /** Fake transient store: key => value. A DELETED key is absent, not zero. */
    private array $transients = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
        $this->transients = [];

        Functions\when('get_transient')->alias(fn($key) => $this->transients[$key] ?? false);
        Functions\when('set_transient')->alias(function ($key, $value, $ttl = 0) {
            $this->transients[$key] = $value;
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) {
            $existed = array_key_exists($key, $this->transients);
            unset($this->transients[$key]);
            return $existed;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function testResetClearsTheBucketSoTheNextAttemptStartsFromZero(): void
    {
        // The login-lockout shape: three failures exhaust the budget, the
        // fourth is denied, then the caller finally succeeds and the counter
        // must go away — not sit there until the window elapses.
        $key = 'ntdst_login_' . md5('ip|stefan');

        NTDST_RateLimiter::attempt($key, 3, 900);
        NTDST_RateLimiter::attempt($key, 3, 900);
        NTDST_RateLimiter::attempt($key, 3, 900);
        $this->assertFalse(NTDST_RateLimiter::attempt($key, 3, 900), 'control: the budget is spent.');

        NTDST_RateLimiter::reset($key);

        $this->assertTrue(
            NTDST_RateLimiter::attempt($key, 3, 900),
            'After a reset the bucket is new — that is what reset-on-success means.',
        );
    }

    public function testResetDeletesTheRowRatherThanWritingAZero(): void
    {
        // A zeroed counter is still a wp_options row with a live timeout row
        // beside it. The verb baseline needs is delete.
        $key = 'ntdst_login_' . md5('ip|stefan');
        NTDST_RateLimiter::attempt($key, 3, 900);
        $this->assertArrayHasKey($key, $this->transients, 'control: the bucket exists.');

        NTDST_RateLimiter::reset($key);

        $this->assertArrayNotHasKey($key, $this->transients, 'reset() deletes the bucket, it does not zero it.');
    }

    public function testResetClearsAMemoizedDecisionForThatKey(): void
    {
        // attempt() memoizes its decision per (scope, key) — the guarantee that
        // keeps WP's double permission-callback invocation from halving every
        // limit. A reset that left the memo standing would be a half-reset: the
        // caller cleared the bucket and the very next question still answered
        // from the denial it just cleared.
        $key = 'ntdst_login_' . md5('ip|stefan');
        $scope = new stdClass();

        // Spend the budget OUTSIDE the scope, so the scope's own first
        // question earns a denial and memoizes THAT. (Asking twice inside one
        // scope replays the first answer, which is the allow — that memo is
        // the point of the memo.)
        NTDST_RateLimiter::attempt($key, 1, 900);
        $this->assertFalse(NTDST_RateLimiter::attempt($key, 1, 900, $scope), 'control: memoized denial.');

        NTDST_RateLimiter::reset($key);

        $this->assertTrue(NTDST_RateLimiter::attempt($key, 1, 900, $scope), 'The memo must not outlive the bucket.');
    }

    public function testResetTouchesNoOtherBucket(): void
    {
        $mine = 'ntdst_login_' . md5('ip|stefan');
        $theirs = 'ntdst_login_' . md5('ip|someone_else');
        $scope = new stdClass();

        NTDST_RateLimiter::attempt($mine, 3, 900, $scope);
        NTDST_RateLimiter::attempt($theirs, 1, 900);
        $this->assertFalse(
            NTDST_RateLimiter::attempt($theirs, 1, 900, $scope),
            'control: this scope has memoized a denial for their bucket.',
        );

        NTDST_RateLimiter::reset($mine);

        $this->assertArrayHasKey($theirs, $this->transients, 'One key in, one key out.');
        $this->assertFalse(
            NTDST_RateLimiter::attempt($theirs, 1, 900, $scope),
            'Their spent budget — and their memoized denial — must survive my reset.',
        );
    }

    public function testResetDoesNotDiscardThisScopesMemoForOtherKeys(): void
    {
        // The bucket hides a broad memo wipe: re-asking simply re-reads the
        // store and denies again, so the answer looks right. What it costs is
        // a SECOND consumed unit — the halving the memo exists to prevent.
        // Count the units, not the answers.
        $mine = 'ntdst_login_' . md5('ip|stefan');
        $theirs = 'ntdst_rate_' . md5('u7|save_thing');
        $scope = new stdClass();

        NTDST_RateLimiter::attempt($mine, 3, 900, $scope);
        NTDST_RateLimiter::attempt($theirs, 30, 60, $scope);
        $this->assertSame(1, $this->transients[$theirs], 'control: one unit spent.');

        NTDST_RateLimiter::reset($mine);

        NTDST_RateLimiter::attempt($theirs, 30, 60, $scope);
        $this->assertSame(
            1,
            $this->transients[$theirs],
            'Resetting one key must not make this request pay twice for another.',
        );
    }

    public function testResettingABucketThatWasNeverWrittenIsHarmless(): void
    {
        // The success path runs on every successful login, including the ones
        // that never failed. It must not care.
        NTDST_RateLimiter::reset('ntdst_login_' . md5('ip|never_failed'));

        $this->assertSame([], $this->transients);
    }

    // =====================================================================
    // exceeded() — the read that does not consume
    // =====================================================================

    public function testExceededReportsTheStateWithoutSpendingAUnit(): void
    {
        // A failure counter is CHECKED far more often than it is incremented:
        // ntdst-baseline's lockout asks twice per login attempt and only
        // increments on an actual failure. attempt() consumes, so asking with
        // it would count every question as an answer — the check would cause
        // the lockout it is checking for.
        $key = 'ntdst_login_' . md5('ip|stefan');

        NTDST_RateLimiter::attempt($key, 3, 900);

        $this->assertFalse(NTDST_RateLimiter::exceeded($key, 3), 'One failure of three is not a lockout.');
        $this->assertSame(1, $this->transients[$key], 'Asking must not spend.');

        NTDST_RateLimiter::exceeded($key, 3);
        NTDST_RateLimiter::exceeded($key, 3);

        $this->assertSame(1, $this->transients[$key], 'Nor must asking three times.');
    }

    public function testExceededIsTrueAtTheCapNotOnlyPastIt(): void
    {
        // The boundary the caller's own `>= max` comparison used to own. Off
        // by one here and a three-strike lockout becomes a four-strike one.
        $key = 'ntdst_login_' . md5('ip|stefan');

        NTDST_RateLimiter::attempt($key, 2, 900);
        $this->assertFalse(NTDST_RateLimiter::exceeded($key, 2));

        NTDST_RateLimiter::attempt($key, 2, 900);
        $this->assertTrue(NTDST_RateLimiter::exceeded($key, 2), 'Two of two IS the lockout.');
    }

    public function testExceededIsFalseForABucketThatWasNeverWritten(): void
    {
        $this->assertFalse(NTDST_RateLimiter::exceeded('ntdst_login_' . md5('ip|nobody'), 3));
    }

    public function testExceededAgreesWithAttemptAtEveryStep(): void
    {
        // The two must never disagree: `exceeded()` is the question
        // `attempt()` answers on its way past. If they drift, a caller can be
        // told it is fine and then refused, or told it is locked and let in.
        $key = 'ntdst_login_' . md5('ip|agree');

        for ($i = 0; $i < 6; $i++) {
            $before = NTDST_RateLimiter::exceeded($key, 3);
            $allowed = NTDST_RateLimiter::attempt($key, 3, 900);

            $this->assertSame($before, !$allowed, "step {$i}: exceeded() must predict attempt()'s refusal");
        }
    }

    public function testADisabledLimitCanNeverBeExceeded(): void
    {
        // `attempt()` treats a limit of <= 0 as "switched off, always allow".
        // `exceeded()` must agree, or a caller that disabled its limiter is
        // told it is permanently locked out — and `(int) $count >= 0` is true
        // for every bucket that ever existed, so the naive read gets this
        // exactly backwards.
        $key = 'ntdst_login_' . md5('ip|disabled');

        NTDST_RateLimiter::attempt($key, 0, 900);
        $this->assertFalse(NTDST_RateLimiter::exceeded($key, 0), 'A limit that is off cannot be met.');

        NTDST_RateLimiter::attempt($key, 5, 900);
        $this->assertFalse(NTDST_RateLimiter::exceeded($key, -1), 'Nor can a negative one.');
    }

    public function testResetClearsWhatExceededReports(): void
    {
        $key = 'ntdst_login_' . md5('ip|stefan');
        NTDST_RateLimiter::attempt($key, 1, 900);
        $this->assertTrue(NTDST_RateLimiter::exceeded($key, 1), 'control: locked.');

        NTDST_RateLimiter::reset($key);

        $this->assertFalse(NTDST_RateLimiter::exceeded($key, 1), 'The three verbs describe one bucket.');
    }
}
