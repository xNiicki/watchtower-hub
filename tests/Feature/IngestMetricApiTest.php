<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\AppMetric;
use App\Models\MonitoredApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngestMetricApiTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $o = []): array
    {
        return array_merge([
            'slug' => 'booking', 'schemaVersion' => 1,
            'points' => [
                ['key' => 'requests', 'value' => 120, 'bucketAt' => '2026-06-05T05:00:00+00:00'],
                ['key' => 'request_latency_avg_ms', 'value' => 45.5, 'bucketAt' => '2026-06-05T05:00:00+00:00'],
            ],
        ], $o);
    }

    private function token(MonitoredApp $a): string
    {
        return $a->createToken('i', [TokenAbility::Ingest->value])->plainTextToken;
    }

    public function test_unauthenticated(): void
    {
        $this->postJson('/api/ingest/metrics', $this->payload())->assertUnauthorized();
    }

    public function test_mobile_token_forbidden(): void
    {
        $t = User::factory()->create()->createToken('p', TokenAbility::mobile())->plainTextToken;
        $this->postJson('/api/ingest/metrics', $this->payload(), ['Authorization' => 'Bearer '.$t])->assertForbidden();
    }

    public function test_valid_points_upserted(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/metrics', $this->payload(), ['Authorization' => 'Bearer '.$this->token($a)])->assertNoContent();
        $this->assertSame(2, AppMetric::where('app_id', $a->id)->count());
    }

    public function test_idempotent_reship(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $h = ['Authorization' => 'Bearer '.$this->token($a)];
        $this->postJson('/api/ingest/metrics', $this->payload(), $h)->assertNoContent();
        $this->postJson('/api/ingest/metrics', $this->payload(['points' => [['key' => 'requests', 'value' => 999, 'bucketAt' => '2026-06-05T05:00:00+00:00']]]), $h)->assertNoContent();
        $this->assertSame(999.0, (float) AppMetric::where('app_id', $a->id)->where('key', 'requests')->first()->value);
    }

    public function test_non_aligned_timestamp_normalized_to_minute_bucket(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/metrics', $this->payload(['points' => [
            ['key' => 'requests', 'value' => 5, 'bucketAt' => '2026-06-05T05:00:30+00:00'],
        ]]), ['Authorization' => 'Bearer '.$this->token($a)])->assertNoContent();

        $metric = AppMetric::where('app_id', $a->id)->where('key', 'requests')->sole();
        $this->assertSame('2026-06-05 05:00:00', $metric->bucket_at->utc()->toDateTimeString());
    }

    public function test_duplicate_points_in_payload_dedupe_last_wins(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/metrics', $this->payload(['points' => [
            ['key' => 'requests', 'value' => 1, 'bucketAt' => '2026-06-05T05:00:00+00:00'],
            ['key' => 'requests', 'value' => 2, 'bucketAt' => '2026-06-05T05:00:30+00:00'],
        ]]), ['Authorization' => 'Bearer '.$this->token($a)])->assertNoContent();

        $metric = AppMetric::where('app_id', $a->id)->where('key', 'requests')->sole();
        $this->assertSame(2.0, (float) $metric->value);
    }

    public function test_slug_mismatch_forbidden(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/metrics', $this->payload(['slug' => 'x']), ['Authorization' => 'Bearer '.$this->token($a)])->assertForbidden();
    }

    public function test_bad_key_422(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/metrics', $this->payload(['points' => [['key' => 'evil', 'value' => 1, 'bucketAt' => '2026-06-05T05:00:00+00:00']]]), ['Authorization' => 'Bearer '.$this->token($a)])->assertStatus(422);
    }

    public function test_unknown_schema_422(): void
    {
        $a = MonitoredApp::factory()->create(['slug' => 'booking']);
        $this->postJson('/api/ingest/metrics', $this->payload(['schemaVersion' => 9]), ['Authorization' => 'Bearer '.$this->token($a)])->assertStatus(422);
    }
}
