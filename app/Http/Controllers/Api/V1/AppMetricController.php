<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AppMetric;
use App\Models\MonitoredApp;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppMetricController extends Controller
{
    private const RANGES = ['1h' => 60, '6h' => 360, '24h' => 1440];

    public function index(Request $request, string $slug): JsonResponse
    {
        $app = MonitoredApp::where('slug', $slug)->firstOrFail();

        $range = $request->query('range', '1h');
        if (! isset(self::RANGES[$range])) {
            $range = '1h';
        }
        $since = CarbonImmutable::now()->subMinutes(self::RANGES[$range]);

        $metrics = AppMetric::where('app_id', $app->id)
            ->where('bucket_at', '>=', $since)
            ->orderBy('bucket_at')
            ->get();

        // Build the series and the latest-per-key map in a single pass. The
        // query is ordered by bucket_at ascending, so the last value seen for
        // each key is the latest.
        $series = [];
        $latest = [];
        foreach ($metrics as $m) {
            $series[$m->key][] = ['at' => $m->bucket_at->toIso8601String(), 'value' => $m->value];
            $latest[$m->key] = $m->value;
        }

        $latestOf = fn (string $key) => $latest[$key] ?? 0;

        return response()->json([
            'range' => $range,
            'series' => (object) $series,
            'latest' => [
                'requestsPerMin' => (int) $latestOf('requests'),
                'latencyAvgMs' => (int) round($latestOf('request_latency_avg_ms')),
                'latencyMaxMs' => (int) round($latestOf('request_latency_max_ms')),
                'slowRequests' => (int) $latestOf('slow_requests'),
                'slowQueries' => (int) $latestOf('slow_queries'),
            ],
        ]);
    }
}
