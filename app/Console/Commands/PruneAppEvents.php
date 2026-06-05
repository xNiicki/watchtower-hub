<?php

namespace App\Console\Commands;

use App\Models\AppEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PruneAppEvents extends Command
{
    protected $signature = 'app-events:prune';

    protected $description = 'Delete grouped app events whose last occurrence is older than the retention window.';

    public function handle(): int
    {
        $days = (int) config('watchtower.apps.events.retention_days', 120);
        $cutoff = CarbonImmutable::now()->subDays($days);

        $deleted = AppEvent::where('last_seen_at', '<', $cutoff)->delete();

        $this->info("app-events:prune deleted {$deleted} event group(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
