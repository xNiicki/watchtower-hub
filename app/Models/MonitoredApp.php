<?php

namespace App\Models;

use Database\Factories\MonitoredAppFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Laravel\Sanctum\HasApiTokens;

/**
 * A monitored host application (e.g. booking). Authenticates its telemetry
 * pushes with a per-app, ingest-scoped Sanctum token (tokenable = this model).
 *
 * Named MonitoredApp (not App) to avoid shadowing the Laravel `App` facade alias.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property AppHealth|null $health
 */
class MonitoredApp extends Model
{
    /** @use HasFactory<MonitoredAppFactory> */
    use HasApiTokens, HasFactory;

    protected $table = 'apps';

    protected $fillable = ['name', 'slug'];

    public function health(): HasOne
    {
        return $this->hasOne(AppHealth::class, 'app_id');
    }
}
