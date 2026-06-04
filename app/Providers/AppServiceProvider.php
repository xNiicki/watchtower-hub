<?php

namespace App\Providers;

use App\Collectors\HttpServiceCollector;
use App\Collectors\PbsCollector;
use App\Collectors\ProxmoxCollector;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
        //
    }
}
