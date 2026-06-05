<?php

namespace App\Console\Commands;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use App\Events\AlertFired;
use App\Events\AlertResolved;
use App\Models\Alert;
use App\Models\MonitoredApp;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class WatchApps extends Command
{
    protected $signature = 'apps:watch';

    protected $description = 'Raise/resolve app telemetry alerts: silence (critical) and delivery-degraded (warning).';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $staleAfter = (int) config('watchtower.apps.stale_after', 15);
        $staleCutoff = $now->subMinutes($staleAfter);
        $degradedCutoff = $now->subMinutes((int) config('watchtower.apps.delivery.degraded_after', 5));

        foreach (MonitoredApp::with('health')->get() as $app) {
            $health = $app->health;
            $receivedAt = $health?->received_at;

            $silent = $receivedAt === null || $receivedAt->lt($staleCutoff);
            $this->reconcile($app->id, 'app.silence', AlertTier::Critical, $silent,
                "{$app->name} stopped reporting",
                "No health snapshot received for over {$staleAfter} minutes.");

            $degraded = $health?->degraded_since !== null && $health->degraded_since->lt($degradedCutoff);
            $reason = $health?->last_ship_error ?? 'telemetry delivery backlog';
            $this->reconcile($app->id, 'app.delivery_degraded', AlertTier::Warning, $degraded,
                "{$app->name} telemetry delivery degraded", $reason);
        }

        return self::SUCCESS;
    }

    /**
     * Idempotently fire (on first true) or resolve (on first false) the single
     * app-scoped alert identified by rule_key. Mirrors the D2 fire/resolve discipline.
     */
    private function reconcile(int $appId, string $ruleKey, AlertTier $tier, bool $active, string $title, string $message): void
    {
        $now = CarbonImmutable::now();

        $alert = Alert::where('app_id', $appId)
            ->where('rule_key', $ruleKey)
            ->whereIn('state', [AlertState::Pending->value, AlertState::Firing->value])
            ->first();

        if ($active && $alert === null) {
            $alert = new Alert;
            $alert->target_id = null;
            $alert->app_id = $appId;
            $alert->rule_key = $ruleKey;
            $alert->state = AlertState::Firing;
            $alert->tier = $tier;
            $alert->title = $title;
            $alert->message = $message;
            $alert->fired_at = $now;
            $alert->pending_since = null;
            $alert->save();

            AlertFired::dispatch($alert);

            return;
        }

        if (! $active && $alert !== null) {
            $alert->state = AlertState::Resolved;
            $alert->resolved_at = $now;
            $alert->save();

            AlertResolved::dispatch($alert);
        }
    }
}
