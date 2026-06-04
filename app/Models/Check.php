<?php

namespace App\Models;

use App\Enums\TargetStatus;
use Database\Factories\CheckFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $target_id
 * @property TargetStatus $status
 * @property int|null $latency_ms
 * @property int $fail_streak
 * @property Carbon|null $last_ok_at
 * @property Carbon|null $last_checked_at
 */
class Check extends Model
{
    /** @use HasFactory<CheckFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TargetStatus::class,
            'last_ok_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
