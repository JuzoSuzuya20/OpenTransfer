<?php

namespace Tests\Unit;

use App\Support\FileSize;
use PHPUnit\Framework\TestCase;

// The emails write a size as the screens do (resources/js/lib/fileSize.js, the same cases): the
// units and the decimal separator of the reader's language, the decimals the size needs.
class FileSizeTest extends TestCase
{
    private const NBSP = "\u{00A0}";
    private const MB = 1024 * 1024;
    private const GB = 1024 * 1024 * 1024;

    public function test_french_reads_octets_ko_mo_go_with_a_comma(): void
    {
        $this->assertSame('486' . self::NBSP . 'Mo', FileSize::human(486 * self::MB, 'fr'));
        $this->assertSame('1,35' . self::NBSP . 'Go', FileSize::human(1.35 * self::GB, 'fr'));
        $this->assertSame('512' . self::NBSP . 'octets', FileSize::human(512, 'fr'));
    }

    public function test_english_reads_bytes_kb_mb_gb_with_a_point(): void
    {
        $this->assertSame('612' . self::NBSP . 'MB', FileSize::human(612 * self::MB, 'en'));
        $this->assertSame('1.5' . self::NBSP . 'KB', FileSize::human(1536, 'en'));
        $this->assertSame('0' . self::NBSP . 'bytes', FileSize::human(0, 'en'));
    }

    public function test_the_decimals_follow_the_size(): void
    {
        $this->assertSame('2,5' . self::NBSP . 'GB', FileSize::human(2.5 * self::GB, 'de'));
        $this->assertSame('48.3' . self::NBSP . 'MB', FileSize::human(48.25 * self::MB, 'en'));
        $this->assertSame('280' . self::NBSP . 'MB', FileSize::human(280.4 * self::MB, 'en'));
    }

    public function test_an_unknown_language_reads_as_english(): void
    {
        $this->assertSame('3' . self::NBSP . 'MB', FileSize::human(3 * self::MB, 'xx'));
        $this->assertSame('3' . self::NBSP . 'MB', FileSize::human(3 * self::MB));
    }
}
