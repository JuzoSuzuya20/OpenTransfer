<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * What a member changes on their account (Mon profil): their address, their password, the link to
 * an identity provider. An admin can turn each off (Administration > SSO). An account that signs
 * in through the identity provider alone (a forced provider, a domain that requires it: SsoPolicy)
 * changes none of them, whatever the settings: its address and its way in are those of the
 * provider. Admins keep all three, for the way in of /?noAUTO; a password an admin asked to change
 * (must_change_password) can always be changed. The guests of a file request change nothing.
 */
class AccountPolicy
{
    public const CHANGE_EMAIL = 'members_change_email';

    public const CHANGE_PASSWORD = 'members_change_password';

    public const UNLINK_PROVIDERS = 'members_unlink_providers';

    public function __construct(private SettingsService $settings, private SsoPolicy $sso)
    {
    }

    public function canChangeEmail(User $user): bool
    {
        return $this->allows($user, self::CHANGE_EMAIL);
    }

    public function canChangePassword(User $user): bool
    {
        if ($user->must_change_password && !$user->is_guest && !$this->sso->forbidsPassword($user)) {
            return true;
        }

        return $this->allows($user, self::CHANGE_PASSWORD);
    }

    public function canUnlinkProviders(User $user): bool
    {
        return $this->allows($user, self::UNLINK_PROVIDERS);
    }

    /** @return array{change_email: bool, change_password: bool, unlink_providers: bool} what the profile offers */
    public function forUser(User $user): array
    {
        return [
            'change_email' => $this->canChangeEmail($user),
            'change_password' => $this->canChangePassword($user),
            'unlink_providers' => $this->canUnlinkProviders($user),
        ];
    }

    /** The refusal: the identity provider holds it, or an admin turned it off */
    public function refusal(User $user): JsonResponse
    {
        if ($this->sso->forbidsPassword($user)) {
            return SsoPolicy::refusal();
        }

        return response()->json([
            'status' => 'error',
            'code' => 'account_change_disabled',
            'message' => 'An administrator turned this change off',
        ], 403);
    }

    private function allows(User $user, string $setting): bool
    {
        if ($user->is_guest) {
            return false;
        }
        if ($user->admin) {
            return true;
        }
        if ($this->sso->forbidsPassword($user)) {
            return false;
        }

        return filter_var($this->settings->get($setting) ?? true, FILTER_VALIDATE_BOOLEAN);
    }
}
