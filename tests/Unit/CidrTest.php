<?php // tests/Unit/CidrTest.php
defined('ABSPATH') || exit; // direct web hit: ABSPATH undefined → exit; under phpunit the bootstrap defines it first


use PHPUnit\Framework\TestCase;

/**
 * NTDST_Cidr::contains() — the trust predicate.
 *
 * This is the most security-critical function in v2.4.0 and it shipped with no
 * direct test: it was covered only incidentally through ClientIpTest. A FALSE
 * POSITIVE here means an untrusted peer is treated as a trusted proxy, which
 * unlocks the entire X-Forwarded-For path and hands an attacker control of the
 * identity that rate limiting and the audit log record. So the bias of this
 * file is deliberate — most cases assert that something is NOT trusted.
 *
 * Case list from the cluster-2 security audit, which fuzzed this function with
 * 300k randomized differential cases against an independent reference and found
 * no deviations; these pin the edges that fuzzing found interesting so a future
 * change cannot quietly lose them.
 */
final class CidrTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function untrustedProvider(): array
    {
        return [
            // Malformed prefix lengths — ctype_digit() is the guard.
            'negative prefix' => ['1.2.3.4', '10.0.0.0/-1'],
            'signed prefix' => ['1.2.3.4', '10.0.0.0/+8'],
            'space before prefix' => ['1.2.3.4', '10.0.0.0/ 8'],
            'space after prefix' => ['1.2.3.4', '10.0.0.0/8 '],
            'double slash' => ['1.2.3.4', '10.0.0.0//8'],
            'two prefixes' => ['1.2.3.4', '1.2.3.4/8/16'],
            'empty prefix' => ['1.2.3.4', '1.2.3.4/'],
            'no base address' => ['1.2.3.4', '/8'],
            'prefix out of range v4' => ['1.2.3.4', '1.2.3.0/33'],
            'prefix out of range v6' => ['::1', '::/129'],
            'absurd prefix saturates' => ['1.2.3.4', '1.2.3.0/999999999999999999999999'],

            // Malformed addresses — inet_pton() is strict in PHP 8.
            'leading zero octet' => ['1.2.3.4', '01.2.3.0/24'],
            'whitespace in range' => ['1.2.3.4', ' 1.2.3.0/24'],
            'newline in range' => ['1.2.3.4', "1.2.3.0/24\n"],
            'port suffix' => ['1.2.3.4', '1.2.3.4:80'],
            'bracketed v6' => ['::1', '[::1]'],
            'shorthand v4' => ['127.0.0.1', '127.1'],
            'integer form' => ['127.0.0.1', '2130706433'],
            'hex octet' => ['127.0.0.1', '0x7f.0.0.1'],
            'zone id' => ['fe80::1', 'fe80::1%eth0'],
            'empty ip' => ['', '10.0.0.0/8'],
            'empty range' => ['1.2.3.4', ''],
            'both empty' => ['', ''],

            // Cross-family confusion — an IPv4-mapped IPv6 address must NOT be
            // matched against an IPv4 range, in either direction. This is the
            // bypass class the binary comparison exists to prevent.
            'mapped v6 against v4 range' => ['::ffff:10.0.0.1', '10.0.0.0/8'],
            'v4 against mapped v6 range' => ['10.0.0.1', '::ffff:10.0.0.0/104'],
            'v6 against v4 default route' => ['::1', '0.0.0.0/0'],
            'v4 against v6 default route' => ['1.2.3.4', '::/0'],

            // Ordinary misses.
            'just outside the range' => ['11.0.0.1', '10.0.0.0/8'],
            'narrow prefix excludes neighbour' => ['1.2.4.1', '1.2.3.0/24'],
            'bare entry does not cover neighbours' => ['10.0.0.2', '10.0.0.1'],
            'sub-byte boundary just outside' => ['10.0.128.1', '10.0.0.0/17'],
        ];
    }

    /** @dataProvider untrustedProvider */
    public function test_it_does_not_trust(string $ip, string $range): void
    {
        $this->assertFalse(
            NTDST_Cidr::contains($ip, $range),
            sprintf('contains(%s, %s) granted trust it should not have.', var_export($ip, true), var_export($range, true)),
        );
    }

    /** @return array<string, array{string, string}> */
    public static function trustedProvider(): array
    {
        return [
            'inside a v4 range' => ['10.5.5.5', '10.0.0.0/8'],
            'inside a v6 range' => ['2001:db8::1', '2001:db8::/32'],
            'bare v4 exact match' => ['127.0.0.1', '127.0.0.1'],
            'bare v6 exact match' => ['::1', '::1'],
            'v4 /32 boundary' => ['1.2.3.4', '1.2.3.4/32'],
            'v6 /128 boundary' => ['::1', '::1/128'],
            'v4 default route matches any v4' => ['203.0.113.9', '0.0.0.0/0'],
            'non-zero base with /0 still matches' => ['203.0.113.9', '5.6.7.8/0'],
            'sub-byte prefix inside' => ['10.0.127.255', '10.0.0.0/17'],
            'leading-zero prefix parses as decimal' => ['1.2.3.9', '1.2.3.0/024'],
        ];
    }

    /** @dataProvider trustedProvider */
    public function test_it_trusts(string $ip, string $range): void
    {
        $this->assertTrue(
            NTDST_Cidr::contains($ip, $range),
            sprintf('contains(%s, %s) withheld trust it should have granted.', var_export($ip, true), var_export($range, true)),
        );
    }
}
