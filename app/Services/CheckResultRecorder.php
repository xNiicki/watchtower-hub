<?php

namespace App\Services;

use App\Collectors\CheckResult;
use App\Enums\TargetStatus;
use App\Models\Check;
use App\Models\Metric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persists collector results into the checks and metrics tables.
 *
 * Single-writer assumed (scheduler withoutOverlapping); fail_streak uses a DB-side increment so a second writer cannot lose updates.
 */
class CheckResultRecorder
{
    /**
     * Persist a collector result: upsert the Check row and batch-insert Metrics.
     */
    public function record(CheckResult $result, CarbonImmutable $capturedAt): void
    {
        $utcCapturedAt = $capturedAt->utc();

        $check = Check::where('target_id', $result->target->id)->first() ?? new Check;

        $check->target_id = $result->target->id;

        $check->status = $result->status;
        $check->latency_ms = $result->latencyMs;
        $check->last_checked_at = $utcCapturedAt;

        if ($result->status === TargetStatus::Up) {
            $check->fail_streak = 0;
            $check->last_ok_at = $utcCapturedAt;
            $check->save();
        } elseif ($result->status === TargetStatus::Down) {
            if ($check->exists) {
                $check->save();
                Check::where('id', $check->id)->update([
                    'fail_streak' => DB::raw('fail_streak + 1'),
                ]);
            } else {
                $check->fail_streak = 1;
                $check->save();
            }
        } else {
            // Unknown / Paused: streak and last_ok_at unchanged
            $check->save();
        }

        if ($result->metrics !== []) {
            $rows = [];
            foreach ($result->metrics as $key => $value) {
                $rows[] = [
                    'target_id' => $result->target->id,
                    'key' => $key,
                    'value' => $value,
                    'captured_at' => $utcCapturedAt->toDateTimeString(),
                ];
            }

            Metric::insert($rows);
        }
    }
}
