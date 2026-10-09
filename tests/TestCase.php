<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Feature requests must exercise authorization and business middleware,
        // but deployment maintenance mode must not turn every test response into 503.
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
    }
}
