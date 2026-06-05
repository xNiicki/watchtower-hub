<?php

use App\Enums\TokenAbility;
use App\Http\Controllers\Api\Ingest\HealthController;
use App\Http\Controllers\Api\Ingest\MetricController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\LogController;
use App\Http\Controllers\Api\V1\SummaryController;
use App\Http\Controllers\Api\V1\TargetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public — no auth required.
    Route::get('/ping', function (Request $request) {
        return ['service' => 'watchtower-hub'];
    });

    // All authenticated routes require a valid Sanctum token.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // Read-gated endpoints — token must have the "read" ability.
        Route::middleware('abilities:'.TokenAbility::Read->value)->group(function () {
            Route::get('/targets', [TargetController::class, 'index']);
            Route::get('/targets/{id}', [TargetController::class, 'show']);
            Route::get('/alerts', [AlertController::class, 'index']);
            Route::get('/summary', SummaryController::class);
            Route::get('/logs', LogController::class);
        });

        // Ack endpoint — token must have the "alerts:ack" ability.
        Route::middleware('abilities:'.TokenAbility::AckAlerts->value)
            ->post('/alerts/{id}/ack', [AlertController::class, 'acknowledge']);
    });
});

// Satellite ingest — per-app tokens with the "ingest" ability only.
// Deliberately NOT under the `v1` prefix: this is a push channel, not the versioned mobile read API.
// Future schema changes are versioned via the payload `schemaVersion` field instead.
Route::prefix('ingest')->middleware(['auth:sanctum', 'abilities:'.TokenAbility::Ingest->value])->group(function () {
    Route::post('/health', HealthController::class);
    Route::post('/metrics', MetricController::class);
});
