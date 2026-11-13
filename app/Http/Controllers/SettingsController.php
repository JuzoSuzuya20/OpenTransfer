<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Setting;
use App\Services\Altcha;
use App\Services\IpAllowlist;
use App\Services\StorageEncryption;
use App\Services\TwoFactor;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use App\Utils\FileHelper;
use App\Services\SettingsService;
use App\Services\PatternGenerator;
use Illuminate\Support\Str;
class SettingsController extends Controller
{
    public function write(Request $request)
    {
        $request->validate([
            'settings' => 'required|array',
            'settings.*.key' => 'required|string|max:255',
            'settings.*.value' => 'string|nullable|max:65535',
        ]);

        // An empty setting is kept empty: Laravel turns an empty field into null, which the
        // screen sent back as the text "null" on its next save, where the checks below read it
        // as an entry ("Not a domain name: null") and nothing could be saved any more
        $request->merge(['settings' => array_map(
            fn (array $setting) => in_array($setting['value'] ?? null, [null, 'null'], true) ? ['key' => $setting['key'], 'value' => ''] : $setting,
            $request->input('settings')
        )]);

        // Before anything is saved: a refused request must not be half applied
        $refusal = $this->refuseIpAllowlist($request) ?? $this->refuseMfaPolicy($request) ?? $this->refuseStorageEncryption($request) ?? $this->refuseAltchaDifficulty($request) ?? $this->refuseSsoDomains($request) ?? $this->refuseWeakSharePattern($request);
        if ($refusal) {
            return $refusal;
        }

        $errors = [];
        $savedSettings = [];

        foreach ($request->settings as $settingData) {
            try {
                $setting = Setting::where('key', $settingData['key'])->first();
                
                if (!$setting) {
                    throw new \Exception('Setting does not exist');
                }

                $setting->key = $settingData['key'];
                $setting->previous_value = $setting->value;
                $setting->value = $settingData['value'];
                $setting->save();

                $savedSettings[] = $setting;
            } catch (\Exception $e) {
                Log::error('Failed to save setting', ['key' => $settingData['key'], 'error' => $e->getMessage()]);
                $errors[] = [
                    'key' => $settingData['key'],
                    'error' => 'Could not save this setting'
                ];
            }
        }

        if (!empty($errors)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Some settings could not be saved',
                'errors' => $errors,
            ], 422);
        }

        // Clear settings cache after successful save
        app(SettingsService::class)->clearCache();

        return response()->json([
            'status' => 'success',
            'message' => 'Settings saved successfully',
            'data' => [
                'settings' => $savedSettings,
            ]
        ]);
    }

    /**
     * Refuses a list with invalid entries, and a change that would lock out
     * the admin making it (the disable-ip-allowlist command is the way back).
     */
    private function refuseIpAllowlist(Request $request): ?JsonResponse
    {
        $incoming = collect($request->settings)->pluck('value', 'key');

        if (!$incoming->hasAny([IpAllowlist::SETTING_ENABLED, IpAllowlist::SETTING_LIST])) {
            return null;
        }

        $allowlist = app(IpAllowlist::class);
        $entries = $incoming->has(IpAllowlist::SETTING_LIST)
            ? IpAllowlist::parse($incoming->get(IpAllowlist::SETTING_LIST))
            : $allowlist->entries();
        $enabled = $incoming->has(IpAllowlist::SETTING_ENABLED)
            ? $incoming->get(IpAllowlist::SETTING_ENABLED) === 'true'
            : $allowlist->isEnabled();

        $invalid = IpAllowlist::invalidEntries($entries);
        if ($invalid) {
            return response()->json([
                'status' => 'error',
                'code' => 'ip_allowlist_invalid',
                'message' => 'The IP allowlist contains invalid entries',
                'data' => ['invalid' => $invalid],
            ], 422);
        }

        if ($enabled && !IpAllowlist::matches($request->ip(), $entries)) {
            return response()->json([
                'status' => 'error',
                'code' => 'ip_allowlist_lockout',
                'message' => 'This IP allowlist would lock you out: it does not include your address',
                'data' => ['ip' => $request->ip()],
            ], 422);
        }

        return null;
    }

    // A mistyped domain would silently leave its accounts on their passwords
    private function refuseSsoDomains(Request $request): ?JsonResponse
    {
        $incoming = collect($request->settings)->pluck('value', 'key');
        if (!$incoming->has(\App\Services\SsoPolicy::SETTING_DOMAINS)) {
            return null;
        }

        $invalid = \App\Services\SsoPolicy::invalid($incoming->get(\App\Services\SsoPolicy::SETTING_DOMAINS));
        if ($invalid === []) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'sso_domains_invalid',
            'message' => 'Not a domain name: ' . implode(', ', $invalid),
            'data' => ['invalid' => $invalid],
        ], 422);
    }

    private function refuseMfaPolicy(Request $request): ?JsonResponse
    {
        $incoming = collect($request->settings)->pluck('value', 'key');

        if (!$incoming->has(TwoFactor::SETTING_REQUIRED_FOR)
            || in_array($incoming->get(TwoFactor::SETTING_REQUIRED_FOR), TwoFactor::POLICIES, true)) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'mfa_policy_invalid',
            'message' => 'Unknown two-factor policy',
        ], 422);
    }

    /**
     * Without a key, turning it on would silently leave new shares in plain text.
     */
    private function refuseStorageEncryption(Request $request): ?JsonResponse
    {
        $incoming = collect($request->settings)->pluck('value', 'key');

        if ($incoming->get(StorageEncryption::SETTING_ENABLED) !== 'true' || app(StorageEncryption::class)->hasKey()) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'storage_key_missing',
            'message' => 'Set the STORAGE_ENCRYPTION_KEY environment variable before turning this on',
        ], 422);
    }

    /**
     * In the "pattern" mode the links of the shares follow the pattern of the admin: one too short
     * would let them be guessed (see PatternGenerator::MIN_BITS).
     */
    private function refuseWeakSharePattern(Request $request): ?JsonResponse
    {
        $incoming = collect($request->settings)->pluck('value', 'key');
        if (!$incoming->hasAny(['share_url_mode', 'share_url_pattern'])) {
            return null;
        }

        $settings = app(SettingsService::class);
        $mode = $incoming->get('share_url_mode', $settings->get('share_url_mode'));
        $pattern = (string) $incoming->get('share_url_pattern', $settings->get('share_url_pattern'));
        $generator = new PatternGenerator();
        if ($mode !== 'pattern' || $generator->isStrongEnough($pattern)) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'share_url_pattern_weak',
            'message' => 'This pattern gives links that could be guessed: it needs ' . PatternGenerator::MIN_BITS . ' bits of chance',
            'data' => ['bits' => round($generator->bits($pattern), 1), 'minimum_bits' => PatternGenerator::MIN_BITS],
        ], 422);
    }

    private function refuseAltchaDifficulty(Request $request): ?JsonResponse
    {
        $incoming = collect($request->settings)->pluck('value', 'key');

        if (!$incoming->has(Altcha::SETTING_MAX_NUMBER)) {
            return null;
        }

        $value = $incoming->get(Altcha::SETTING_MAX_NUMBER);
        if (is_string($value) && ctype_digit($value) && (int) $value >= Altcha::MIN_MAX_NUMBER && (int) $value <= Altcha::MAX_MAX_NUMBER) {
            return null;
        }

        return response()->json([
            'status' => 'error',
            'code' => 'altcha_difficulty_invalid',
            'message' => 'The difficulty must be between '.Altcha::MIN_MAX_NUMBER.' and '.Altcha::MAX_MAX_NUMBER,
        ], 422);
    }

    public function storageEncryptionStatus()
    {
        $encryption = app(StorageEncryption::class);

        return response()->json([
            'status' => 'success',
            'data' => [
                'key_configured' => $encryption->hasKey(),
                'misconfigured' => $encryption->isMisconfigured(),
            ]
        ]);
    }

    /**
     * The address the application sees for the admin, as the allowlist will
     * see it: behind a proxy, it shows whether TRUSTED_PROXIES is right.
     */
    public function clientIp(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => [
                'ip' => $request->ip(),
            ]
        ]);
    }

    /**
     * Settings only admins may read. These read routes are open to every
     * signed-in account, reverse-share guests included: the SMTP group holds
     * the mail server credentials, the security group the company's networks.
     */
    private const ADMIN_ONLY_GROUPS = ['system.smtp', 'system.security'];

    private function withoutAdminOnlySettings($query, Request $request)
    {
        if ($request->user()->admin) {
            return $query;
        }

        return $query->whereNotIn('group', self::ADMIN_ONLY_GROUPS)
            ->where('key', 'not like', 'smtp%');
    }

    public function read(Request $request, $key)
    {
        $setting = $this->withoutAdminOnlySettings(Setting::where('key', $key), $request)->first();
        if (!$setting) {
            return response()->json([
                'status' => 'error',
                'message' => 'Setting not found',
            ], 404);
        }
        return response()->json([
            'status' => 'success',
            'data' => [
                'setting' => $setting,
            ]
        ]);
    }

    public function readGroup(Request $request, $group)
    {

        $query = Setting::query();

        if (str_ends_with($group, '.*')) {
            // For patterns like "general.*"
            $baseGroup = rtrim($group, '.*');
            $query->where(function ($q) use ($baseGroup) {
                $q->where('group', $baseGroup)  // Matches exact base group
                    ->orWhere('group', 'LIKE', $baseGroup . '.%');  // Matches anything with baseGroup.
            });
        } else {
            // For exact matches like "general" or "general.shares"
            $query->where('group', $group);
        }

        $settings = $this->withoutAdminOnlySettings($query, $request)->get();

        return response()->json([
            'status' => 'success',
            'data' => [
                'settings' => $settings,
            ]
        ]);
    }

    // The image behind the sign-in screen. An image only, never an SVG (it would carry script), of a
    // bounded weight and size so that resizing it cannot exhaust the memory. Stored under a random
    // name (no stale cache, nothing guessed from the upload's name); the previous one goes.
    public function writeLoginBackground(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'background' => 'required|file|image|mimes:jpg,jpeg,png,webp|max:10240|dimensions:max_width=8000,max_height=8000',
        ]);
        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $image = $request->file('background');
        $extension = strtolower($image->extension()) === 'jpeg' ? 'jpg' : strtolower($image->extension());
        $file = 'login-' . Str::lower(Str::random(16)) . '.' . $extension;

        if (!Storage::disk('backgrounds')->putFileAs('', $image, $file)) {
            return response()->json(['status' => 'error', 'message' => 'Failed to save the image'], 500);
        }

        $previous = $this->setLoginBackground($file);
        $this->removeBackgroundFile($previous);

        return response()->json([
            'status' => 'success',
            'message' => 'Sign-in background saved',
            'data' => ['file' => $file],
        ]);
    }

    public function deleteLoginBackground()
    {
        $this->removeBackgroundFile($this->setLoginBackground(''));

        return response()->json(['status' => 'success', 'message' => 'Sign-in background removed']);
    }

    // Saves the new value and returns the file it replaces
    private function setLoginBackground(string $file): string
    {
        $setting = Setting::firstOrCreate(['key' => 'login_background'], ['value' => '', 'group' => 'ui.branding']);
        $previous = (string) $setting->value;
        $setting->previous_value = $previous;
        $setting->value = $file;
        $setting->save();
        app(SettingsService::class)->clearCache();

        return $previous;
    }

    private function removeBackgroundFile(string $file): void
    {
        // Only a file this endpoint wrote: never a path
        if (!preg_match('/^login-[a-z0-9]{16}\.(jpg|png|webp)$/', $file)) {
            return;
        }
        $disk = Storage::disk('backgrounds');
        $disk->delete([$file, 'cache/' . $file, 'cache/thumbs/' . pathinfo($file, PATHINFO_FILENAME) . '.webp']);
    }

    public function writeLogo(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'logo' => 'required|image:allow_svg|mimes:png,svg|max:2048',
        ]);

        if ($validator->fails()) {
            // Map validation rule to error code for frontend translation
            $failedRules = $validator->failed();
            $errorCode = 'validation_failed';
            
            if (isset($failedRules['logo']['Max'])) {
                $errorCode = 'file_too_large';
            } elseif (isset($failedRules['logo']['Mimes'])) {
                $errorCode = 'invalid_file_type';
            } elseif (isset($failedRules['logo']['Image'])) {
                $errorCode = 'invalid_image';
            } elseif (isset($failedRules['logo']['Required'])) {
                $errorCode = 'file_required';
            }

            return response()->json([
                'status' => 'error',
                'error_code' => $errorCode,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $logo = $request->file('logo');
        
        try {
            // Store the logo as logo.png in storage/app/public/images (symlinked to public/images)
            $stored = Storage::disk('public')->put('images/logo.png', file_get_contents($logo));
            
            if (!$stored) {
                Log::error('Failed to store logo file');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Failed to save logo file',
                ], 500);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Logo updated successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Logo upload error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to save logo',
            ], 500);
        }
    }

    public function deleteLogo()
    {
        try {
            // Check if default logo exists in storage
            if (!Storage::disk('public')->exists('images/_default-logo.png')) {
                Log::error('Default logo not found in storage');
                return response()->json([
                    'status' => 'error',
                    'message' => 'Default logo not found',
                ], 404);
            }
            
            // Copy the default logo to restore it
            $defaultLogo = Storage::disk('public')->get('images/_default-logo.png');
            Storage::disk('public')->put('images/logo.png', $defaultLogo);

            // Update the logo setting to show the default logo filename
            $logoSetting = Setting::where('key', 'logo')->where('group', 'ui.logo')->first();
            if ($logoSetting) {
                $logoSetting->previous_value = $logoSetting->value;
                $logoSetting->value = 'erugo-logo.png';
                $logoSetting->save();
                
                // Clear settings cache after modifying setting
                app(SettingsService::class)->clearCache();
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Logo reset to default successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Logo reset error: ' . $e->getMessage());
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset logo',
            ], 500);
        }
    }

    public function writeFavicon(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'favicon' => 'required|file|mimes:png,svg|max:1024',
        ]);

        if ($validator->fails()) {
            // Map validation rule to error code for frontend translation
            $failedRules = $validator->failed();
            $errorCode = 'validation_failed';
            
            if (isset($failedRules['favicon']['Max'])) {
                $errorCode = 'file_too_large';
            } elseif (isset($failedRules['favicon']['Mimes'])) {
                $errorCode = 'invalid_file_type';
            } elseif (isset($failedRules['favicon']['Required'])) {
                $errorCode = 'file_required';
            } elseif (isset($failedRules['favicon']['File'])) {
                $errorCode = 'invalid_file';
            }

            return response()->json([
                'status' => 'error',
                'error_code' => $errorCode,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], 422);
        }

        $favicon = $request->file('favicon');
        $extension = strtolower($favicon->getClientOriginalExtension());
        
        // Always store as favicon.png or favicon.svg
        $filename = 'favicon.' . $extension;
        
        // Delete any existing favicon files first
        if (Storage::disk('public')->exists('favicon.png')) {
            Storage::disk('public')->delete('favicon.png');
        }
        if (Storage::disk('public')->exists('favicon.svg')) {
            Storage::disk('public')->delete('favicon.svg');
        }
        
        // Store the new favicon
        Storage::disk('public')->put($filename, file_get_contents($favicon));

        return response()->json([
            'status' => 'success',
            'message' => 'Favicon updated successfully',
            'data' => [
                'filename' => $filename,
            ]
        ]);
    }

    public function deleteFavicon()
    {
        $deleted = false;
        
        if (Storage::disk('public')->exists('favicon.png')) {
            Storage::disk('public')->delete('favicon.png');
            $deleted = true;
        }
        if (Storage::disk('public')->exists('favicon.svg')) {
            Storage::disk('public')->delete('favicon.svg');
            $deleted = true;
        }

        return response()->json([
            'status' => 'success',
            'message' => $deleted ? 'Favicon deleted successfully' : 'No custom favicon to delete',
        ]);
    }

    public function getFavicon()
    {
        // Check for custom favicon (PNG first, then SVG)
        if (Storage::disk('public')->exists('favicon.png')) {
            $path = Storage::disk('public')->path('favicon.png');
            return response()->file($path, ['Content-Type' => 'image/png']);
        }
        
        if (Storage::disk('public')->exists('favicon.svg')) {
            $path = Storage::disk('public')->path('favicon.svg');
            return response()->file($path, self::SVG_HEADERS);
        }

        // Fall back to default icon.svg
        $defaultPath = public_path('icon.svg');
        if (file_exists($defaultPath)) {
            return response()->file($defaultPath, self::SVG_HEADERS);
        }

        abort(404);
    }

    /**
     * An uploaded SVG can embed scripts: opened directly from our origin it
     * would run as stored XSS. This CSP keeps it a pure image.
     */
    private const SVG_HEADERS = [
        'Content-Type' => 'image/svg+xml',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:",
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function hasFavicon()
    {
        $hasCustomFavicon = Storage::disk('public')->exists('favicon.png') || 
                           Storage::disk('public')->exists('favicon.svg');
        
        $filename = null;
        if (Storage::disk('public')->exists('favicon.png')) {
            $filename = 'favicon.png';
        } elseif (Storage::disk('public')->exists('favicon.svg')) {
            $filename = 'favicon.svg';
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'has_custom_favicon' => $hasCustomFavicon,
                'filename' => $filename,
            ]
        ]);
    }
}
