<?php

namespace App\Models;

use App\Enums\AlertState;
use App\Enums\AlertTier;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $target_id
 * @property string $rule_key
 * @property AlertState $state
 * @property AlertTier $tier
 * @property string $title
 * @property string $message
 * @property Carbon|null $pending_since
 * @property Carbon|null $fired_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $acknowledged_at
 */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'state' => AlertState::class,
            'tier' => AlertTier::class,
            'pending_since' => 'datetime',
            'fired_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
