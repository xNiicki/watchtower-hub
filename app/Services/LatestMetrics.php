<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Fetches the latest metric value per target in a single Postgres DISTINCT ON query.
 *
 * Returns a nested array keyed by [target_id][metric_key] => float value.
 * Only metrics captured within the staleness window are returned; stale entries
 * are omitted so callers can treat absence as null/unknown.
 *
 * This query is Postgres-specific (DISTINCT ON) but the application is Postgres-only.
 */
class LatestMetrics
{
    /**
     * @param  Collection<int, int>  $targetIds
     * @param  array<int, string>  $keys  Metric keys to fetch (e.g. ['cpu_pct', 'mem_pct'])
     * @param  int  $stalenessMinutes  Ignore metrics older than this many minutes
     * @return array<int, array<string, float>> Nested [target_id][key] => value
     */
    public function forTargets(Collection $targetIds, array $keys, int $stalenessMinutes = 15): array
    {
        if ($targetIds->isEmpty() || empty($keys)) {
            return [];
        }

        $cutoff = CarbonImmutable::now()->subMinutes($stalenessMinutes);

        // DISTINCT ON (target_id, key) with ORDER BY captured_at DESC gives the latest
        // row per (target_id, key) pair in a single pass — no subqueries needed.
        $rows = DB::select(
            '
            SELECT DISTINCT ON (m.target_id, m.key)
                m.target_id,
                m.key,
                m.value
            FROM metrics m
            WHERE m.target_id = ANY(?)
              AND m.key = ANY(?)
              AND m.captured_at >= ?
            ORDER BY m.target_id, m.key, m.captured_at DESC
            ',
            [
                '{'.implode(',', $targetIds->all()).'}',
                '{'.implode(',', array_map(fn (string $k) => '"'.$k.'"', $keys)).'}',
                $cutoff->toDateTimeString(),
            ]
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row->target_id][$row->key] = (float) $row->value;
        }

        return $result;
    }
}
