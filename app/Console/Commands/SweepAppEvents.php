<?php

namespace App\Console\Commands;

use App\Enums\AlertState;
use App\Events\AlertResolved;
use App\Models\Alert;
use App\Models\AppEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SweepAppEvents extends Command
{
    protected $signature = 'app-events:sweep';

    protected $description = 'Auto-resolve firing app-event alerts whose group has been quiet past the threshold.';

    public function handle(): int
    {
        $quietAfter = (int) config('watchtower.apps.events.quiet_after', 60);
        $cutoff = CarbonImmutable::now()->subMinutes($quietAfter);

        $firing = Alert::where('state', AlertState::Firing->value)
            ->whereNotNull('app_id')
            ->where('rule_key', 'like', 'app.%')
            ->cursor();

        $resolved = 0;

        foreach ($firing as $alert) {
            $fingerprint = str_contains($alert->rule_key, ':')
                ? substr($alert->rule_key, strpos($alert->rule_key, ':') + 1)
                : null;

            if ($fingerprint === null) {
                continue;
            }

            $group = AppEvent::where('app_id', $alert->app_id)
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($group === null || $group->last_seen_at->lt($cutoff)) {
                $alert->state = AlertState::Resolved;
                $alert->resolved_at = CarbonImmutable::now();
                $alert->save();

                AlertResolved::dispatch($alert);
                $resolved++;
            }
        }

        $this->info("app-events:sweep resolved {$resolved} alert(s).");

        return self::SUCCESS;
    }
}
