<?php

namespace App\Filament\Pages;

use App\Services\Settings as SettingsService;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Operator-facing configuration page for the three upstream integrations
 * (Proxmox, PBS, ntfy). Non-secret fields round-trip through the {@see SettingsService};
 * secret fields are never rendered back to the browser — they show a placeholder and are
 * only persisted when the operator types a new value (blank = keep existing).
 */
class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?int $navigationSort = 90;

    /**
     * Placeholder shown in every secret field so the real secret never reaches the DOM.
     */
    private const SECRET_PLACEHOLDER = '•••••• (leave blank to keep)';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public function mount(SettingsService $settings): void
    {
        $proxmox = $settings->proxmox();
        $pbs = $settings->pbs();
        $ntfy = $settings->ntfy();

        // Prefill non-secret fields only. Secret fields stay blank (placeholder is shown instead).
        $this->form->fill([
            'proxmox' => [
                'base_url' => $proxmox['base_url'],
                'token_id' => $proxmox['token_id'],
                'verify_tls' => $proxmox['verify_tls'],
            ],
            'pbs' => [
                'base_url' => $pbs['base_url'],
                'token_id' => $pbs['token_id'],
                'verify_tls' => $pbs['verify_tls'],
            ],
            'ntfy' => [
                'base_url' => $ntfy['base_url'],
                'topic' => $ntfy['topic'] ?? 'watchtower',
            ],
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Proxmox')
                    ->description('Proxmox VE API connection used to discover and poll nodes, VMs, LXCs and storage.')
                    ->schema([
                        TextInput::make('proxmox.base_url')
                            ->label('Base URL')
                            ->url()
                            ->placeholder('https://pve.example.com:8006'),
                        TextInput::make('proxmox.token_id')
                            ->label('Token ID')
                            ->placeholder('user@pam!tokenname'),
                        TextInput::make('proxmox.token_secret')
                            ->label('Token secret')
                            ->password()
                            ->revealable()
                            ->placeholder(self::SECRET_PLACEHOLDER)
                            ->dehydrated(),
                        Toggle::make('proxmox.verify_tls')
                            ->label('Verify TLS'),
                    ]),
                Section::make('PBS')
                    ->description('Proxmox Backup Server API connection used to monitor datastore usage.')
                    ->schema([
                        TextInput::make('pbs.base_url')
                            ->label('Base URL')
                            ->url()
                            ->placeholder('https://pbs.example.com:8007'),
                        TextInput::make('pbs.token_id')
                            ->label('Token ID')
                            ->placeholder('user@pbs!tokenname'),
                        TextInput::make('pbs.token_secret')
                            ->label('Token secret')
                            ->password()
                            ->revealable()
                            ->placeholder(self::SECRET_PLACEHOLDER)
                            ->dehydrated(),
                        Toggle::make('pbs.verify_tls')
                            ->label('Verify TLS'),
                    ]),
                Section::make('ntfy')
                    ->description('ntfy server used to deliver push notifications to operators.')
                    ->schema([
                        TextInput::make('ntfy.base_url')
                            ->label('Base URL')
                            ->url()
                            ->placeholder('https://ntfy.sh'),
                        TextInput::make('ntfy.topic')
                            ->label('Topic')
                            ->default('watchtower')
                            ->placeholder('watchtower'),
                        TextInput::make('ntfy.token')
                            ->label('Token (optional)')
                            ->password()
                            ->revealable()
                            ->placeholder(self::SECRET_PLACEHOLDER)
                            ->dehydrated(),
                    ]),
            ]);
    }

    public function save(SettingsService $settings): void
    {
        $state = $this->form->getState();

        // Non-secret fields are always written. Secret fields are only written when a
        // non-empty value was typed; a blank secret field keeps the previously-stored secret.
        $this->persistGroup($settings, 'proxmox', $state['proxmox'] ?? [], ['base_url', 'token_id'], ['token_secret'], ['verify_tls']);
        $this->persistGroup($settings, 'pbs', $state['pbs'] ?? [], ['base_url', 'token_id'], ['token_secret'], ['verify_tls']);
        $this->persistGroup($settings, 'ntfy', $state['ntfy'] ?? [], ['base_url', 'topic'], ['token'], []);

        // Clear typed secrets out of the Livewire component state so they are not retained client-side.
        $this->data['proxmox']['token_secret'] = null;
        $this->data['pbs']['token_secret'] = null;
        $this->data['ntfy']['token'] = null;

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    /**
     * Persist a settings group: plain fields always, secret fields only when non-blank, booleans as 'true'/'false'.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $plainFields
     * @param  list<string>  $secretFields
     * @param  list<string>  $boolFields
     */
    private function persistGroup(SettingsService $settings, string $prefix, array $values, array $plainFields, array $secretFields, array $boolFields): void
    {
        foreach ($plainFields as $field) {
            $settings->set("{$prefix}.{$field}", $this->stringOrNull($values[$field] ?? null));
        }

        foreach ($secretFields as $field) {
            $value = $values[$field] ?? null;

            if ($value !== null && $value !== '') {
                $settings->set("{$prefix}.{$field}", (string) $value);
            }
        }

        foreach ($boolFields as $field) {
            $settings->set("{$prefix}.{$field}", ! empty($values[$field]) ? 'true' : 'false');
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    public function testProxmox(SettingsService $settings): void
    {
        $config = $settings->proxmox();

        if (empty($config['base_url']) || empty($config['token_id']) || empty($config['token_secret'])) {
            $this->dangerNotification('Proxmox', 'not configured — save base URL, token ID and secret first.');

            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "PVEAPIToken={$config['token_id']}={$config['token_secret']}",
            ])
                ->when(! $config['verify_tls'], fn ($request) => $request->withoutVerifying())
                ->timeout(8)
                ->get(rtrim((string) $config['base_url'], '/').'/api2/json/version');

            if ($response->successful()) {
                $version = data_get($response->json(), 'data.version', '?');
                $this->successNotification('Proxmox', "connected (v{$version})");

                return;
            }

            $this->dangerNotification('Proxmox', "failed — HTTP {$response->status()}");
        } catch (ConnectionException $e) {
            $this->dangerNotification('Proxmox', 'connection error');
        } catch (\Throwable $e) {
            $this->dangerNotification('Proxmox', 'connection error');
        }
    }

    public function testPbs(SettingsService $settings): void
    {
        $config = $settings->pbs();

        if (empty($config['base_url']) || empty($config['token_id']) || empty($config['token_secret'])) {
            $this->dangerNotification('PBS', 'not configured — save base URL, token ID and secret first.');

            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "PBSAPIToken={$config['token_id']}:{$config['token_secret']}",
            ])
                ->when(! $config['verify_tls'], fn ($request) => $request->withoutVerifying())
                ->timeout(8)
                ->get(rtrim((string) $config['base_url'], '/').'/api2/json/status/datastore-usage');

            if ($response->successful()) {
                $this->successNotification('PBS', 'connected');

                return;
            }

            $this->dangerNotification('PBS', "failed — HTTP {$response->status()}");
        } catch (ConnectionException $e) {
            $this->dangerNotification('PBS', 'connection error');
        } catch (\Throwable $e) {
            $this->dangerNotification('PBS', 'connection error');
        }
    }

    public function testNtfy(SettingsService $settings): void
    {
        $config = $settings->ntfy();

        if (empty($config['base_url']) || empty($config['topic'])) {
            $this->dangerNotification('ntfy', 'not configured — save base URL and topic first.');

            return;
        }

        try {
            $request = Http::withHeaders([
                'Title' => 'Watchtower test',
            ])->timeout(8);

            if (! empty($config['token'])) {
                $request = $request->withToken((string) $config['token']);
            }

            $url = rtrim((string) $config['base_url'], '/').'/'.ltrim((string) $config['topic'], '/');
            $response = $request->withBody('Watchtower test notification', 'text/plain')->post($url);

            if ($response->successful()) {
                $this->successNotification('ntfy', 'connected — test message sent');

                return;
            }

            $this->dangerNotification('ntfy', "failed — HTTP {$response->status()}");
        } catch (ConnectionException $e) {
            $this->dangerNotification('ntfy', 'connection error');
        } catch (\Throwable $e) {
            $this->dangerNotification('ntfy', 'connection error');
        }
    }

    private function successNotification(string $service, string $message): void
    {
        Notification::make()
            ->title("{$service}: {$message}")
            ->success()
            ->send();
    }

    private function dangerNotification(string $service, string $message): void
    {
        Notification::make()
            ->title("{$service}: {$message}")
            ->danger()
            ->send();
    }
}
