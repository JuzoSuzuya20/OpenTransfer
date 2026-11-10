<?php

use Illuminate\Support\Facades\Route;
use App\Models\Setting;
use App\Models\User;
use App\Mail\shareDownloadedMail;
use App\Jobs\sendEmail;
use App\Models\Share;
use App\Http\Controllers\ExternalAuthController;
use App\Services\SettingsService;

// The screens of the application: the page is the same, the front end shows the one its address names
$appPage = function () {
    $indexedSettings = getSettings();

    //grab the app url from env
    $indexedSettings['api_url'] = config('app.url');

    return view('app', ['settings' => $indexedSettings]);
};

Route::get('/', $appPage);
Route::get('/my-shares', $appPage);
Route::get('/requests', $appPage);
Route::get('/stats', $appPage);
Route::get('/profile', $appPage);
Route::get('/about', $appPage);
Route::get('/admin/{section?}', $appPage)->whereIn('section', ['system', 'sso', 'branding', 'email-templates', 'users', 'all-shares']);

Route::get('/reset-password/{token}', function ($token) {
    $indexedSettings = getSettings();

    $indexedSettings['token'] = $token;



    return view('app', ['settings' => $indexedSettings]);
});

Route::get('/shares/{share}', function ($shareId) {
    // Detect CLI tools (curl, wget, etc.) and serve file directly
    $userAgent = request()->userAgent() ?? '';
    $cliPatterns = [
        '/^curl\//i',
        '/^wget\//i',
        '/^libcurl/i',
        '/^Wget/i',
        '/^HTTPie\//i',
    ];
    
    $isCli = false;
    foreach ($cliPatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            $isCli = true;
            break;
        }
    }
    
    if ($isCli) {
        // Serve file directly (curl uses Content-Disposition with -OJ flag)
        $controller = app(\App\Http\Controllers\SharesController::class);
        return $controller->download($shareId);
    }

    $indexedSettings = getSettings();


    return view('app', ['settings' => $indexedSettings]);
});

// Direct download route with filename in URL (for wget compatibility - wget uses URL path for filename)
Route::get('/shares/{share}/{filename}', function ($shareId, $filename) {
    $controller = app(\App\Http\Controllers\SharesController::class);
    return $controller->download($shareId);
})->where('filename', '[^/]+'); // Only match single filename, not paths

// Download specific file from a multi-file share (supports nested paths)
Route::get('/shares/{share}/file/{filepath}', function ($shareId, $filepath) {
    $controller = app(\App\Http\Controllers\SharesController::class);
    return $controller->downloadFile($shareId, $filepath);
})->where('filepath', '.*');


//auth provider login
Route::get('/auth/provider/{provider}/login', [ExternalAuthController::class, 'providerLogin'])
    ->name('social.provider.login');

//auth provider callback
Route::get('/auth/provider/{provider}/callback', [ExternalAuthController::class, 'providerCallback'])
    ->name('social.provider.callback');

//auth provider link account
Route::get('/auth/provider/{provider}/link', [ExternalAuthController::class, 'linkAccount'])
    ->name('social.provider.link');
