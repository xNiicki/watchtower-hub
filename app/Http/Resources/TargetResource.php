<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of a Target for the mobile API.
 *
 * Shape matches the iOS app's Target DTO constructor exactly (camelCase keys):
 *   id (string), name, type, status, node, cpuPercent, memPercent, diskPercent, latencyMs
 *
 * The resource expects the following extra keys injected via `additional()` or
 * set directly on the resource before toArray() is called:
 *   - latestMetrics: array<string, float|null>  keyed by metric key for this target
 *
 * Status defaults to "unknown" when no Check row exists.
 */
class TargetResource extends JsonResource
{
    /**
     * @var array<string, float|null>
     */
    public array $latestMetrics = [];

    /**
     * @param  array<string, float|null>  $metrics
     */
    public function withMetrics(array $metrics): static
    {
        $this->latestMetrics = $metrics;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $status = $this->check?->status?->value ?? 'unknown';
        $latencyMs = isset($this->latestMetrics['latency_ms'])
            ? (int) $this->latestMetrics['latency_ms']
            : null;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'type' => $this->type->value,
            'status' => $status,
            'node' => $this->node,
            'cpuPercent' => $this->latestMetrics['cpu_pct'] ?? null,
            'memPercent' => $this->latestMetrics['mem_pct'] ?? null,
            'diskPercent' => $this->latestMetrics['disk_pct'] ?? null,
            'latencyMs' => $latencyMs,
        ];
    }
}
