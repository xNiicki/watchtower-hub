<?php

namespace Database\Seeders;

use App\Models\Rule;
use Illuminate\Database\Seeder;

/**
 * Seeds the canonical alert rules for Watchtower.
 *
 * Tier policy notes:
 *   - infra-down: critical — covers all infra targets except the example media targets
 *   - media-down: warning — example non-critical targets that should not page critically.
 *       Edit the include/exclude target lists to match your own fleet.
 *   - disk-high: critical — storage breach requires immediate attention
 *   - backup-stale: critical — duration 0 fires immediately on first evaluate
 *
 * // Sub-30s flaps are below the evaluate cadence and never observed — the schedule is an implicit debounce floor.
 */
class RuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            [
                'key' => 'infra-down',
                'condition_type' => 'target_down',
                'params' => ['exclude_targets' => ['media-server', 'downloader']],
                'duration_seconds' => 180,
                'tier' => 'critical',
                'enabled' => true,
            ],
            [
                'key' => 'media-down',
                'condition_type' => 'target_down',
                'params' => ['include_targets' => ['media-server', 'downloader']],
                'duration_seconds' => 300,
                'tier' => 'warning',
                'enabled' => true,
            ],
            [
                'key' => 'disk-high',
                'condition_type' => 'metric_threshold',
                'params' => ['metric' => 'disk_pct', 'operator' => '>=', 'value' => 90],
                'duration_seconds' => 300,
                'tier' => 'critical',
                'enabled' => true,
            ],
            [
                'key' => 'backup-stale',
                'condition_type' => 'metric_threshold',
                'params' => ['metric' => 'backup_age_hours', 'operator' => '>', 'value' => 26],
                'duration_seconds' => 0,
                'tier' => 'critical',
                'enabled' => true,
            ],
        ];

        foreach ($rules as $rule) {
            Rule::updateOrCreate(
                ['key' => $rule['key']],
                $rule,
            );
        }
    }
}
