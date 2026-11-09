<?php

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

    // Add app version
    $indexedSettings['version'] = config('app.version');

    return $indexedSettings;
}
}
