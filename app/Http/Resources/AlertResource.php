<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * JSON representation of an Alert for the mobile API.
 *
 * Shape matches the iOS app's Alert DTO constructor exactly (camelCase keys):
 *   id, tier, title, message, targetId, firedAt, acknowledged, resolvedAt
 *
 * Note: firedAt is null when the alert is still in the pending state.
 *       resolvedAt is always null on active-alert endpoints (/alerts, /summary).
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'tier' => $this->tier->value,
            'title' => $this->title,
            'message' => $this->message,
            'targetId' => $this->target_id !== null ? (string) $this->target_id : null,
            // Active alerts always have an "active since" instant; the app DTO is deliberately non-nullable.
            'firedAt' => ($this->fired_at ?? $this->pending_since)?->toIso8601String(),
            'acknowledged' => $this->acknowledged_at !== null,
            'resolvedAt' => null,
        ];
    }
}
