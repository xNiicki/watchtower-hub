<?php

namespace App\Collectors;

use App\Enums\TargetType;

interface Collector
{
    /** Human/log identifier, e.g. 'proxmox'. */
    public function key(): string;

    /** Whether this collector is configured and should run. */
    public function enabled(): bool;

    /**
     * Gather results for the targets this collector owns.
     *
     * @return list<CheckResult>
     */
    public function collect(): array;

    /**
     * The target types this collector is responsible for.
     *
     * @return list<TargetType>
     */
    public function scope(): array;
}
