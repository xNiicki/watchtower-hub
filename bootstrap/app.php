<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The hub is deployed behind a TLS-terminating reverse proxy on a private
        // network, so trust the forwarded headers (incl. X-Forwarded-Proto) to
        // detect the original https scheme. Safe here: the container is only
        // reachable via the proxy.
        $middleware->trustProxies(at: '*');

        // Register Sanctum ability middleware aliases for use in routes.
        // CheckAbilities (abilities): ALL listed abilities must be present on the token.
        // CheckForAnyAbility (ability): ANY of the listed abilities must be present.
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
