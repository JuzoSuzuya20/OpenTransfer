<?php

use Illuminate\Support\Facades\Storage;
use App\Models\Setting;
use App\Models\User;
use App\Services\SettingsService;

// Chargé par Composer (autoload "files") : la fonction existe même quand les
// routes sont servies depuis le cache, où routes/web.php n'est plus inclus.
if (!function_exists('getSettings')) {
function getSettings()
{
    
    $settingsService = new SettingsService();

    
    
    $settings = Setting::whereLike('group', 'ui%')
        ->orWhere('key', 'default_language')
        ->orWhere('key', 'show_language_selector')
        ->orWhere('key', 'allow_reverse_shares')
        ->orWhere('key', 'max_expiry_time')
        ->orWhere('key', 'default_expiry_time')
        ->orWhere('key', 'passkey_login_enabled')
        ->orWhere('key', 'altcha_enabled')
        ->orWhere('key', 'sso_required_domains')
        ->get();

    $settings = $settings->map(function ($setting) use ($settingsService) {
        return [
            'key' => $setting->key,
            'value' => $settingsService->convertToCorrectType($setting->value)
        ];
    });

    $indexedSettings = [];
    foreach ($settings as $setting) {
        $indexedSettings[$setting['key']] = $setting['value'];
    }

    //have we any users in the database?
    $userCount = User::count();
    $indexedSettings['setup_needed'] = $userCount > 0 ? false : true;

    //grab the app url from env
    $appURL = config('app.url');
    $indexedSettings['api_url'] = $appURL;

    // The logo is still one that shipped with the application (OpenTransfer, or Erugo before
    // it): the header then draws the OpenTransfer mark.
    $indexedSettings['default_logo'] = usesDefaultLogo();

    // Add app version
    $indexedSettings['version'] = config('app.version');

    // The identity provider the sign-in page leads straight to, if an admin forced one
    $indexedSettings['sso_forced_login'] = app(\App\Services\SsoPolicy::class)->forcedLoginUrl();

    return $indexedSettings;
}
}

if (!function_exists('usesDefaultLogo')) {
function usesDefaultLogo(): bool
{
    // One of the logos shipped (App\Support\DefaultLogo), or none at all
    $disk = Storage::disk('public');
    if (!$disk->exists('images/logo.png')) {
        return true;
    }

    return \App\Support\DefaultLogo::isShipped($disk->get('images/logo.png'));
}
}
