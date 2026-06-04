<?php

namespace App\Events;

use App\Models\Alert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertResolved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Alert $alert,
    ) {}
}
