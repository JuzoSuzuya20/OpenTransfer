<?php

namespace Tests\Unit;

use App\Services\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    // RFC 6238 appendix B, secret "12345678901234567890" (SHA-1), last 6 digits
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc_test_vectors(int $time, string $code): void
    {
        $this->assertSame($code, Totp::codeAt(self::SECRET, Totp::timestep($time)));
    }

    public static function rfcVectors(): array
    {
        return [
            [59, '287082'],
            [1111111109, '081804'],
            [1111111111, '050471'],
            [1234567890, '005924'],
            [2000000000, '279037'],
        ];
    }

    public function test_a_valid_code_returns_its_time_step(): void
    {
        $this->assertSame(37037036, Totp::verify(self::SECRET, '081804', null, 1111111109));
    }

    public function test_one_step_of_clock_drift_is_accepted_on_each_side(): void
    {
        $step = Totp::timestep(1111111109);

        $this->assertSame($step - 1, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 1), null, 1111111109));
        $this->assertSame($step + 1, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step + 1), null, 1111111109));
    }

    public function test_two_steps_of_drift_are_refused(): void
    {
        $step = Totp::timestep(1111111109);

        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 2), null, 1111111109));
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step + 2), null, 1111111109));
    }

    public function test_a_step_already_used_is_refused(): void
    {
        $step = Totp::timestep(1111111109);

        $this->assertNull(Totp::verify(self::SECRET, '081804', $step, 1111111109));
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, $step - 1), $step, 1111111109));
    }

    public function test_spaces_typed_by_the_user_are_ignored(): void
    {
        $this->assertNotNull(Totp::verify(self::SECRET, '081 804', null, 1111111109));
    }

    #[DataProvider('malformedCodes')]
    public function test_malformed_codes_are_refused(string $code): void
    {
        $this->assertNull(Totp::verify(self::SECRET, $code, null, 1111111109));
    }

    public static function malformedCodes(): array
    {
        return ['letters' => ['abcdef'], 'too short' => ['08180'], 'too long' => ['0818040'], 'empty' => ['']];
    }

    public function test_a_new_secret_is_160_bits_of_base32_and_never_repeats(): void
    {
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', Totp::generateSecret());
        $this->assertNotSame(Totp::generateSecret(), Totp::generateSecret());
    }

    public function test_the_uri_names_the_application_and_the_account(): void
    {
        $this->assertSame(
            'otpauth://totp/My%20Files:me%40example.test?secret=ABC&issuer=My%20Files&algorithm=SHA1&digits=6&period=30',
            Totp::provisioningUri('ABC', 'me@example.test', 'My Files')
        );
    }
}
