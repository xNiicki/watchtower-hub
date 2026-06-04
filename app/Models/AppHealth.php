<?php

namespace App\Models;

use Database\Factories\AppHealthFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Latest health snapshot for a MonitoredApp (1:1, updateOrCreate on ingest).
 *
 * @property int $app_id
 * @property bool $healthy
 * @property int $errors_last_hour
 * @property int $queue_depth
 * @property int $failed_jobs_24h
 * @property int $mail_sent_24h
 * @property Carbon|null $last_deploy_at
 * @property Carbon $snapshot_at
 * @property Carbon $received_at
 */
class AppHealth extends Model
{
    /** @use HasFactory<AppHealthFactory> */
    use HasFactory;

    protected $table = 'app_health';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'healthy' => 'boolean',
            'last_deploy_at' => 'datetime',
            'snapshot_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MonitoredApp::class, 'app_id');
    }
}
