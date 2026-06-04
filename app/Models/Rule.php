<?php

namespace App\Models;

use App\Enums\AlertTier;
use Database\Factories\RuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property string $condition_type
 * @property array<string, mixed> $params
 * @property int $duration_seconds
 * @property AlertTier $tier
 * @property bool $enabled
 */
#[Fillable(['key', 'condition_type', 'params', 'duration_seconds', 'tier', 'enabled'])]
class Rule extends Model
{
    /** @use HasFactory<RuleFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'tier' => AlertTier::class,
            'params' => 'array',
            'enabled' => 'boolean',
        ];
    }
}
