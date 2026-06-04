<?php

namespace App\Models;

use Database\Factories\MetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $target_id
 * @property string $key
 * @property float $value
 * @property Carbon $captured_at
 */
class Metric extends Model
{
    /** @use HasFactory<MetricFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(Target::class);
    }
}
