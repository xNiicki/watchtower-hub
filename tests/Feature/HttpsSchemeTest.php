<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class HttpsSchemeTest extends TestCase
{
    public function test_https_app_url_forces_https_url_generation(): void
    {
        config(['app.url' => 'https://hub.example.com']);

        // Re-run the provider boot with the https APP_URL in place.
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/admin'));
        $this->assertStringStartsWith('https://', URL::asset('css/app.css'));
    }

    public function test_http_app_url_leaves_scheme_untouched(): void
    {
        config(['app.url' => 'http://localhost:8000']);

        (new AppServiceProvider($this->app))->boot();

        // No forced scheme: a plain request stays http.
        $this->assertStringStartsWith('http://', URL::to('/admin'));
    }
}
