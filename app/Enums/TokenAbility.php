<?php

namespace App\Enums;

enum TokenAbility: string
{
    case Read = 'read';
    case AckAlerts = 'alerts:ack';

    /**
     * Return all case values as a plain array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
