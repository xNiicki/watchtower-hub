<?php

namespace App\Console\Commands;

use App\Models\AppMetric;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app-metrics:prune')]
#[Description('Delete app metric points older than the retention window.')]
class PruneAppMetrics extends Command
{
    public function handle(): int
    {
        $days = (int) config('watchtower.apps.metrics.retention_days', 30);
        $cutoff = CarbonImmutable::now()->subDays($days);
        $deleted = AppMetric::where('bucket_at', '<', $cutoff)->delete();
        $this->info("app-metrics:prune deleted {$deleted} metric point(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
