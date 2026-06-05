<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('collect:run')->everyMinute()->withoutOverlapping(5);

Schedule::command('alerts:evaluate')->everyThirtySeconds()->withoutOverlapping(2);

Schedule::command('app-metrics:prune')->daily()->withoutOverlapping(10);
