<?php

namespace App\Console\Commands;

use App\Collectors\Collector;
use App\Collectors\CollectorException;
use App\Enums\TargetStatus;
use App\Models\Check;
use App\Services\CheckResultRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('collect:run')]
#[Description('Run all enabled collectors and record results')]
class CollectRun extends Command
{
    public function handle(CheckResultRecorder $recorder): int
    {
        /** @var iterable<Collector> $collectors */
        $collectors = app()->tagged('collectors');

        foreach ($collectors as $collector) {
            if (! $collector->enabled()) {
                continue;
            }

            $capturedAt = CarbonImmutable::now();
            $start = microtime(true);

            try {
                $results = $collector->collect();

                foreach ($results as $result) {
                    $recorder->record($result, $capturedAt);
                }

                $elapsed = (int) round((microtime(true) - $start) * 1000);

                $this->line("{$collector->key()}: ".count($results)." results in {$elapsed}ms");

                // Mark vanished targets Unknown: in-scope targets with a check row
                // that were NOT present in this run's results.
                $collectedTargetIds = array_map(fn ($r) => $r->target->id, $results);
                $scopeValues = array_map(fn ($t) => $t->value, $collector->scope());

                $vanishedCount = Check::whereHas(
                    'target',
                    fn ($q) => $q
                        ->whereIn('type', $scopeValues)
                        // PBS-owned targets (node='pbs') are managed exclusively by the PBS
                        // collector (scope=[]) and must never be reconciled here. Although
                        // TargetType::Storage is in Proxmox's scope, PBS datastores are not
                        // part of the PVE cluster/resources response, so excluding them
                        // prevents a spurious Unknown flicker and avoids leaving them stuck
                        // Unknown if the PBS collector later fails.
                        ->where('node', '!=', 'pbs')
                )->when(
                    ! empty($collectedTargetIds),
                    fn ($q) => $q->whereNotIn('target_id', $collectedTargetIds)
                )->update([
                    'status' => TargetStatus::Unknown->value,
                    'last_checked_at' => $capturedAt->utc()->toDateTimeString(),
                ]);

                if ($vanishedCount > 0) {
                    $this->line("{$collector->key()}: {$vanishedCount} vanished targets marked unknown");
                }
            } catch (CollectorException $e) {
                $scopeValues = array_map(fn ($t) => $t->value, $collector->scope());

                $markedCount = Check::whereHas(
                    'target',
                    fn ($q) => $q->whereIn('type', $scopeValues)
                )->update(['status' => TargetStatus::Unknown->value]);

                Log::warning("Collector [{$collector->key()}] failed: {$e->getMessage()}", [
                    'collector' => $collector->key(),
                    'exception' => $e->getMessage(),
                ]);

                $this->line("{$collector->key()}: FAILED — marked {$markedCount} targets unknown");
            }
        }

        return self::SUCCESS;
    }
}
