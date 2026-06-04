<?php

namespace App\Enums;

enum TargetType: string
{
    case Node = 'node';
    case Lxc = 'lxc';
    case Vm = 'vm';
    case Storage = 'storage';
    case Service = 'service';
    case App = 'app';
}
