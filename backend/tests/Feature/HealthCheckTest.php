<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_health_check_endpoint_reports_healthy_when_the_database_is_reachable(): void
    {
        $this->get('/up')->assertOk();
    }
}
