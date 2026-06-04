<?php

namespace Tests\Feature;

use App\Collectors\CollectorException;
use App\Collectors\HttpServiceCollector;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Models\Target;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HttpServiceCollectorTest extends TestCase
{
    use RefreshDatabase;

    private HttpServiceCollector $collector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collector = new HttpServiceCollector;
    }

    public function test_always_enabled(): void
    {
        $this->assertTrue($this->collector->enabled());
    }

    public function test_scope_is_service(): void
    {
        $this->assertSame([TargetType::Service], $this->collector->scope());
    }

    public function test_up_service_returns_up_with_latency_metric(): void
    {
        Target::factory()->service()->create([
            'name' => 'my-api',
            'check_config' => ['url' => 'http://192.168.1.1:8080/health', 'timeout_ms' => 5000],
        ]);

        Http::fake([
            'http://192.168.1.1:8080/health' => Http::response('OK', 200),
        ]);

        $results = $this->collector->collect();

        $this->assertCount(1, $results);
        $result = $results[0];

        $this->assertSame(TargetStatus::Up, $result->status);
        $this->assertNotNull($result->latencyMs);
        $this->assertArrayHasKey('latency_ms', $result->metrics);
        $this->assertSame((float) $result->latencyMs, $result->metrics['latency_ms']);
    }

    public function test_500_response_returns_down(): void
    {
        Target::factory()->service()->create([
            'name' => 'broken-api',
            'check_config' => ['url' => 'http://192.168.1.1:9000/health'],
        ]);

        Http::fake([
            'http://192.168.1.1:9000/health' => Http::response('Server Error', 500),
        ]);

        $results = $this->collector->collect();

        $this->assertCount(1, $results);
        $this->assertSame(TargetStatus::Down, $results[0]->status);
    }

    public function test_404_response_returns_down(): void
    {
        Target::factory()->service()->create([
            'name' => 'missing-api',
            'check_config' => ['url' => 'http://192.168.1.1:9001/health'],
        ]);

        Http::fake([
            'http://192.168.1.1:9001/health' => Http::response('Not Found', 404),
        ]);

        $results = $this->collector->collect();

        $this->assertCount(1, $results);
        $this->assertSame(TargetStatus::Down, $results[0]->status);
    }

    public function test_per_target_isolation_one_connection_failure_does_not_abort_others(): void
    {
        Target::factory()->service()->create([
            'name' => 'unreachable-api',
            'check_config' => ['url' => 'http://192.168.1.99:9999/health'],
        ]);

        Target::factory()->service()->create([
            'name' => 'healthy-api',
            'check_config' => ['url' => 'http://192.168.1.1:8080/health'],
        ]);

        Http::fake(function ($request) {
            if (str_contains($request->url(), '192.168.1.99')) {
                throw new ConnectionException('Connection refused');
            }

            return Http::response('OK', 200);
        });

        $results = $this->collector->collect();

        $this->assertCount(2, $results);

        $unreachable = collect($results)->first(fn ($r) => $r->target->name === 'unreachable-api');
        $healthy = collect($results)->first(fn ($r) => $r->target->name === 'healthy-api');

        $this->assertNotNull($unreachable);
        $this->assertSame(TargetStatus::Down, $unreachable->status);
        $this->assertNull($unreachable->latencyMs);

        $this->assertNotNull($healthy);
        $this->assertSame(TargetStatus::Up, $healthy->status);
    }

    public function test_disabled_service_returns_paused_no_metrics(): void
    {
        Target::factory()->service()->disabled()->create([
            'name' => 'disabled-api',
        ]);

        Http::fake();

        $results = $this->collector->collect();

        $this->assertCount(1, $results);
        $this->assertSame(TargetStatus::Paused, $results[0]->status);
        $this->assertEmpty($results[0]->metrics);

        Http::assertNothingSent();
    }

    public function test_missing_url_in_config_returns_unknown(): void
    {
        Target::factory()->service()->create([
            'name' => 'no-url-api',
            'check_config' => ['timeout_ms' => 3000],
        ]);

        Http::fake();

        $results = $this->collector->collect();

        $this->assertCount(1, $results);
        $this->assertSame(TargetStatus::Unknown, $results[0]->status);

        Http::assertNothingSent();
    }

    public function test_null_check_config_returns_unknown(): void
    {
        Target::factory()->service()->create([
            'name' => 'null-config-api',
            'check_config' => null,
        ]);

        Http::fake();

        $results = $this->collector->collect();

        $this->assertCount(1, $results);
        $this->assertSame(TargetStatus::Unknown, $results[0]->status);

        Http::assertNothingSent();
    }

    public function test_timeout_derivation_from_check_config(): void
    {
        // Http::fake() cannot observe Guzzle's timeout option, so the derivation
        // is a pure function tested directly instead of a false-positive Http::assertSent.
        $this->assertSame(2.0, HttpServiceCollector::timeoutSeconds(['timeout_ms' => 2000]));
        $this->assertSame(5.0, HttpServiceCollector::timeoutSeconds([]));
        $this->assertSame(0.3, HttpServiceCollector::timeoutSeconds(['timeout_ms' => 300]));
    }

    public function test_returns_empty_array_when_no_service_targets_exist(): void
    {
        Http::fake();

        $results = $this->collector->collect();

        $this->assertSame([], $results);

        Http::assertNothingSent();
    }

    public function test_non_http_throwable_in_check_target_propagates_as_collector_exception(): void
    {
        Target::factory()->service()->create([
            'name' => 'buggy-api',
            'check_config' => ['url' => 'http://192.168.1.1:9999/health'],
        ]);

        Http::fake(function () {
            throw new \RuntimeException('unexpected internal error');
        });

        Log::spy();

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('http: unexpected internal error');

        $this->collector->collect();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn ($msg) => str_contains($msg, 'buggy-api'));
    }

    public function test_3xx_redirect_response_returns_up(): void
    {
        Target::factory()->service()->create([
            'name' => 'redirect-api',
            'check_config' => ['url' => 'http://192.168.1.1:8081/health'],
        ]);

        Http::fake([
            'http://192.168.1.1:8081/health' => Http::response('Moved', 301),
        ]);

        $results = $this->collector->collect();

        $this->assertCount(1, $results);
        $this->assertSame(TargetStatus::Up, $results[0]->status);
    }
}
