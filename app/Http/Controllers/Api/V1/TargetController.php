<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TargetResource;
use App\Models\Target;
use App\Services\LatestMetrics;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TargetController extends Controller
{
    public function __construct(private readonly LatestMetrics $latestMetrics) {}

    /**
     * GET /api/v1/targets
     *
     * List all targets with their latest metrics. Uses a single LatestMetrics query
     * plus eager-loaded check — no N+1.
     */
    public function index(): AnonymousResourceCollection
    {
        $targets = Target::with('check')->get();

        $metricsByTarget = $this->latestMetrics->forTargets(
            $targets->pluck('id'),
            ['cpu_pct', 'mem_pct', 'disk_pct', 'latency_ms'],
        );

        return TargetResource::collection(
            $targets->map(fn (Target $target) => (new TargetResource($target))
                ->withMetrics($metricsByTarget[$target->id] ?? []))
        );
    }

    /**
     * GET /api/v1/targets/{id}
     *
     * Single target. 404 on unknown id.
     */
    public function show(string $id): TargetResource
    {
        $target = Target::with('check')->findOrFail($id);

        $metricsByTarget = $this->latestMetrics->forTargets(
            collect([$target->id]),
            ['cpu_pct', 'mem_pct', 'disk_pct', 'latency_ms'],
        );

        return (new TargetResource($target))
            ->withMetrics($metricsByTarget[$target->id] ?? []);
    }
}
