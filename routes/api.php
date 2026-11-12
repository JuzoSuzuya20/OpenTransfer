<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailChangeController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\UsersController;
use App\Http\Middleware\AdminMiddleware as Admin;
use App\Http\Middleware\RequireAltcha;
use App\Http\Middleware\MembersOnly;
use App\Services\Altcha;
use App\Http\Middleware\NoUsersMiddleware as NoUsers;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SharesController;
use App\Http\Controllers\BackgroundsController;
use App\Services\SettingsService;
use App\Http\Controllers\ThemesController;
use App\Http\Controllers\AuthProvidersController;
use App\Http\Controllers\UploadsController;
use App\Http\Controllers\ReverseSharesController;
use App\Http\Controllers\EmailTemplatesController;
use App\Http\Controllers\TusdHooksController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\SelfRegistrationController;
use App\Http\Controllers\BackupsController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


//route group for auth
Route::group([], function ($router) {

    Route::post('/setup', [UsersController::class, 'createFirstUser'])
        ->name('users.createFirstUser')
        ->middleware(NoUsers::class);

    Route::get('/health', function () {
        return response()->json([
            'status' => 'success',
            'message' => 'OK',
            'data' => [
                'max_share_size' => app(SettingsService::class)->getMaxUploadSize()
            ]
        ]);
    });

    //auth
    Route::group(['prefix' => 'auth'], function ($router) {
        //anti-robot check of the forms open to anyone, when an admin turns it on
        Route::get('challenge', function (Altcha $altcha) {
            abort_unless($altcha->isEnabled(), 404);

            return response()->json(['status' => 'success', 'data' => $altcha->createChallenge()]);
        })->name('auth.challenge')->middleware('throttle:60,1');

        Route::post('login', [AuthController::class, 'login'])->name('auth.login')->middleware(RequireAltcha::class);
        Route::post('refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
        Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
        // forms that send e-mails to anyone: a limit per network address on top of the anti-robot check
        Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->name('auth.forgotPassword')->middleware(['throttle:10,1', RequireAltcha::class]);
        Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('auth.resetPassword');

        //second factor, after a correct password
        Route::post('mfa/verify', [TwoFactorController::class, 'verifyLogin'])->name('auth.mfa.verify');
        Route::post('mfa/enrol/totp/setup', [TwoFactorController::class, 'enrolTotpSetup'])->name('auth.mfa.enrolTotpSetup');
        Route::post('mfa/enrol/totp/confirm', [TwoFactorController::class, 'enrolTotpConfirm'])->name('auth.mfa.enrolTotpConfirm');
        Route::post('mfa/passkey/options', [TwoFactorController::class, 'passkeyLoginOptions'])->name('auth.mfa.passkeyOptions');
        Route::post('mfa/passkey', [TwoFactorController::class, 'passkeyLogin'])->name('auth.mfa.passkey');
        Route::post('mfa/enrol/passkey/options', [TwoFactorController::class, 'enrolPasskeyOptions'])->name('auth.mfa.enrolPasskeyOptions');
        Route::post('mfa/enrol/passkey', [TwoFactorController::class, 'enrolPasskey'])->name('auth.mfa.enrolPasskey');

        //signing in with a passkey alone, when an admin allows it. Open to anyone: limited
        Route::post('passkey/options', [TwoFactorController::class, 'passwordlessOptions'])->name('auth.passkey.options')->middleware('throttle:30,1');
        Route::post('passkey/login', [TwoFactorController::class, 'passwordlessLogin'])->name('auth.passkey.login')->middleware('throttle:30,1');
        
        // Self-registration routes
        Route::post('register', [SelfRegistrationController::class, 'register'])->name('auth.register')->middleware(['throttle:10,1', RequireAltcha::class]);
        Route::post('verify-email', [SelfRegistrationController::class, 'verifyEmail'])->name('auth.verifyEmail');
        Route::post('resend-verification', [SelfRegistrationController::class, 'resendCode'])->name('auth.resendVerification')->middleware(['throttle:10,1', RequireAltcha::class]);
        Route::get('registration-settings', [SelfRegistrationController::class, 'getSettings'])->name('auth.registrationSettings');
    });

    //manage my profile [auth]
    Route::group(['prefix' => 'users/me', 'middleware' => ['auth']], function ($router) {
        Route::get('/', [UsersController::class, 'me'])->name('profile.view');
        // Changing the account and how it signs in: members only, never the guest of a file request
        Route::middleware(MembersOnly::class)->group(function () {
            Route::put('/', [UsersController::class, 'updateMe'])->name('profile.update');
            //a new address, proved by the code sent to it
            Route::post('/email', [EmailChangeController::class, 'request'])->name('profile.email.request')->middleware('throttle:6,1');
            Route::post('/email/confirm', [EmailChangeController::class, 'confirm'])->name('profile.email.confirm');
            Route::delete('/providers/{providerId}', [UsersController::class, 'unlinkProvider'])->name('profile.unlinkProvider');

            //second factor
            Route::get('/2fa', [TwoFactorController::class, 'status'])->name('profile.2fa.status');
            Route::post('/2fa/totp/setup', [TwoFactorController::class, 'totpSetup'])->name('profile.2fa.totpSetup');
            Route::post('/2fa/totp/confirm', [TwoFactorController::class, 'totpConfirm'])->name('profile.2fa.totpConfirm');
            Route::delete('/2fa/totp', [TwoFactorController::class, 'totpDisable'])->name('profile.2fa.totpDisable');
            Route::post('/2fa/recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes'])->name('profile.2fa.recoveryCodes');
            Route::post('/passkeys/options', [TwoFactorController::class, 'passkeyRegistrationOptions'])->name('profile.passkeys.options');
            Route::post('/passkeys', [TwoFactorController::class, 'passkeyRegister'])->name('profile.passkeys.register');
            Route::delete('/passkeys/{id}', [TwoFactorController::class, 'passkeyDelete'])->whereNumber('id')->name('profile.passkeys.delete');
        });
    });

    //manage users [auth, admin]
    Route::group(['prefix' => 'users', 'middleware' => ['auth', Admin::class]], function ($router) {

        //create a new user
        Route::post('/', [UsersController::class, 'create'])->name('users.create');

        //get all users
        Route::get('/', [UsersController::class, 'index'])->name('users.index');

        //update a user
        Route::put('/{id}', [UsersController::class, 'update'])->name('users.update');

        //delete a user
        Route::delete('/{id}', [UsersController::class, 'delete'])->name('users.delete');

        //remove the second factor of a user who lost their device
        Route::delete('/{id}/2fa', [TwoFactorController::class, 'adminReset'])->whereNumber('id')->name('users.reset2fa');

        //force reset a user's password
        Route::post('/{id}/force-reset-password', [UsersController::class, 'forceResetPassword'])->name('users.forceResetPassword');
    });


    //manage settings [auth, admin]
    Route::group(['prefix' => 'settings', 'middleware' => ['auth', Admin::class]], function ($router) {
        //create or update a setting
        Route::put('/', [SettingsController::class, 'write'])->name('settings.write');
        //the address the application sees for the admin
        Route::get('/client-ip', [SettingsController::class, 'clientIp'])->name('settings.clientIp');
        //whether the key for encrypting stored files is there
        Route::get('/storage-encryption', [SettingsController::class, 'storageEncryptionStatus'])->name('settings.storageEncryption');
        Route::post('/logo', [SettingsController::class, 'writeLogo'])->name('settings.writeLogo');
        Route::delete('/logo', [SettingsController::class, 'deleteLogo'])->name('settings.deleteLogo');
        //favicon management
        Route::post('/favicon', [SettingsController::class, 'writeFavicon'])->name('settings.writeFavicon');
        Route::delete('/favicon', [SettingsController::class, 'deleteFavicon'])->name('settings.deleteFavicon');
        Route::get('/favicon/status', [SettingsController::class, 'hasFavicon'])->name('settings.hasFavicon');
        //the image behind the sign-in screen
        Route::post('/login-background', [SettingsController::class, 'writeLoginBackground'])->name('settings.writeLoginBackground');
        Route::delete('/login-background', [SettingsController::class, 'deleteLoginBackground'])->name('settings.deleteLoginBackground');
        //list background images
        Route::get('/backgrounds', [BackgroundsController::class, 'list'])->name('settings.backgrounds.list');
        //upload a background image
        Route::post('/backgrounds', [BackgroundsController::class, 'upload'])->name('backgrounds.upload');
        //delete a background image
        Route::delete('/backgrounds/{file}', [BackgroundsController::class, 'delete'])->name('backgrounds.delete');
    });

    //system stats [auth, admin]
    Route::group(['prefix' => 'stats', 'middleware' => ['auth', Admin::class]], function ($router) {
        Route::get('/', [StatsController::class, 'getStats'])->name('stats.get');
        Route::get('/system-info', [StatsController::class, 'getSystemInfo'])->name('stats.systemInfo');
    });

    //read settings [auth]
    Route::group(['prefix' => 'settings', 'middleware' => ['auth']], function ($router) {
        //read a setting by its key
        Route::get('/{key}', [SettingsController::class, 'read'])->name('settings.read');
        //read settings by their group
        Route::get('/group/{group}', [SettingsController::class, 'readGroup'])->name('settings.readGroup');
    });

    //manage shares [auth]
    Route::group(['prefix' => 'shares', 'middleware' => ['auth']], function ($router) {
        //get my shares
        Route::get('/', [SharesController::class, 'myShares'])->name('shares.myShares');

        //expire a share
        Route::post('/{id}/expire', [SharesController::class, 'expire'])->name('shares.expire');

        //extend a share
        Route::post('/{id}/extend', [SharesController::class, 'extend'])->name('shares.extend');

        //set download limit
        Route::post('/{id}/set-download-limit', [SharesController::class, 'setDownloadLimit'])->name('shares.setDownloadLimit');

        //prune expired shares
        Route::post('/prune-expired', [SharesController::class, 'pruneExpiredShares'])->name('shares.pruneExpired');
    });

    //all shares [auth, admin]
    Route::group(['prefix' => 'shares', 'middleware' => ['auth', Admin::class]], function ($router) {
        //get all shares (admin only)
        Route::get('/all', [SharesController::class, 'allShares'])->name('shares.allShares');
    });

    //manage themes [auth, admin]
    Route::group(['prefix' => 'themes', 'middleware' => ['auth', Admin::class]], function ($router) {
        Route::post('/', [ThemesController::class, 'saveTheme'])->name('themes.save');
        Route::get('/', [ThemesController::class, 'getThemes'])->name('themes.list');
        Route::delete('/', [ThemesController::class, 'deleteTheme'])->name('themes.delete');
        Route::post('/set-active', [ThemesController::class, 'setActiveTheme'])->name('themes.setActive');
        Route::post('/install', [ThemesController::class, 'installCustomTheme'])->name('themes.install');
    });

    //manage auth providers [auth, admin]
    Route::group(['prefix' => 'auth-providers', 'middleware' => ['auth', Admin::class]], function ($router) {
        Route::get('/', [AuthProvidersController::class, 'index'])->name('auth-providers.index');
        Route::get('/available-types', [AuthProvidersController::class, 'listAvailableProviderTypes'])->name('auth-providers.listAvailableProviderTypes');
        Route::post('/test', [AuthProvidersController::class, 'testConnection'])->name('auth-providers.test');
        Route::post('/', [AuthProvidersController::class, 'create'])->name('auth-providers.create');
        Route::put('/bulk-update', [AuthProvidersController::class, 'bulkUpdate'])->name('auth-providers.bulkUpdate');
        Route::put('/{id}', [AuthProvidersController::class, 'update'])->name('auth-providers.update');
        Route::delete('/{id}', [AuthProvidersController::class, 'delete'])->name('auth-providers.delete');
        Route::get('/{uuid}/callback-url', [AuthProvidersController::class, 'getCallbackUrl'])->name('auth-providers.getCallbackUrl');
    });

    //manage email templates [auth, admin]
    Route::group(['prefix' => 'email-templates', 'middleware' => ['auth', Admin::class]], function ($router) {
        Route::get('/', [EmailTemplatesController::class, 'index'])->name('email-templates.index');
        Route::put('/', [EmailTemplatesController::class, 'update'])->name('email-templates.update');
        Route::delete('/{locale}/{name}', [EmailTemplatesController::class, 'reset'])->name('email-templates.reset');
    });

    //manage database backups [auth, admin]
    Route::group(['prefix' => 'backups', 'middleware' => ['auth', Admin::class]], function ($router) {
        Route::get('/', [BackupsController::class, 'index'])->name('backups.index');
        Route::post('/', [BackupsController::class, 'create'])->name('backups.create');
        Route::get('/{filename}/download', [BackupsController::class, 'download'])->name('backups.download');
        Route::delete('/{filename}', [BackupsController::class, 'delete'])->name('backups.delete');
    });

    //manage reverse shares [auth]
    //the guest of a file request never sends requests itself (MembersOnly)
    Route::group(['prefix' => 'reverse-shares', 'middleware' => ['auth', MembersOnly::class]], function ($router) {
        Route::post('/invite', [ReverseSharesController::class, 'createInvite'])->name('reverse-shares.createInvite');
        //the requests this account sent, and where each one is
        Route::get('/invites', [ReverseSharesController::class, 'listInvites'])->name('reverse-shares.listInvites');
        // Accept invite by ID (for existing users who must log in first)
        Route::post('/accept-by-id', [AuthController::class, 'acceptReverseShareInviteById'])->name('reverse-shares.acceptInviteById');
    });

    //accept a reverse share invite [public] (for guests with token)
    Route::group(['prefix' => 'reverse-shares'], function ($router) {
        Route::get('/accept', [ AuthController::class, 'acceptReverseShareInvite'])->name('reverse-shares.acceptInvite');
    });

    //read auth providers [public]
    Route::get('/available-auth-providers', [AuthProvidersController::class, 'list'])->name('auth-providers.list');

    //read active theme [public]
    Route::get('/themes/active', [ThemesController::class, 'getActiveTheme'])->name('themes.getActive');

    //read shares [public]
    Route::get('/shares/{share}', [SharesController::class, 'read'])->name('shares.read');

    //open a share behind a password [public, limited like the downloads]
    Route::post('/shares/{share}/unlock', [SharesController::class, 'unlock'])->name('shares.unlock');

    //download shares [public]
    Route::any('/shares/{share}/download', [SharesController::class, 'download'])->name('shares.download');
    
    //download specific file from share [public] - filepath can include nested directories; posted
    //by the browser when the share has a password, so that it never ends up in an address or a log
    Route::match(['get', 'post'], '/shares/{share}/download/file/{filepath}', [SharesController::class, 'downloadFile'])
        ->where('filepath', '.*')
        ->name('shares.downloadFile');

    //use background image [public]
    Route::get('/backgrounds', [BackgroundsController::class, 'list'])->name('backgrounds.list');
    Route::get('/backgrounds/{file}/thumb', [BackgroundsController::class, 'useThumb'])->name('backgrounds.useThumb');
    Route::get('/backgrounds/{file}', [BackgroundsController::class, 'use'])->name('backgrounds.use');

    //serve favicon [public]
    Route::get('/favicon', [SettingsController::class, 'getFavicon'])->name('settings.getFavicon');
});


// tusd webhook handler (called by tusd server, not authenticated via middleware)
Route::post('/tusd-hooks', [TusdHooksController::class, 'handleHook'])->name('tusd.hooks');

// Upload routes (now using tusd for actual uploads)
Route::group(['prefix' => 'uploads', 'middleware' => ['auth']], function ($router) {
    // Verify if an upload session is still valid (for tus resume functionality)
    Route::get('/verify/{uploadId}', [UploadsController::class, 'verifyUpload'])->name('uploads.verify');
    // Create a share from uploaded files (after tusd uploads complete)
    Route::post('/create-share-from-uploads', [UploadsController::class, 'createShareFromUploads'])->name('uploads.createShareFromUploads');
});
