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

    return $indexedSettings;
}
}

if (!function_exists('usesDefaultLogo')) {
function usesDefaultLogo(): bool
{
    // SHA-256 of the logos shipped as storage_skell/app/public/images/logo.png
    $shipped = [
        'ab4413bc0a608b61bd164da038a0927c63c59882ab4b0ba9aa3aea1d20fb4093', // OpenTransfer
        '768ff36a5c585ee6a643ccd039c82cb126ab040ed11207d2053b50b4f8bef3ee', // Erugo
    ];

    $disk = Storage::disk('public');
    if (!$disk->exists('images/logo.png')) {
        return true;
    }

    return in_array(hash('sha256', $disk->get('images/logo.png')), $shipped, true);
}
}
