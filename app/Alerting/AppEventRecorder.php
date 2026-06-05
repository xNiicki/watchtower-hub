<?php

namespace App\Alerting;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Events\AlertFired;
use App\Models\Alert;
use App\Models\AppEvent;
use App\Models\MonitoredApp;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AppEventRecorder
{
    /**
     * Upsert the (app_id, fingerprint) incident group and raise/refresh its alert.
     *
     * @param  array<string, mixed>  $data  validated event payload
     */
    public function record(MonitoredApp $app, array $data): void
    {
        $severity = $this->severityFor((string) $data['type']);

        try {
            $event = $this->upsert($app, $data, $severity);
        } catch (UniqueConstraintViolationException) {
            // Lost an insert race for a brand-new fingerprint — the row now exists; retry as an update.
            $event = $this->upsert($app, $data, $severity);
        }

        if ($severity === 'critical') {
            $this->raiseAlert($app, $data, $event, $severity);
        }
    }

    private function severityFor(string $type): string
    {
        return (string) config("watchtower.apps.events.severity.{$type}", 'warning');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function upsert(MonitoredApp $app, array $data, string $severity): AppEvent
    {
        return DB::transaction(function () use ($app, $data, $severity): AppEvent {
            $event = AppEvent::query()
                ->where('app_id', $app->id)
                ->where('fingerprint', (string) $data['fingerprint'])
                ->lockForUpdate()
                ->first();

            $now = CarbonImmutable::now();
            $occurredAt = isset($data['occurredAt']) ? CarbonImmutable::parse($data['occurredAt']) : $now;
            $incoming = max(1, (int) ($data['occurrences'] ?? 1));

            if ($event === null) {
                $event = new AppEvent;
                $event->app_id = $app->id;
                $event->fingerprint = (string) $data['fingerprint'];
                $event->occurrences = 0;
                $event->first_seen_at = $occurredAt;
            }

            $event->type = (string) $data['type'];
            $event->severity = $severity;
            $event->title = (string) $data['title'];
            $event->message = (string) $data['message'];
            $event->exception_class = $data['exceptionClass'] ?? null;
            $event->file = $data['file'] ?? null;
            $event->line = isset($data['line']) ? (int) $data['line'] : null;
            $event->trace = $data['trace'] ?? null;
            $event->context = $data['context'] ?? null;
            $event->occurrences += $incoming;
            // last_seen_at tracks the latest occurrence and must only move
            // forward — guard against out-of-order arrivals dragging it back.
            $event->last_seen_at = ($event->last_seen_at !== null && $event->last_seen_at->greaterThan($occurredAt))
                ? $event->last_seen_at
                : $occurredAt;
            // received_at is the hub receipt timestamp — always now().
            $event->received_at = $now;
            $event->save();

            return $event;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function raiseAlert(MonitoredApp $app, array $data, AppEvent $event, string $severity): void
    {
        $ruleKey = "app.{$data['type']}:{$data['fingerprint']}";
        $now = CarbonImmutable::now();

        $alert = Alert::where('app_id', $app->id)
            ->where('rule_key', $ruleKey)
            ->whereIn('state', [AlertState::Pending->value, AlertState::Firing->value])
            ->first();

        $message = "{$event->title}: {$event->message} ({$event->occurrences}× — {$event->file}:{$event->line})";

        if ($alert === null) {
            // Fix [3]: derive tier from severity
            $tier = $severity === 'critical' ? AlertTier::Critical : AlertTier::Warning;

            $alert = new Alert;
            $alert->target_id = null;
            $alert->app_id = $app->id;
            $alert->rule_key = $ruleKey;
            $alert->state = AlertState::Firing; // event already happened — skip pending/debounce
            $alert->tier = $tier;
            $alert->title = "{$app->name}: {$event->title}";
            $alert->message = $message;
            $alert->fired_at = $now;
            $alert->pending_since = null; // Fix [7]: explicit null for parity with AlertEngine
            $alert->save();

            // Fix [2]: new alert always pages unconditionally; prime the cooldown key afterward
            $this->pageNow($alert, $ruleKey);

            return;
        }

        // Fix [4]: refresh title on recurrence
        $alert->title = "{$app->name}: {$event->title}";
        $alert->message = $message;
        $alert->save();

        // Existing alert: respect the cooldown gate
        $this->page($alert, $ruleKey);
    }

    /**
     * Page unconditionally and prime the cooldown key (used for brand-new alerts).
     */
    private function pageNow(Alert $alert, string $ruleKey): void
    {
        $cooldown = (int) config('watchtower.apps.events.renotify_after', 60);

        Cache::put("app-event-notified:{$ruleKey}", true, $cooldown * 60);
        AlertFired::dispatch($alert);
    }

    /**
     * Dispatch AlertFired at most once per renotify_after window per rule_key.
     */
    private function page(Alert $alert, string $ruleKey): void
    {
        $cooldown = (int) config('watchtower.apps.events.renotify_after', 60);

        if (Cache::add("app-event-notified:{$ruleKey}", true, $cooldown * 60)) {
            AlertFired::dispatch($alert);
        }
    }
}
