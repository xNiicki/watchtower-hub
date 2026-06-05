<?php

namespace App\Http\Resources;

use App\Models\MonitoredApp;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a MonitoredApp (with its latest health) for the mobile
 * API's summary.apps[]. Shape matches the iOS AppHealth DTO (camelCase):
 *   name, healthy, errorsLastHour, queueDepth, failedJobs24h, mailSent24h,
 *   lastDeployAt, lastSeenAt, stale, bufferDepth, lastShipError, deliveryDegraded
 *
 * Staleness is computed by the hub: an app is stale when it has no health
 * snapshot, or its received_at is older than the configured threshold. A stale
 * app is reported unhealthy regardless of its last self-reported state.
 *
 * @mixin MonitoredApp
 */
class MonitoredAppResource extends JsonResource
{
    public int $staleAfterMinutes = 15;

    public function withStaleAfter(int $minutes): static
    {
        $this->staleAfterMinutes = $minutes;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $health = $this->health;
        $receivedAt = $health?->received_at;
        $stale = $receivedAt === null || $receivedAt->lt(CarbonImmutable::now()->subMinutes($this->staleAfterMinutes));

        return [
            'name' => $this->name,
            'slug' => $this->slug,
            'healthy' => $health !== null && $health->healthy && ! $stale,
            'errorsLastHour' => (int) ($health->errors_last_hour ?? 0),
            'queueDepth' => (int) ($health->queue_depth ?? 0),
            'failedJobs24h' => (int) ($health->failed_jobs_24h ?? 0),
            'mailSent24h' => (int) ($health->mail_sent_24h ?? 0),
            'lastDeployAt' => $health?->last_deploy_at?->toIso8601String(),
            'lastSeenAt' => $receivedAt?->toIso8601String(),
            'stale' => $stale,
            'bufferDepth' => (int) ($health->buffer_depth ?? 0),
            'lastShipError' => $health?->last_ship_error,
            'deliveryDegraded' => (int) ($health->buffer_depth ?? 0) > 0,
        ];
    }
}
