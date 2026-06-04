<?php

namespace App\Models;

use Database\Factories\MonitoredAppFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
#[Fillable(['name', 'slug'])]
class MonitoredApp extends Model
{
    /** @use HasFactory<MonitoredAppFactory> */
    use HasApiTokens, HasFactory;

    protected $table = 'apps';

    public function health(): HasOne
    {
        return $this->hasOne(AppHealth::class, 'app_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AppEvent::class, 'app_id');
    }
}
