<?php

namespace App\Listeners;

use App\Enums\AlertTier;
use App\Events\AlertResolved;
use App\Models\Alert;
use App\Notifications\NtfySender;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAlertResolvedNotification
{
    public function __construct(
        private readonly NtfySender $sender,
    ) {}

    public function handle(AlertResolved $event): void
    {
        $alert = $event->alert;

        // Tier policy: only send recovery for alerts that paged (Critical only).
        // Note: we use $alert->tier (not the Rule) because the rule may have been
        // deleted by the time this listener runs — the alert row carries the tier.
        if ($alert->tier !== AlertTier::Critical) {
            return;
        }

        if (! $this->sender->enabled()) {
            Log::info('SendAlertResolvedNotification: ntfy not configured, skipping.', [
                'alert_id' => $alert->id,
            ]);

            return;
        }

        // No state check required here: resolution is a terminal state.
        // AlertResolved is only dispatched when the alert has already moved to Resolved.

        try {
            $message = $this->buildRecoveryMessage($alert);

            $this->sender->send(
                title: "✅ Resolved: {$alert->title}",
                message: $message,
                priority: 'default',
                tags: ['white_check_mark'],
            );
        } catch (Throwable $e) {
            // Notification failure must never break the evaluate pipeline.
            Log::error('SendAlertResolvedNotification: failed to send ntfy notification.', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildRecoveryMessage(Alert $alert): string
    {
        if ($alert->fired_at !== null && $alert->resolved_at !== null) {
            $duration = $alert->fired_at->diffForHumans($alert->resolved_at, [
                'syntax' => CarbonInterface::DIFF_ABSOLUTE,
                'parts' => 2,
                'join' => true,
            ]);

            return "Recovered after {$duration}";
        }

        return 'Recovered.';
    }
}
