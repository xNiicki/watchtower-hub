<?php

namespace Tests;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow(null);
        CarbonImmutable::setTestNow(null);

        parent::tearDown();
    }
}
