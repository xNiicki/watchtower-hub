<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertController extends Controller
{
    /**
     * GET /api/v1/alerts
     *
     * Active (pending + firing) alerts ordered: critical first, then fired_at desc nulls last.
     */
    public function index(): AnonymousResourceCollection
    {
        $alerts = Alert::whereIn('state', [AlertState::Pending->value, AlertState::Firing->value])
            ->orderByRaw('CASE WHEN tier = ? THEN 0 ELSE 1 END', [AlertTier::Critical->value])
            ->orderByRaw('fired_at DESC NULLS LAST')
            ->get();

        return AlertResource::collection($alerts);
    }

    /**
     * POST /api/v1/alerts/{id}/ack
     *
     * Acknowledge an alert. Idempotent — already-acknowledged alerts return 200.
     * Requires the alerts:ack token ability (enforced via middleware on the route).
     */
    public function acknowledge(string $id): AlertResource
    {
        $alert = Alert::findOrFail($id);

        if ($alert->acknowledged_at === null) {
            $alert->acknowledged_at = now();
            $alert->save();
        }

        return new AlertResource($alert);
    }
}
