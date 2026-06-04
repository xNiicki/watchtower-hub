<?php

namespace App\Console\Commands;

use App\Alerting\AlertEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alerts:evaluate')]
#[Description('Evaluate all alert rules and advance the alert state machine')]
class AlertsEvaluate extends Command
{
    public function handle(AlertEngine $engine): int
    {
        $summary = $engine->evaluate(CarbonImmutable::now());

        $this->line(
            "alerts:evaluate: {$summary->pendingCreated} pending created, "
            ."{$summary->fired} fired, "
            ."{$summary->resolved} resolved"
        );

        return self::SUCCESS;
    }
}
