<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * When the automatic backup of the database runs (Administration > Backups): off, every day, one
 * day of the week (1 Monday … 7 Sunday) or of the month (1 … 28), at a time, in the time zone of the
 * instance (APP_TIMEZONE). The scheduler asks every minute (routes/console.php): a change applies
 * at once. A value that is not offered is the default: every night at midnight.
 */
final class BackupSchedule
{
    public const FREQUENCIES = ['off', 'daily', 'weekly', 'monthly'];
    public const KEEP_DAYS = [1, 3650];

    private string $frequency;
    private int $hour;
    private int $minute;
    private int $weekday;
    private int $monthday;

    public function __construct(string $frequency = 'daily', string $time = '00:00', int $weekday = 1, int $monthday = 1)
    {
        $this->frequency = in_array($frequency, self::FREQUENCIES, true) ? $frequency : 'daily';
        [$this->hour, $this->minute] = self::isTime($time) ? array_map('intval', explode(':', $time)) : [0, 0];
        $this->weekday = $weekday >= 1 && $weekday <= 7 ? $weekday : 1;
        $this->monthday = $monthday >= 1 && $monthday <= 28 ? $monthday : 1;
    }

    public static function fromSettings(): self
    {
        $settings = app(\App\Services\SettingsService::class);

        return new self(
            (string) $settings->get('backup_frequency'),
            (string) $settings->get('backup_time'),
            (int) $settings->get('backup_weekday'),
            (int) $settings->get('backup_monthday'),
        );
    }

    public static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    public function isOff(): bool
    {
        return $this->frequency === 'off';
    }

    public function isDue(CarbonInterface $at): bool
    {
        return !$this->isOff() && $at->hour === $this->hour && $at->minute === $this->minute && $this->isTheDay($at);
    }

    // The next backup after this moment, or none
    public function next(CarbonInterface $from): ?CarbonInterface
    {
        if ($this->isOff()) {
            return null;
        }
        $candidate = $from->copy()->setTime($this->hour, $this->minute, 0);
        for ($day = 0; $day < 400; $day++) {
            if ($candidate->greaterThan($from) && $this->isTheDay($candidate)) {
                return $candidate;
            }
            $candidate = $candidate->addDay();
        }

        return null;
    }

    private function isTheDay(CarbonInterface $at): bool
    {
        return match ($this->frequency) {
            'weekly' => $at->isoWeekday() === $this->weekday,
            'monthly' => $at->day === $this->monthday,
            default => true,
        };
    }
}
