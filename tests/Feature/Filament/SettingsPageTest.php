<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Settings;
use App\Models\Setting;
use App\Models\User;
use App\Services\Settings as SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOperator(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        return $user;
    }

    public function test_unauthenticated_user_is_redirected(): void
    {
        $this->get('/admin/settings')->assertRedirect('/admin/login');
    }

    public function test_authenticated_user_can_render_the_page(): void
    {
        $this->actingAsOperator();

        Livewire::test(Settings::class)->assertOk();
    }

    public function test_saving_persists_non_secret_values(): void
    {
        $this->actingAsOperator();

        Livewire::test(Settings::class)
            ->set('data.proxmox.base_url', 'https://pve.example.com:8006')
            ->set('data.proxmox.token_id', 'root@pam!mon')
            ->set('data.proxmox.verify_tls', true)
            ->set('data.ntfy.base_url', 'https://ntfy.example.com')
            ->set('data.ntfy.topic', 'watchtower')
            ->call('save')
            ->assertHasNoErrors();

        $settings = app(SettingsService::class);

        $this->assertSame('https://pve.example.com:8006', $settings->get('proxmox.base_url'));
        $this->assertSame('root@pam!mon', $settings->get('proxmox.token_id'));
        $this->assertSame('true', $settings->get('proxmox.verify_tls'));
        $this->assertSame('watchtower', $settings->get('ntfy.topic'));
    }

    public function test_blank_secret_field_does_not_overwrite_an_existing_secret(): void
    {
        $this->actingAsOperator();

        $settings = app(SettingsService::class);
        $settings->set('proxmox.token_secret', 'original-secret');

        Livewire::test(Settings::class)
            ->set('data.proxmox.base_url', 'https://pve.example.com:8006')
            // Leave proxmox.token_secret blank.
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('original-secret', app(SettingsService::class)->get('proxmox.token_secret'));
    }

    public function test_provided_secret_is_saved_and_stored_encrypted(): void
    {
        $this->actingAsOperator();

        Livewire::test(Settings::class)
            ->set('data.proxmox.token_secret', 'brand-new-secret')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('brand-new-secret', app(SettingsService::class)->get('proxmox.token_secret'));

        // Stored ciphertext must not equal the plaintext.
        $raw = Setting::query()->where('key', 'proxmox.token_secret')->value('value');
        $this->assertNotNull($raw);
        $this->assertNotSame('brand-new-secret', $raw);
        $this->assertStringNotContainsString('brand-new-secret', $raw);
    }

    public function test_secret_is_not_rendered_into_the_page_html(): void
    {
        $this->actingAsOperator();

        app(SettingsService::class)->set('proxmox.token_secret', 'super-secret-value');

        Livewire::test(Settings::class)
            ->assertDontSee('super-secret-value');
    }

    public function test_proxmox_test_connection_success(): void
    {
        $this->actingAsOperator();

        $settings = app(SettingsService::class);
        $settings->set('proxmox.base_url', 'https://pve.example.com:8006');
        $settings->set('proxmox.token_id', 'root@pam!mon');
        $settings->set('proxmox.token_secret', 'secret');

        Http::fake([
            '*/api2/json/version' => Http::response(['data' => ['version' => '8.1']], 200),
        ]);

        Livewire::test(Settings::class)
            ->call('testProxmox')
            ->assertHasNoErrors()
            ->assertNotified();
    }

    public function test_proxmox_test_connection_handles_http_error(): void
    {
        $this->actingAsOperator();

        $settings = app(SettingsService::class);
        $settings->set('proxmox.base_url', 'https://pve.example.com:8006');
        $settings->set('proxmox.token_id', 'root@pam!mon');
        $settings->set('proxmox.token_secret', 'secret');

        Http::fake([
            '*/api2/json/version' => Http::response('Unauthorized', 401),
        ]);

        Livewire::test(Settings::class)
            ->call('testProxmox')
            ->assertHasNoErrors()
            ->assertNotified();
    }

    public function test_pbs_test_connection_handles_connection_exception(): void
    {
        $this->actingAsOperator();

        $settings = app(SettingsService::class);
        $settings->set('pbs.base_url', 'https://pbs.example.com:8007');
        $settings->set('pbs.token_id', 'root@pbs!mon');
        $settings->set('pbs.token_secret', 'secret');

        Http::fake(fn () => throw new ConnectionException('down'));

        Livewire::test(Settings::class)
            ->call('testPbs')
            ->assertHasNoErrors()
            ->assertNotified();
    }

    public function test_ntfy_test_connection_success(): void
    {
        $this->actingAsOperator();

        $settings = app(SettingsService::class);
        $settings->set('ntfy.base_url', 'https://ntfy.example.com');
        $settings->set('ntfy.topic', 'watchtower');

        Http::fake([
            '*' => Http::response('', 200),
        ]);

        Livewire::test(Settings::class)
            ->call('testNtfy')
            ->assertHasNoErrors()
            ->assertNotified();
    }
}
