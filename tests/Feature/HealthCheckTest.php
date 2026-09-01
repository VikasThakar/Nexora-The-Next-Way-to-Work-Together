<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_health_endpoint_reports_ok_when_dependencies_are_reachable(): void
    {
        $this->getJson('/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('checks.database', true)
            ->assertJsonPath('checks.cache', true);
    }

    public function test_the_health_endpoint_is_reachable_without_authentication(): void
    {
        $this->assertGuest();

        $this->get('/health')->assertOk();
    }

    public function test_the_framework_probe_is_available(): void
    {
        $this->get('/up')->assertOk();
    }
}
