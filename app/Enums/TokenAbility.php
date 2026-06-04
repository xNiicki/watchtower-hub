<?php

namespace App\Enums;

enum TokenAbility: string
{
    case Read = 'read';
    case AckAlerts = 'alerts:ack';
    case Ingest = 'ingest';

    /**
     * Return all case values as a plain array.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Abilities granted to mobile (phone) tokens. Deliberately excludes Ingest:
     * the phone must never be able to push telemetry.
     *
     * @return list<string>
     */
    public static function mobile(): array
    {
        return [self::Read->value, self::AckAlerts->value];
    }
}
