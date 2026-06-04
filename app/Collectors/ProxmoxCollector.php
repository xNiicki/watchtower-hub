<?php

namespace App\Collectors;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Support\Facades\Http;

class ProxmoxCollector implements Collector
{
    public function key(): string
    {
        return 'proxmox';
    }

    public function enabled(): bool
    {
        return filled(config('watchtower.proxmox.base_url'))
            && filled(config('watchtower.proxmox.token_id'))
            && filled(config('watchtower.proxmox.token_secret'));
    }

    /**
     * @return list<TargetType>
     */
    public function scope(): array
    {
        return [TargetType::Node, TargetType::Lxc, TargetType::Vm, TargetType::Storage];
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
            throw new CollectorException("proxmox: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @return list<CheckResult>
     */
    private function doCollect(): array
    {
        $baseUrl = rtrim((string) config('watchtower.proxmox.base_url'), '/');
        $tokenId = (string) config('watchtower.proxmox.token_id');
        $tokenSecret = (string) config('watchtower.proxmox.token_secret');
        $verifyTls = (bool) config('watchtower.proxmox.verify_tls', false);

        $request = Http::timeout(10)
            ->withHeader('Authorization', "PVEAPIToken={$tokenId}={$tokenSecret}");

        if (! $verifyTls) {
            $request = $request->withoutVerifying();
        }

        $response = $request->get("{$baseUrl}/api2/json/cluster/resources");

        if (! $response->successful()) {
            throw new CollectorException(
                "Proxmox API returned HTTP {$response->status()}: {$response->body()}"
            );
        }

        $items = $response->json('data') ?? [];

        $results = [];

        foreach ($items as $item) {
            $type = $this->resolveType($item['type'] ?? '');

            if ($type === null) {
                continue;
            }

            $name = $this->resolveName($item, $type);

            if ($name === null) {
                continue;
            }

            $target = $this->resolveTarget($item, $type, $name);
            $status = $this->resolveStatus($item['status'] ?? '', $target);
            $metrics = ($status === TargetStatus::Up)
                ? $this->resolveMetrics($item)
                : [];

            $results[] = new CheckResult($target, $status, null, $metrics);
        }

        return $results;
    }

    private function resolveType(string $rawType): ?TargetType
    {
        return match ($rawType) {
            'node' => TargetType::Node,
            'lxc' => TargetType::Lxc,
            'qemu' => TargetType::Vm,
            'storage' => TargetType::Storage,
            default => null,
        };
    }

    private function resolveName(array $item, TargetType $type): ?string
    {
        return match ($type) {
            TargetType::Node => $item['node'] ?? null,
            TargetType::Storage => $item['storage'] ?? null,
            TargetType::Lxc, TargetType::Vm => $item['name'] ?? null,
            default => null,
        };
    }

    private function resolveStatus(string $rawStatus, Target $target): TargetStatus
    {
        if (! $target->enabled) {
            return TargetStatus::Paused;
        }

        return match ($rawStatus) {
            'running', 'online', 'available' => TargetStatus::Up,
            'stopped', 'offline' => TargetStatus::Down,
            default => TargetStatus::Unknown,
        };
    }

    /**
     * @return array<string, float>
     */
    private function resolveMetrics(array $item): array
    {
        $metrics = [];

        if (isset($item['cpu'], $item['maxcpu']) && $item['maxcpu'] > 0) {
            $metrics['cpu_pct'] = round((float) $item['cpu'] * 100, 1);
        }

        if (isset($item['mem'], $item['maxmem']) && $item['maxmem'] > 0) {
            $metrics['mem_pct'] = round((float) $item['mem'] / (float) $item['maxmem'] * 100, 1);
        }

        if (isset($item['disk'], $item['maxdisk']) && $item['maxdisk'] > 0) {
            $metrics['disk_pct'] = round((float) $item['disk'] / (float) $item['maxdisk'] * 100, 1);
        }

        return $metrics;
    }

    /**
     * Resolve an existing Target or auto-create it.
     */
    private function resolveTarget(array $item, TargetType $type, string $name): Target
    {
        $externalId = isset($item['vmid']) ? (string) $item['vmid'] : null;
        $node = $item['node'] ?? null;

        // Storage names are only unique per node — match on (type, name, node).
        if ($type === TargetType::Storage) {
            $target = Target::where('type', $type->value)
                ->where('name', $name)
                ->where('node', $node)
                ->first();
        } else {
            $target = Target::where('type', $type->value)->where('name', $name)->first();
        }

        if ($target === null) {
            $target = new Target;
            $target->type = $type;
            $target->name = $name;
            $target->external_id = $externalId;
            $target->node = ($type === TargetType::Node) ? null : $node;
            $target->enabled = true;
            $target->save();
        } else {
            $dirty = false;

            if ($target->external_id === null && $externalId !== null) {
                $target->external_id = $externalId;
                $dirty = true;
            }

            if ($target->node === null && $node !== null && $type !== TargetType::Node) {
                $target->node = $node;
                $dirty = true;
            }

            if ($dirty) {
                $target->save();
            }
        }

        return $target;
    }
}
