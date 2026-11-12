<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\SettingsService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Schema;
use App\Twig\EmailSandbox;
use Twig\Extension\SandboxExtension;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Loaded by TwigBridge (config/twigbridge.php): every email template is rendered sandboxed
        $this->app->bind(SandboxExtension::class, fn () => EmailSandbox::extension());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Use a view composer for email templates so settings are loaded fresh each time.
        // This is important for queue workers which are long-running processes -
        // View::share() would capture stale values from when the worker started.
        // The error pages (resources/views/errors): the language and the brand of the instance,
        // set before the page reads its texts. An error page must show even when the database
        // does not answer: it then falls back on English and the OpenTransfer mark.
        View::composer(['errors::*', 'errors.*'], function ($view) {
            try {
                $locale = \App\Support\Locales::default();
                app()->setLocale($locale);
                $view->with([
                    'errorLocale' => $locale,
                    'errorSettings' => app(SettingsService::class)->getMany(['application_name', 'logo_width']),
                    'errorDefaultLogo' => usesDefaultLogo(),
                ]);
            } catch (\Throwable $e) {
                $view->with(['errorLocale' => app()->getLocale()]);
            }
        });

        View::composer('emails.*', function ($view) {
            try {
                $settingsService = app(SettingsService::class);
                $settings = $settingsService->getGlobalViewData();
                $view->with('settings', $settings);
            } catch (\Exception $e) {
                // If settings can't be loaded, provide empty defaults
                $view->with('settings', []);
            }
        });

        View::prependLocation(storage_path('templates'));
        View::addNamespace('email-overrides', storage_path('templates/emails'));
    }
}
