<?php

namespace App\Http\Controllers\Api\Ingest;

use App\Http\Controllers\Controller;
use App\Models\AppMetric;
use App\Models\MonitoredApp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class MetricController extends Controller
{
    private const KEYS = ['requests', 'request_latency_avg_ms', 'request_latency_max_ms', 'slow_requests', 'slow_queries'];

    public function __invoke(Request $request): Response
    {
        $app = $request->user();

        if (! $app instanceof MonitoredApp) {
            abort(403, 'This token is not associated with a monitored app.');
        }

        $data = $request->validate([
            'slug' => ['required', 'string'],
            'schemaVersion' => ['required', 'integer', 'in:1'],
            'points' => ['required', 'array', 'max:5000'],
            'points.*.key' => ['required', 'string', Rule::in(self::KEYS)],
            'points.*.value' => ['required', 'numeric'],
            'points.*.bucketAt' => ['required', 'date'],
        ]);

        if ($data['slug'] !== $app->slug) {
            abort(403, 'Payload slug does not match the authenticated app.');
        }

        // Normalize each bucket to the start of its minute and dedupe by
        // (key, bucket_at), keeping the last occurrence. This preserves
        // idempotency for non-minute-aligned timestamps and avoids Postgres
        // erroring on a duplicate ON CONFLICT target within a single upsert.
        $rows = [];
        foreach ($data['points'] as $p) {
            $bucketAt = CarbonImmutable::parse($p['bucketAt'])->startOfMinute()->toDateTimeString();
            $rows[$p['key'].'|'.$bucketAt] = [
                'app_id' => $app->id,
                'key' => $p['key'],
                'value' => (float) $p['value'],
                'bucket_at' => $bucketAt,
            ];
        }

        AppMetric::upsert(array_values($rows), uniqueBy: ['app_id', 'key', 'bucket_at'], update: ['value']);

        return response()->noContent();
    }
}
