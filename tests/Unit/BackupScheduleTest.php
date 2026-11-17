<?php

namespace Tests\Unit;

use App\Support\BackupSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

// When the automatic backup runs: off, every day, a day of the week or a day of the month, at a
// time, in the time zone of the instance
class BackupScheduleTest extends TestCase
{
    private function at(string $moment): CarbonImmutable
    {
        return CarbonImmutable::parse($moment, 'UTC');
    }

    public function test_by_default_every_night_at_midnight(): void
    {
        $schedule = new BackupSchedule();

        $this->assertTrue($schedule->isDue($this->at('2026-11-17 00:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-11-17 00:01')));
        $this->assertFalse($schedule->isDue($this->at('2026-11-17 12:00')));
        $this->assertSame('2026-11-18 00:00', $schedule->next($this->at('2026-11-17 09:00'))->format('Y-m-d H:i'));
    }

    public function test_off_never_runs(): void
    {
        $schedule = new BackupSchedule('off');

        $this->assertFalse($schedule->isDue($this->at('2026-11-17 00:00')));
        $this->assertNull($schedule->next($this->at('2026-11-17 09:00')));
    }

    public function test_every_day_at_the_time_chosen(): void
    {
        $schedule = new BackupSchedule('daily', '02:30');

        $this->assertTrue($schedule->isDue($this->at('2026-11-17 02:30')));
        $this->assertFalse($schedule->isDue($this->at('2026-11-17 00:00')));
        $this->assertSame('2026-11-17 02:30', $schedule->next($this->at('2026-11-17 01:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-18 02:30', $schedule->next($this->at('2026-11-17 02:30'))->format('Y-m-d H:i'));
    }

    public function test_once_a_week_on_the_day_chosen(): void
    {
        // 1 is Monday (ISO 8601), 7 Sunday
        $schedule = new BackupSchedule('weekly', '03:00', 1);

        $this->assertTrue($schedule->isDue($this->at('2026-11-23 03:00')));
        $this->assertFalse($schedule->isDue($this->at('2026-11-24 03:00')));
        $this->assertSame('2026-11-23 03:00', $schedule->next($this->at('2026-11-17 09:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-11-22 03:00', (new BackupSchedule('weekly', '03:00', 7))->next($this->at('2026-11-17 09:00'))->format('Y-m-d H:i'));
    }

    public function test_once_a_month_on_the_day_chosen(): void
    {
        $schedule = new BackupSchedule('monthly', '04:15', weekday: 1, monthday: 28);

        $this->assertTrue($schedule->isDue($this->at('2026-12-28 04:15')));
        $this->assertFalse($schedule->isDue($this->at('2026-12-27 04:15')));
        $this->assertSame('2026-11-28 04:15', $schedule->next($this->at('2026-11-17 09:00'))->format('Y-m-d H:i'));
        $this->assertSame('2026-12-28 04:15', $schedule->next($this->at('2026-11-28 05:00'))->format('Y-m-d H:i'));
    }

    public function test_values_that_are_not_offered_fall_back_to_the_default(): void
    {
        $schedule = new BackupSchedule('hourly', '25:99', 9, 31);

        $this->assertTrue($schedule->isDue($this->at('2026-11-17 00:00')));
    }
}
