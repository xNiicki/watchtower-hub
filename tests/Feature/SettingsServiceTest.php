<?php

namespace Tests\Feature;

use App\Collectors\ProxmoxCollector;
use App\Models\Setting;
use App\Services\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    private Settings $settings;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh instance per test so the per-request decrypt cache never leaks.
        $this->settings = new Settings;
    }

    // -------------------------------------------------------------------------
    // get / set round-trip + encryption
    // -------------------------------------------------------------------------

    public function test_set_then_get_round_trips_the_value(): void
    {
        $this->settings->set('proxmox.base_url', 'https://pve.local:8006');

        $this->assertSame('https://pve.local:8006', $this->settings->get('proxmox.base_url'));
    }

    public function test_value_is_encrypted_at_rest_and_decrypts_on_get(): void
    {
        $this->settings->set('proxmox.token_secret', 'abc');

        $raw = Setting::query()->where('key', 'proxmox.token_secret')->value('value');

        // Stored ciphertext must not equal the plaintext...
        $this->assertNotSame('abc', $raw);
        $this->assertSame('abc', Crypt::decryptString($raw));

        // ...and a fresh service instance (no cache) still decrypts it back.
        $this->assertSame('abc', (new Settings)->get('proxmox.token_secret'));
    }

    public function test_missing_key_returns_null(): void
    {
        $this->assertNull($this->settings->get('does.not.exist'));
    }

    public function test_set_overwrites_existing_value(): void
    {
        $this->settings->set('ntfy.topic', 'first');
        $this->settings->set('ntfy.topic', 'second');

        $this->assertSame('second', $this->settings->get('ntfy.topic'));
        $this->assertSame(1, Setting::query()->where('key', 'ntfy.topic')->count());
    }

    public function test_set_null_clears_the_setting(): void
    {
        $this->settings->set('ntfy.token', 'token-123');
        $this->settings->set('ntfy.token', null);

        $this->assertNull($this->settings->get('ntfy.token'));
        $this->assertDatabaseMissing('settings', ['key' => 'ntfy.token']);
    }

    public function test_set_empty_string_clears_the_setting(): void
    {
        $this->settings->set('ntfy.token', 'token-123');
        $this->settings->set('ntfy.token', '');

        $this->assertNull($this->settings->get('ntfy.token'));
        $this->assertDatabaseMissing('settings', ['key' => 'ntfy.token']);
    }

    public function test_undecryptable_value_returns_null(): void
    {
        // Simulate corrupted/foreign ciphertext written directly to the column.
        Setting::query()->create(['key' => 'proxmox.token_secret', 'value' => 'not-valid-ciphertext']);

        $this->assertNull($this->settings->get('proxmox.token_secret'));
    }

    // -------------------------------------------------------------------------
    // Typed accessors: DB → env/config fallback
    // -------------------------------------------------------------------------

    public function test_proxmox_accessor_falls_back_to_config_when_db_empty(): void
    {
        config([
            'watchtower.proxmox.base_url' => 'https://config-pve:8006',
            'watchtower.proxmox.token_id' => 'cfg@pam!hub',
            'watchtower.proxmox.token_secret' => 'cfg-secret',
            'watchtower.proxmox.verify_tls' => true,
        ]);

        $config = $this->settings->proxmox();

        $this->assertSame('https://config-pve:8006', $config['base_url']);
        $this->assertSame('cfg@pam!hub', $config['token_id']);
        $this->assertSame('cfg-secret', $config['token_secret']);
        $this->assertTrue($config['verify_tls']);
    }

    public function test_proxmox_accessor_db_value_wins_over_config(): void
    {
        config([
            'watchtower.proxmox.base_url' => 'https://config-pve:8006',
            'watchtower.proxmox.token_secret' => 'cfg-secret',
        ]);

        $this->settings->set('proxmox.base_url', 'https://db-pve:8006');
        $this->settings->set('proxmox.token_secret', 'db-secret');

        $config = $this->settings->proxmox();

        $this->assertSame('https://db-pve:8006', $config['base_url']);
        $this->assertSame('db-secret', $config['token_secret']);
    }

    public function test_pbs_accessor_db_value_wins_over_config(): void
    {
        config(['watchtower.pbs.base_url' => 'https://config-pbs:8007']);

        $this->settings->set('pbs.base_url', 'https://db-pbs:8007');

        $this->assertSame('https://db-pbs:8007', $this->settings->pbs()['base_url']);
    }

    public function test_ntfy_accessor_falls_back_to_config_when_db_empty(): void
    {
        config([
            'watchtower.ntfy.base_url' => 'http://config-ntfy',
            'watchtower.ntfy.topic' => 'cfg-topic',
            'watchtower.ntfy.token' => 'cfg-token',
        ]);

        $config = $this->settings->ntfy();

        $this->assertSame('http://config-ntfy', $config['base_url']);
        $this->assertSame('cfg-topic', $config['topic']);
        $this->assertSame('cfg-token', $config['token']);
    }

    // -------------------------------------------------------------------------
    // verify_tls boolean casting
    // -------------------------------------------------------------------------

    public function test_verify_tls_casts_db_false_string_to_bool_false(): void
    {
        // config fallback is true, but the DB string 'false' must win and cast to false.
        config(['watchtower.proxmox.verify_tls' => true]);

        $this->settings->set('proxmox.verify_tls', 'false');

        $this->assertFalse($this->settings->proxmox()['verify_tls']);
    }

    public function test_verify_tls_casts_db_true_string_to_bool_true(): void
    {
        config(['watchtower.proxmox.verify_tls' => false]);

        $this->settings->set('proxmox.verify_tls', 'true');

        $this->assertTrue($this->settings->proxmox()['verify_tls']);
    }

    public function test_verify_tls_falls_back_to_config_bool_when_db_empty(): void
    {
        config(['watchtower.pbs.verify_tls' => true]);

        $this->assertTrue($this->settings->pbs()['verify_tls']);
    }

    // -------------------------------------------------------------------------
    // End-to-end: a collector reads its connection config from the DB
    // -------------------------------------------------------------------------

    public function test_proxmox_collector_uses_db_setting_for_base_url(): void
    {
        // No config — prove the value comes purely from the DB.
        config([
            'watchtower.proxmox.base_url' => null,
            'watchtower.proxmox.token_id' => null,
            'watchtower.proxmox.token_secret' => null,
        ]);

        $this->settings->set('proxmox.base_url', 'https://db-host.test:8006');
        $this->settings->set('proxmox.token_id', 'db@pam!hub');
        $this->settings->set('proxmox.token_secret', 'db-secret');

        // Bind our pre-seeded Settings instance so the collector resolves it.
        $this->app->instance(Settings::class, $this->settings);

        Http::fake(['db-host.test:8006/*' => Http::response(['data' => []], 200)]);

        $collector = new ProxmoxCollector($this->settings);

        $this->assertTrue($collector->enabled());

        $collector->collect();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'db-host.test:8006')
                && str_contains($request->url(), 'cluster/resources')
                && $request->hasHeader('Authorization', 'PVEAPIToken=db@pam!hub=db-secret');
        });
    }
}
