<?php

namespace Tests\Unit;

use App\Services\IpAllowlist;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class IpAllowlistTest extends TestCase
{
    public function test_parse_accepts_lines_commas_comments_and_duplicates(): void
    {
        $value = "203.0.113.10 # head office\r\n\r\n10.0.0.0/8, 192.0.2.1\n# whole line comment\n203.0.113.10\n  2001:db8::/32  ";

        $this->assertSame(['203.0.113.10', '10.0.0.0/8', '192.0.2.1', '2001:db8::/32'], IpAllowlist::parse($value));
    }

    /**
     * The settings form sends "null" back when it reloaded an empty value.
     */
    #[DataProvider('emptyValues')]
    public function test_parse_treats_empty_values_as_no_entries(?string $value): void
    {
        $this->assertSame([], IpAllowlist::parse($value));
    }

    public static function emptyValues(): array
    {
        return ['null' => [null], 'null string' => ['null'], 'empty' => [''], 'blank' => [" \n "], 'comment only' => ['# nothing']];
    }

    #[DataProvider('entries')]
    public function test_only_addresses_and_ranges_are_valid_entries(string $entry, bool $valid): void
    {
        $this->assertSame($valid, IpAllowlist::isValidEntry($entry));
    }

    public static function entries(): array
    {
        return [
            'ipv4' => ['203.0.113.10', true],
            'ipv4 range' => ['10.0.0.0/8', true],
            'ipv4 largest mask' => ['203.0.113.10/32', true],
            'ipv6' => ['2001:db8::1', true],
            'ipv6 range' => ['2001:db8::/32', true],
            'ipv6 largest mask' => ['2001:db8::1/128', true],
            'text' => ['not-an-ip', false],
            'partial address' => ['10.0.0', false],
            'out of range octet' => ['300.0.0.1', false],
            'ipv4 mask too large' => ['10.0.0.0/33', false],
            'ipv6 mask too large' => ['2001:db8::/129', false],
            'negative mask' => ['10.0.0.0/-1', false],
            'text mask' => ['10.0.0.0/abc', false],
            'empty mask' => ['10.0.0.0/', false],
            'two masks' => ['10.0.0.0/8/8', false],
            'wildcard' => ['*', false],
        ];
    }

    public function test_invalid_entries_are_reported_one_by_one(): void
    {
        $this->assertSame(['nope', '10.0.0.0/99'], IpAllowlist::invalidEntries(['10.0.0.1', 'nope', '10.0.0.0/99']));
    }

    #[DataProvider('addressesToMatch')]
    public function test_an_address_matches_its_exact_entry_or_its_range(?string $ip, bool $matches): void
    {
        $this->assertSame($matches, IpAllowlist::matches($ip, ['203.0.113.10', '10.0.0.0/8', '2001:db8::/32']));
    }

    public static function addressesToMatch(): array
    {
        return [
            'exact address' => ['203.0.113.10', true],
            'neighbour of an exact address' => ['203.0.113.11', false],
            'inside a range' => ['10.20.30.40', true],
            'just outside a range' => ['11.0.0.0', false],
            'inside an ipv6 range' => ['2001:db8:1::5', true],
            'outside an ipv6 range' => ['2001:db9::5', false],
            // Some proxies report IPv4 clients in their IPv6 form
            'ipv4-mapped form of a listed address' => ['::ffff:203.0.113.10', true],
            'ipv4-mapped form of an unlisted address' => ['::ffff:198.51.100.7', false],
            'unknown address' => [null, false],
        ];
    }

    public function test_nothing_matches_an_empty_list(): void
    {
        $this->assertFalse(IpAllowlist::matches('203.0.113.10', []));
    }
}
