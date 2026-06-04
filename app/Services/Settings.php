<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * DB-backed configuration store with env/config fallback.
 *
 * Each setting is stored under a dot key (e.g. 'proxmox.base_url') with its value
 * encrypted at rest via Crypt::encryptString. The typed accessors resolve a value
 * by preferring the DB entry and falling back to config('watchtower.*') — which is
 * sourced from .env — so behaviour is identical to the pre-DB world when no setting
 * has been persisted.
 *
 * Registered as a singleton so the per-request decrypt cache is shared across the
 * collectors/notifier within a single run.
 */
class Settings
{
    /**
     * Per-request cache of decrypted values, keyed by setting key.
     * A sentinel is stored even for missing keys to avoid repeated DB lookups.
     *
     * @var array<string, string|null>
     */
    private array $cache = [];

    /**
     * Return the decrypted DB value for a key, or null when absent/undecryptable.
     */
    public function get(string $key): ?string
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        $setting = Setting::query()->where('key', $key)->first();

        if ($setting === null || $setting->value === null) {
            return $this->cache[$key] = null;
        }

        try {
            return $this->cache[$key] = Crypt::decryptString($setting->value);
        } catch (DecryptException) {
            return $this->cache[$key] = null;
        }
    }

    /**
     * Upsert an encrypted value for a key. A null/empty value clears the setting.
     */
    public function set(string $key, ?string $value): void
    {
        unset($this->cache[$key]);

        if ($value === null || $value === '') {
            Setting::query()->where('key', $key)->delete();

            return;
        }

        Setting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => Crypt::encryptString($value)],
        );
    }

    /**
     * Resolve Proxmox connection config (DB wins, else config/watchtower.php → .env).
     *
     * @return array{base_url: ?string, token_id: ?string, token_secret: ?string, verify_tls: bool}
     */
    public function proxmox(): array
    {
        return [
            'base_url' => $this->get('proxmox.base_url') ?? config('watchtower.proxmox.base_url'),
            'token_id' => $this->get('proxmox.token_id') ?? config('watchtower.proxmox.token_id'),
            'token_secret' => $this->get('proxmox.token_secret') ?? config('watchtower.proxmox.token_secret'),
            'verify_tls' => $this->resolveBool('proxmox.verify_tls', 'watchtower.proxmox.verify_tls'),
        ];
    }

    /**
     * Resolve PBS connection config (DB wins, else config/watchtower.php → .env).
     *
     * @return array{base_url: ?string, token_id: ?string, token_secret: ?string, verify_tls: bool}
     */
    public function pbs(): array
    {
        return [
            'base_url' => $this->get('pbs.base_url') ?? config('watchtower.pbs.base_url'),
            'token_id' => $this->get('pbs.token_id') ?? config('watchtower.pbs.token_id'),
            'token_secret' => $this->get('pbs.token_secret') ?? config('watchtower.pbs.token_secret'),
            'verify_tls' => $this->resolveBool('pbs.verify_tls', 'watchtower.pbs.verify_tls'),
        ];
    }

    /**
     * Resolve ntfy connection config (DB wins, else config/watchtower.php → .env).
     *
     * @return array{base_url: ?string, topic: ?string, token: ?string}
     */
    public function ntfy(): array
    {
        return [
            'base_url' => $this->get('ntfy.base_url') ?? config('watchtower.ntfy.base_url'),
            'topic' => $this->get('ntfy.topic') ?? config('watchtower.ntfy.topic'),
            'token' => $this->get('ntfy.token') ?? config('watchtower.ntfy.token'),
        ];
    }

    /**
     * Resolve a boolean setting: a DB value is the string 'true'/'false'; otherwise
     * fall back to the (already-typed) config value.
     */
    private function resolveBool(string $key, string $configKey): bool
    {
        $value = $this->get($key);

        if ($value !== null) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return (bool) config($configKey, false);
    }
}
