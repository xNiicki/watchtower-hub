<?php

namespace Tests\Feature;

use Tests\TestCase;

class AppEventsConfigTest extends TestCase
{
    public function test_event_policy_defaults(): void
    {
        $this->assertSame('critical', config('watchtower.apps.events.severity.exception'));
        $this->assertSame('critical', config('watchtower.apps.events.severity.failed_job'));
        $this->assertSame('warning', config('watchtower.apps.events.severity.failed_scheduled_task'));
        $this->assertSame(60, config('watchtower.apps.events.quiet_after'));
        $this->assertSame(60, config('watchtower.apps.events.renotify_after'));
        $this->assertSame(120, config('watchtower.apps.events.retention_days'));
    }
}
