<?php

namespace App\Enums;

enum AlertTier: string
{
    case Critical = 'critical';
    case Warning = 'warning';
}
