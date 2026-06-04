<?php

namespace App\Models;

use App\Enums\TargetType;
use Database\Factories\TargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property TargetType $type
 * @property string $name
 * @property string|null $external_id
 * @property string|null $node
 * @property array<string, mixed>|null $check_config
 * @property bool $enabled
 */
class Target extends Model
{
    /** @use HasFactory<TargetFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => TargetType::class,
            'check_config' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function check(): HasOne
    {
        return $this->hasOne(Check::class);
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(Metric::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }
}
