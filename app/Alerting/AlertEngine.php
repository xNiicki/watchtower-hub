<?php

namespace App\Alerting;

use App\Alerting\Conditions\ConditionResolver;
use App\Enums\AlertState;
use App\Enums\TargetStatus;
use App\Events\AlertFired;
use App\Events\AlertResolved;
use App\Models\Alert;
use App\Models\Rule;
use Carbon\CarbonImmutable;

class AlertEngine
{
    public function __construct(
        private readonly ConditionResolver $conditionResolver,
    ) {}

    /**
     * Evaluate all enabled rules and advance the alert state machine.
     *
     * State transitions:
     *   - New breach, no open alert       → create Pending (pending_since = $now)
     *   - Still breaching, Pending, duration elapsed → Firing; dispatch AlertFired
     *   - Still breaching, Pending, duration 0      → immediate Firing in same pass
     *   - No longer breaching, open alert           → Resolved;
     *       if had fired: dispatch AlertResolved
     *       if never fired (debounce flap): silent — this is intentional
     *
     * Acknowledged alerts still transition (ack is orthogonal; M7 suppresses re-notification).
     */
    public function evaluate(CarbonImmutable $now): EvaluationSummary
    {
        $pendingCreated = 0;
        $fired = 0;
        $resolved = 0;

        $rules = Rule::where('enabled', true)->get();

        /** @var array<string> $processedRuleKeys */
        $processedRuleKeys = [];

        foreach ($rules as $rule) {
            $processedRuleKeys[] = $rule->key;

            $condition = $this->conditionResolver->resolve($rule->condition_type);

            if ($condition === null) {
                // Unknown condition_type — warning already logged by resolver.
                continue;
            }

            // Fetch all currently-breaching targets, keyed by target_id.
            $breaches = $condition->breachingTargets($rule)
                ->keyBy(fn (Breach $b) => $b->target->id);

            // Fetch all open (pending or firing) alerts for this rule.
            $openAlerts = Alert::where('rule_key', $rule->key)
                ->whereIn('state', [AlertState::Pending->value, AlertState::Firing->value])
                ->get()
                ->keyBy('target_id');

            // --- Handle breaching targets ---
            foreach ($breaches as $targetId => $breach) {
                $openAlert = $openAlerts->get($targetId);

                if ($openAlert === null) {
                    // No open alert: create a new Pending alert.
                    $alert = new Alert;
                    $alert->target_id = $breach->target->id;
                    $alert->rule_key = $rule->key;
                    $alert->state = AlertState::Pending;
                    $alert->tier = $rule->tier;
                    $alert->title = "{$rule->key}: {$breach->target->name}";
                    $alert->message = $breach->description;
                    $alert->pending_since = $now;
                    $alert->fired_at = null;
                    $alert->resolved_at = null;
                    $alert->acknowledged_at = null;
                    $alert->save();

                    $pendingCreated++;

                    // duration_seconds = 0 means fire immediately on first evaluate.
                    // Reload the newly-created alert so we can transition it below.
                    if ($rule->duration_seconds === 0) {
                        $openAlert = Alert::where('rule_key', $rule->key)
                            ->where('target_id', $breach->target->id)
                            ->where('state', AlertState::Pending->value)
                            ->first();
                    }
                }

                if ($openAlert !== null && $openAlert->state === AlertState::Pending) {
                    // Check if duration has elapsed (duration_seconds = 0 fires immediately).
                    $fireAt = $openAlert->pending_since->addSeconds($rule->duration_seconds);

                    // $now behind pending_since (NTP step) leaves the alert pending — gte() never true, no negative math.
                    if ($now->gte($fireAt)) {
                        $openAlert->state = AlertState::Firing;
                        $openAlert->fired_at = $now;
                        $openAlert->save();

                        AlertFired::dispatch($openAlert);
                        $fired++;
                    }
                }
                // Firing alerts that are still breaching: no action needed.
            }

            // --- Handle recovering targets ---
            foreach ($openAlerts as $targetId => $openAlert) {
                if ($breaches->has($targetId)) {
                    // Still breaching — handled above.
                    continue;
                }

                // Target is no longer breaching: resolve the alert, subject to the vanish policy below.

                // VANISH POLICY (product decision): an open alert whose target's current check status
                // is Unknown is SUSTAINED, not resolved — losing sight of a box is not recovery;
                // only an explicit non-breach observation (e.g. Up) resolves.
                // Targets gone from Proxmox are marked Unknown by reconciliation; their down-alerts
                // stay open until the operator deletes/disables the target, which then resolves via
                // target-null or orphaned-alert sweep. That is intentional.
                $target = $openAlert->target()->first();

                if ($target !== null) {
                    $check = $target->check()->first();

                    if ($check !== null && $check->status === TargetStatus::Unknown) {
                        // Target has vanished (Unknown) — sustain the alert this tick.
                        continue;
                    }
                }
                // Null target (deleted) → fall through; sweep semantics resolve it below.

                $hasFired = $openAlert->fired_at !== null;

                $openAlert->state = AlertState::Resolved;
                $openAlert->resolved_at = $now;
                $openAlert->save();

                if ($hasFired) {
                    // Only dispatch if it had fired — silent resolution for debounce flaps.
                    AlertResolved::dispatch($openAlert);
                }

                $resolved++;
            }
        }

        // Rules can be disabled or deleted while their alerts are open; sweep so nothing pages forever or sits silent-stuck.
        $orphaned = Alert::whereIn('state', [AlertState::Pending->value, AlertState::Firing->value])
            ->whereNotIn('rule_key', $processedRuleKeys)
            ->get();

        foreach ($orphaned as $orphanedAlert) {
            $hasFired = $orphanedAlert->fired_at !== null;

            $orphanedAlert->state = AlertState::Resolved;
            $orphanedAlert->resolved_at = $now;
            $orphanedAlert->save();

            if ($hasFired) {
                AlertResolved::dispatch($orphanedAlert);
            }

            $resolved++;
        }

        return new EvaluationSummary(
            pendingCreated: $pendingCreated,
            fired: $fired,
            resolved: $resolved,
        );
    }
}
