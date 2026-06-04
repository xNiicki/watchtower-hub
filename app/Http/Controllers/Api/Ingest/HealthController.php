<?php

namespace App\Http\Controllers\Api\Ingest;

use App\Http\Controllers\Controller;
use App\Models\AppHealth;
use App\Models\MonitoredApp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class HealthController extends Controller
{
    /**
     * POST /api/ingest/health
     *
     * The authenticated user IS the MonitoredApp (Sanctum tokenable). Stores the
     * latest snapshot (1:1, atomic upsert) and stamps received_at = now.
     */
    public function __invoke(Request $request): Response
    {
        $app = $request->user();

        // The `ingest` ability guarantees the ability, not the tokenable type. A
        // token issued for any other model (e.g. a stray User token that somehow
        // carries `ingest`) must be rejected, not crash on ->slug.
        if (! $app instanceof MonitoredApp) {
            abort(403, 'This token is not associated with a monitored app.');
        }

        $data = $request->validate([
            'slug' => ['required', 'string'],
            'snapshotAt' => ['required', 'date'],
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'healthy' => ['required', 'boolean'],
            'errorsLastHour' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'queueDepth' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'failedJobs24h' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'mailSent24h' => ['required', 'integer', 'min:0', 'max:2000000000'],
            'lastDeployAt' => ['nullable', 'date'],
        ]);

        // Cross-check the claimed slug against the token's app.
        if ($data['slug'] !== $app->slug) {
            abort(403, 'Payload slug does not match the authenticated app.');
        }

        // Atomic upsert (single ON CONFLICT statement) — safe under concurrent
        // ingest for the same app, where updateOrCreate could race two inserts
        // into a unique-constraint violation.
        AppHealth::upsert(
            [[
                'app_id' => $app->id,
                'healthy' => $data['healthy'],
                'errors_last_hour' => $data['errorsLastHour'],
                'queue_depth' => $data['queueDepth'],
                'failed_jobs_24h' => $data['failedJobs24h'],
                'mail_sent_24h' => $data['mailSent24h'],
                'last_deploy_at' => $data['lastDeployAt'] ? CarbonImmutable::parse($data['lastDeployAt']) : null,
                'snapshot_at' => CarbonImmutable::parse($data['snapshotAt']),
                'received_at' => CarbonImmutable::now(),
            ]],
            uniqueBy: ['app_id'],
            update: ['healthy', 'errors_last_hour', 'queue_depth', 'failed_jobs_24h', 'mail_sent_24h', 'last_deploy_at', 'snapshot_at', 'received_at'],
        );

        return response()->noContent();
    }
}
