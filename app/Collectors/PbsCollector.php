<?php

namespace App\Collectors;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PbsCollector implements Collector
{
    public function key(): string
    {
        return 'pbs';
    }

    public function enabled(): bool
    {
        return filled(config('watchtower.pbs.base_url'))
            && filled(config('watchtower.pbs.token_id'))
            && filled(config('watchtower.pbs.token_secret'));
    }

    /**
     * Empty scope: PBS creates and owns its own storage targets (node='pbs').
     * An empty scope means the orchestrator's vanished-target reconciliation loop
     * skips this collector entirely — it must not mark PBS datastores as vanished
     * just because Proxmox doesn't report them. PBS is the sole authority on its
     * own datastores; each one is auto-created here on first collection.
     *
     * @return list<TargetType>
     */
    public function scope(): array
    {
        return [];
    }

    /**
     * @return list<CheckResult>
     */
    public function collect(): array
    {
        try {
            return $this->doCollect();
        } catch (CollectorException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new CollectorException("pbs: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @return list<CheckResult>
     */
    private function doCollect(): array
    {
        $baseUrl = rtrim((string) config('watchtower.pbs.base_url'), '/');
        $tokenId = (string) config('watchtower.pbs.token_id');
        $tokenSecret = (string) config('watchtower.pbs.token_secret');
        $verifyTls = (bool) config('watchtower.pbs.verify_tls', false);

        // PBS uses a colon between token id and secret (unlike Proxmox VE which uses an equals sign).
        $request = Http::timeout(10)
            ->withHeader('Authorization', "PBSAPIToken={$tokenId}:{$tokenSecret}");

        if (! $verifyTls) {
            $request = $request->withoutVerifying();
        }

        $usageResponse = $request->get("{$baseUrl}/api2/json/status/datastore-usage");

        if (! $usageResponse->successful()) {
            throw new CollectorException(
                "PBS API returned HTTP {$usageResponse->status()}: {$usageResponse->body()}"
            );
        }

        $datastores = $usageResponse->json('data') ?? [];

        $results = [];

        foreach ($datastores as $datastore) {
            $storeName = $datastore['store'] ?? null;

            if ($storeName === null) {
                continue;
            }

            // Auto-create or resolve a dedicated Storage target for this PBS datastore.
            // node='pbs' is a constant sentinel that distinguishes PBS-owned datastores from
            // PVE storage targets that might share the same name (e.g. a PVE storage named
            // "backup"). The unique key is (type, name, node), so this is collision-safe.
            $target = $this->resolveTarget($storeName);

            if (! $target->enabled) {
                $results[] = new CheckResult($target, TargetStatus::Paused);

                continue;
            }

            // A broken/unmounted datastore has an 'error' field and no total/used values.
            // This is a real problem worth surfacing — a broken backup store is Down.
            if (isset($datastore['error'])) {
                $results[] = new CheckResult($target, TargetStatus::Down);

                continue;
            }

            $total = (float) ($datastore['total'] ?? 0);
            $used = (float) ($datastore['used'] ?? 0);

            // A malformed/edge API response may omit 'total' entirely (no error field either).
            // Dividing by zero would throw — treat this like a broken store: Down, no metrics.
            if ($total <= 0) {
                Log::warning("pbs: datastore {$storeName} has no usable total");

                $results[] = new CheckResult($target, TargetStatus::Down);

                continue;
            }

            // PBS's datastore view (post-dedup/GC) is recorded separately from PVE's mount
            // view (disk_pct) so the alert engine reads one unambiguous series.
            $metrics = ['pbs_disk_pct' => round($used / $total * 100, 1)];

            // Compute backup age across all namespaces. Per-datastore failures (namespace
            // listing or group fetching) must not abort the whole collector run — we record
            // what we know (disk_pct) and log a warning for the unknown part.
            try {
                $newestBackupTime = $this->fetchNewestBackupTime($request, $baseUrl, $storeName);

                if ($newestBackupTime !== null) {
                    $metrics['backup_age_hours'] = round(
                        Carbon::createFromTimestamp($newestBackupTime)->diffInSeconds(Carbon::now()) / 3600,
                        1
                    );
                }
                // Empty datastore (no groups in any namespace): reachable but nothing to back up yet.
                // Status stays Up; backup_age_hours is omitted. Upper layers decide if this is alertable.
            } catch (\Throwable $e) {
                Log::warning("PbsCollector: failed to fetch backup age for store '{$storeName}': {$e->getMessage()}", [
                    'exception' => $e,
                ]);
                // Fall through — disk_pct is still recorded; age is unknown.
            }

            $results[] = new CheckResult($target, TargetStatus::Up, null, $metrics);
        }

        return $results;
    }

    /**
     * Resolve (or auto-create) a Storage target for a PBS datastore.
     * PBS datastores are identified by (type=Storage, name={store}, node='pbs').
     */
    private function resolveTarget(string $storeName): Target
    {
        $target = Target::where('type', TargetType::Storage->value)
            ->where('name', $storeName)
            ->where('node', 'pbs')
            ->first();

        if ($target === null) {
            $target = new Target;
            $target->type = TargetType::Storage;
            $target->name = $storeName;
            $target->node = 'pbs';
            $target->external_id = null;
            $target->enabled = true;
            $target->save();
        }

        return $target;
    }

    /**
     * Fetch the newest last-backup timestamp across all namespaces of a datastore.
     * Returns null when the datastore has no backup groups at all.
     *
     * Root namespace is always listed (empty string '').  If the namespace endpoint
     * errors or returns empty, we fall back to querying just the root namespace.
     */
    private function fetchNewestBackupTime(PendingRequest $request, string $baseUrl, string $storeName): ?int
    {
        $namespaces = $this->fetchNamespaces($request, $baseUrl, $storeName);

        $newest = null;

        foreach ($namespaces as $ns) {
            $groupsUrl = "{$baseUrl}/api2/json/admin/datastore/{$storeName}/groups";

            if ($ns !== '') {
                $groupsUrl .= '?ns='.urlencode($ns);
            }

            $groupsResponse = $request->get($groupsUrl);

            if (! $groupsResponse->successful()) {
                throw new \RuntimeException(
                    "PBS groups API returned HTTP {$groupsResponse->status()} for store '{$storeName}' ns='{$ns}'"
                );
            }

            $groups = $groupsResponse->json('data') ?? [];

            foreach ($groups as $group) {
                $ts = $group['last-backup'] ?? null;

                if ($ts !== null && ($newest === null || $ts > $newest)) {
                    $newest = (int) $ts;
                }
            }
        }

        return $newest;
    }

    /**
     * Fetch the list of namespaces for a datastore.
     * Falls back to [''] (root only) when the endpoint errors or returns empty.
     *
     * @return list<string>
     */
    private function fetchNamespaces(PendingRequest $request, string $baseUrl, string $storeName): array
    {
        try {
            $nsResponse = $request->get("{$baseUrl}/api2/json/admin/datastore/{$storeName}/namespace");

            if (! $nsResponse->successful()) {
                return [''];
            }

            $namespaces = collect($nsResponse->json('data') ?? [])
                ->pluck('ns')
                ->map(fn ($ns) => (string) $ns)
                ->unique()
                ->values()
                ->all();

            return $namespaces !== [] ? $namespaces : [''];
        } catch (\Throwable) {
            // Namespace endpoint unavailable — fall back to root namespace only.
            return [''];
        }
    }
}
