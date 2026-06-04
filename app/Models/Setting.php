<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single DB-backed configuration entry.
 *
 * The `value` column holds ciphertext (encrypted via Crypt::encryptString by the
 * Settings service) so secrets such as API token secrets are encrypted at rest.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 */
class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];
}
