<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Optional list of the addresses and ranges members may sign in from.
 * Adapted from the moutonnoireu/erugo-security fork.
 */
class IpAllowlist
{
    public const SETTING_ENABLED = 'login_ip_allowlist_enabled';
    public const SETTING_LIST = 'login_ip_allowlist';

    public function __construct(private SettingsService $settings)
    {
    }

    public function isEnabled(): bool
    {
        return $this->settings->get(self::SETTING_ENABLED) === true;
    }

    public function entries(): array
    {
        $value = $this->settings->get(self::SETTING_LIST);

        return self::parse(is_string($value) ? $value : null);
    }

    public function blocks(?string $ip): bool
    {
        return $this->isEnabled() && !self::matches($ip, $this->entries());
    }

    /**
     * One entry per line or separated by commas, "#" starts a comment.
     * The settings form sends the string "null" back for an empty value.
     */
    public static function parse(?string $value): array
    {
        if ($value === null || $value === 'null') {
            return [];
        }

        $entries = [];
        foreach (preg_split('/\R/', $value) as $line) {
            $withoutComment = explode('#', $line, 2)[0];
            foreach (explode(',', $withoutComment) as $entry) {
                $entries[] = trim($entry);
            }
        }

        return array_values(array_unique(array_filter($entries, fn (string $entry) => $entry !== '')));
    }

    public static function invalidEntries(array $entries): array
    {
        return array_values(array_filter($entries, fn (string $entry) => !self::isValidEntry($entry)));
    }

    public static function isValidEntry(string $entry): bool
    {
        $parts = explode('/', $entry);

        if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if (count($parts) === 1) {
            return true;
        }

        $largestMask = str_contains($parts[0], ':') ? 128 : 32;

        return ctype_digit($parts[1]) && (int) $parts[1] <= $largestMask;
    }

    public static function matches(?string $ip, array $entries): bool
    {
        if ($ip === null) {
            return false;
        }

        // Some proxies report IPv4 clients in their IPv6 form (::ffff:203.0.113.10)
        if (str_starts_with(strtolower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $ip = substr($ip, 7);
        }

        return IpUtils::checkIp($ip, $entries);
    }
}
