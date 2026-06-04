<?php

namespace App\Enums;

enum AlertState: string
{
    case Pending = 'pending';
    case Firing = 'firing';
    case Resolved = 'resolved';
}
