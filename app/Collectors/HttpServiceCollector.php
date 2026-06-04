<?php

namespace App\Collectors;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpServiceCollector implements Collector
{
    public function key(): string
    {
        return 'http';
    }

    public function enabled(): bool
    {
        // Config-free: always enabled. It may simply find zero service targets.
        return true;
    }

    /**
     * @return list<TargetType>
     */
    public function scope(): array
    {
        return [TargetType::Service];
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
            throw new CollectorException("http: {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @return list<CheckResult>
     */
    private function doCollect(): array
    {
        $targets = Target::where('type', TargetType::Service->value)->get();

        $results = [];

        foreach ($targets as $target) {
            $results[] = $this->checkTarget($target);
        }

        return $results;
    }

    /**
     * Request timeout in seconds derived from a target's check_config.
     *
     * @param  array<string, mixed>  $config
     */
    public static function timeoutSeconds(array $config): float
    {
        return ((int) ($config['timeout_ms'] ?? 5000)) / 1000;
    }

    private function checkTarget(Target $target): CheckResult
    {
        if (! $target->enabled) {
            return new CheckResult($target, TargetStatus::Paused);
        }

        $config = $target->check_config ?? [];
        $url = $config['url'] ?? null;

        if (! filled($url)) {
            // Target is enabled but has no URL — we cannot check it.
            return new CheckResult($target, TargetStatus::Unknown);
        }

        $verifyTls = (bool) ($config['verify_tls'] ?? true);

        try {
            $request = Http::timeout(self::timeoutSeconds($config));

            if (! $verifyTls) {
                $request = $request->withoutVerifying();
            }

            $start = hrtime(true);
            $response = $request->get($url);
            $latencyMs = (int) round((hrtime(true) - $start) / 1_000_000);

            // HTTP connectivity succeeded — determine Up/Down from status code.
            // Unlike infrastructure collectors where an API failure means "we don't know",
            // reachability IS the check here: a 4xx/5xx proves the service responded (Down is
            // correct), and a connection failure also proves it is unreachable (Down, null latency).
            if ($response->successful() || $response->redirect()) {
                return new CheckResult($target, TargetStatus::Up, $latencyMs, ['latency_ms' => (float) $latencyMs]);
            }

            return new CheckResult($target, TargetStatus::Down, $latencyMs);
        } catch (ConnectionException $e) {
            // Per-target isolation: a connection failure for one service must not abort the others.
            // Down (null latency) is correct — the service is definitively unreachable.
            return new CheckResult($target, TargetStatus::Down);
        } catch (\Throwable $e) {
            // Unexpected exceptions are bugs and must surface so the orchestrator can handle them.
            Log::error('HttpServiceCollector: unexpected error for target ['.$target->name.']', ['exception' => $e]);
            throw $e;
        }
    }
}
