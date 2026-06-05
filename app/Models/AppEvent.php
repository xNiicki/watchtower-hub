<?php

namespace App\Models;

use Database\Factories\AppEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A grouped application error incident: one row per (app_id, fingerprint).
 * Written only by AppEventRecorder via the ingest endpoint — never a Filament
 * form — so it follows the unguarded, timestamp-less convention (like SyslogEntry).
 *
 * @property int $app_id
 * @property string $fingerprint
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string $message
 * @property string|null $exception_class
 * @property string|null $file
 * @property int|null $line
 * @property string|null $trace
 * @property array<string, mixed>|null $context
 * @property int $occurrences
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon $received_at
 */
class AppEvent extends Model
{
    /** @use HasFactory<AppEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'line' => 'integer',
            'occurrences' => 'integer',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    public function app(): BelongsTo
    {
        return $this->belongsTo(MonitoredApp::class, 'app_id');
    }
}
