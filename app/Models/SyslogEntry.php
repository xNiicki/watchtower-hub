<?php

namespace App\Models;

use Database\Factories\SyslogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only syslog record. Written by the recorder via batch insert / direct
 * assignment, never through Filament forms — so it follows the Metric/Check
 * convention of an unguarded, timestamp-less model.
 *
 * @property int $id
 * @property string $host
 * @property string|null $facility
 * @property string $severity
 * @property string $message
 * @property string|null $raw
 * @property Carbon $logged_at
 * @property Carbon $received_at
 */
class SyslogEntry extends Model
{
    /** @use HasFactory<SyslogEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'logged_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }
}
