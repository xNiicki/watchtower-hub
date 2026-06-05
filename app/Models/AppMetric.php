<?php

namespace App\Models;

use Database\Factories\AppMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-minute app metric time-series point. Written only by the metrics ingest
 * endpoint (upsert), never a form.
 *
 * @property int $app_id
 * @property string $key
 * @property float $value
 * @property Carbon $bucket_at
 */
class AppMetric extends Model
{
    /** @use HasFactory<AppMetricFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'float', 'bucket_at' => 'immutable_datetime'];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MonitoredApp::class, 'app_id');
    }
}
