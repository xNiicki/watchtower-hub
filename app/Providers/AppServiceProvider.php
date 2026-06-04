<?php

namespace App\Providers;

use App\Collectors\HttpServiceCollector;
use App\Collectors\PbsCollector;
use App\Collectors\ProxmoxCollector;
use App\Services\Settings;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Singleton so the per-request decrypt cache is shared across collectors in a run.
        $this->app->singleton(Settings::class);

        $this->app->tag([ProxmoxCollector::class, HttpServiceCollector::class, PbsCollector::class], 'collectors');
    }

    /**
     * Bootstrap any application services.
     *
     * Listeners in app/Listeners/ are auto-discovered by Laravel's event system
     * (Illuminate\Foundation\Support\Providers\EventServiceProvider) via type-hint
     * inspection of their handle() methods. No manual Event::listen() registration required.
     */
    public function boot(): void
    {
        // Behind a TLS-terminating reverse proxy (the documented deployment),
        // the container receives plain HTTP, so Laravel would generate http:// URLs
        // and the browser blocks them as mixed content on an https page. When the
        // operator declares an https APP_URL, force https URL generation. Left off
        // for plain-http LAN deploys (APP_URL stays http) so nothing breaks there.
        if (Str::startsWith((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }
}
