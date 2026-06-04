<?php

namespace App\Listeners;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Events\AlertFired;
use App\Notifications\NtfySender;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAlertFiredNotification
{
    public function __construct(
        private readonly NtfySender $sender,
    ) {}

    public function handle(AlertFired $event): void
    {
        // Re-read current alert state from DB — SerializesModels means the model
        // was serialised at dispatch time; by the time this listener runs (sync),
        // the alert may have already been resolved (e.g. fast flap + duplicate tick).
        $alert = $event->alert->fresh();

        if ($alert === null) {
            // Alert row was deleted — nothing to notify about.
            Log::info('SendAlertFiredNotification: alert no longer exists, skipping.', [
                'alert_id' => $event->alert->id,
            ]);

            return;
        }

        // If the alert has already resolved by the time this listener runs, skip.
        // Paging for something that has already cleared is noisy and confusing.
        if ($alert->state !== AlertState::Firing) {
            Log::info('SendAlertFiredNotification: alert is no longer Firing, skipping notification.', [
                'alert_id' => $alert->id,
                'state' => $alert->state->value,
            ]);

            return;
        }

        // Tier policy: only Critical alerts push notifications.
        // Warning alerts are handled by the media stack and never page operators.
        // Note: we use $alert->tier (not the Rule) because the rule may have been
        // deleted by the time this listener runs — the alert row carries the tier.
        if ($alert->tier !== AlertTier::Critical) {
            return;
        }

        if (! $this->sender->enabled()) {
            Log::info('SendAlertFiredNotification: ntfy not configured, skipping.', [
                'alert_id' => $alert->id,
            ]);

            return;
        }

        // Acknowledged alerts: ack_at != null does not suppress this notification —
        // acknowledgement is orthogonal to fire/resolve events and this listener only
        // runs once per alert (fire-once semantics), so there is no re-notification risk.

        try {
            $this->sender->send(
                title: "🔴 {$alert->title}",
                message: $alert->message,
                priority: 'urgent',
                tags: ['rotating_light'],
            );
        } catch (Throwable $e) {
            // Notification failure must never break the evaluate pipeline.
            Log::error('SendAlertFiredNotification: failed to send ntfy notification.', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
