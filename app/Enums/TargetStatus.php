<?php

namespace App\Enums;

enum TargetStatus: string
{
    case Up = 'up';
    case Down = 'down';
    case Unknown = 'unknown';
    case Paused = 'paused';
}
