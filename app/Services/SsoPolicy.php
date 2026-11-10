<?php

namespace App\Services;

use App\Models\User;

/**
 * The accounts of the organisation sign in through its identity provider. An admin lists the
 * email domains concerned; their members cannot open a session with a password, a reset link,
 * a sign-up or a passkey alone. Admins keep their password, with a second factor, so that a
 * broken identity provider can still be repaired. Reverse share guests are not concerned.
 */
class SsoPolicy
{
    public const SETTING_DOMAINS = 'sso_required_domains';

    // One domain name, its labels separated by dots: entreprise.fr, mairie-ville.fr
    private const DOMAIN = '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/';

    public function __construct(private SettingsService $settings)
    {
    }

    /** @return string[] the domains as typed, lower-cased, without spaces or a leading @ */
    public static function parse(?string $value): array
    {
        $domains = preg_split('/[\s,;]+/', strtolower((string) $value), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique(array_map(fn ($domain) => ltrim($domain, '@'), $domains)));
    }

    /** @return string[] the entries that are not domain names */
    public static function invalid(?string $value): array
    {
        return array_values(array_filter(self::parse($value), fn ($domain) => !preg_match(self::DOMAIN, $domain)));
    }

    /** @return string[] */
    public function domains(): array
    {
        return self::parse($this->settings->get(self::SETTING_DOMAINS));
    }

    // Exact domain only: sous.entreprise.fr is another domain, to be listed on its own
    public function requiresSso(?string $email): bool
    {
        $at = strrpos((string) $email, '@');
        if ($at === false) {
            return false;
        }

        return in_array(strtolower(substr($email, $at + 1)), $this->domains(), true);
    }

    public function forbidsPassword(User $user): bool
    {
        return !$user->is_guest && !$user->admin && $this->requiresSso($user->email);
    }

    // The admins of a required domain keep their password only with a second factor
    public function requiresSecondFactor(User $user): bool
    {
        return !$user->is_guest && (bool) $user->admin && $this->requiresSso($user->email);
    }

    public static function refusal(int $status = 403)
    {
        return response()->json([
            'status' => 'error',
            'code' => 'sso_required',
            'message' => 'This account signs in through the identity provider of the organisation',
        ], $status);
    }
}
