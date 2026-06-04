<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AlertResource;
use App\Http\Resources\TargetResource;
use App\Models\Alert;
use App\Models\Target;
use App\Services\LatestMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class SummaryController extends Controller
{
    public function __construct(private readonly LatestMetrics $latestMetrics) {}

    /**
     * GET /api/v1/summary
     *
     * Dashboard summary — see contract for full shape.
     */
    public function __invoke(): JsonResponse
    {
        // ----------------------------------------------------------------
        // Load targets with checks (single query + eager load)
        // ----------------------------------------------------------------
        $targets = Target::with('check')->where('enabled', true)->get();

        $targetsTotal = $targets->count();
        $targetsUp = $targets->filter(fn (Target $t) => $t->check?->status === TargetStatus::Up)->count();
        $targetsPaused = $targets->filter(fn (Target $t) => $t->check?->status === TargetStatus::Paused)->count();
        // Targets with no Check row count as neither up nor paused (unknown).

        // ----------------------------------------------------------------
        // Latest metrics for all targets
        // ----------------------------------------------------------------
        $metricsByTarget = $this->latestMetrics->forTargets(
            $targets->pluck('id'),
            ['cpu_pct', 'mem_pct', 'disk_pct', 'latency_ms'],
        );

        // ----------------------------------------------------------------
        // Open alerts — same ordering as /alerts
        // ----------------------------------------------------------------
        $openAlerts = Alert::whereIn('state', [AlertState::Pending->value, AlertState::Firing->value])
            ->orderByRaw('CASE WHEN tier = ? THEN 0 ELSE 1 END', [AlertTier::Critical->value])
            ->orderByRaw('fired_at DESC NULLS LAST')
            ->get();

        // ----------------------------------------------------------------
        // Nodes — type=node targets with metrics
        // ----------------------------------------------------------------
        $nodeTargets = $targets->filter(fn (Target $t) => $t->type === TargetType::Node)->values();

        // ----------------------------------------------------------------
        // Backup age — derived from the latest backup_age_hours metric on
        // any storage target. null when no metric exists.
        // ----------------------------------------------------------------
        $storageTargetIds = $targets
            ->filter(fn (Target $t) => $t->type === TargetType::Storage)
            ->pluck('id');

        $backupAgeMetrics = $this->latestMetrics->forTargets(
            $storageTargetIds,
            ['backup_age_hours'],
            stalenessMinutes: 60 * 48, // 48h window for backup age
        );

        $latestBackupAgeHours = null;
        foreach ($backupAgeMetrics as $metrics) {
            $age = $metrics['backup_age_hours'] ?? null;
            if ($age !== null && ($latestBackupAgeHours === null || $age < $latestBackupAgeHours)) {
                $latestBackupAgeHours = $age;
            }
        }

        $lastBackupAt = $latestBackupAgeHours !== null
            ? CarbonImmutable::now()->subHours($latestBackupAgeHours)
            : null;

        $lastBackupOk = $latestBackupAgeHours !== null && $latestBackupAgeHours <= 26;

        // ----------------------------------------------------------------
        // Tank usage — pbs_disk_pct on the "tank" storage target, falling
        // back to disk_pct when pbs_disk_pct is absent.
        // ----------------------------------------------------------------
        // tank card reflects the PVE storage view, not a PBS datastore.
        $tankTarget = $targets
            ->filter(fn (Target $t) => $t->type === TargetType::Storage && $t->name === 'tank' && $t->node !== 'pbs')
            ->first();

        $tankUsagePercent = 0.0;
        if ($tankTarget !== null) {
            $tankMetrics = $this->latestMetrics->forTargets(
                collect([$tankTarget->id]),
                ['pbs_disk_pct', 'disk_pct'],
            );
            $m = $tankMetrics[$tankTarget->id] ?? [];
            $tankUsagePercent = $m['pbs_disk_pct'] ?? $m['disk_pct'] ?? 0.0;
        }

        $nodesData = $nodeTargets->map(fn (Target $t) => (new TargetResource($t))
            ->withMetrics($metricsByTarget[$t->id] ?? [])
            ->toArray(request()));

        return response()->json([
            'targetsUp' => $targetsUp,
            'targetsTotal' => $targetsTotal,
            'targetsPaused' => $targetsPaused,
            'openAlerts' => AlertResource::collection($openAlerts),
            // Summary sub-collections are FLAT arrays; only top-level list/detail endpoints use Laravel's {data:...} envelope.
            'nodes' => $nodesData->values(),
            'apps' => [],
            'lastBackupAt' => $lastBackupAt?->toIso8601String(),
            'lastBackupOk' => $lastBackupOk,
            'tankUsagePercent' => $tankUsagePercent,
        ]);
    }
}
