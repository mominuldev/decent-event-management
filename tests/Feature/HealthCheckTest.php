<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_up_reports_healthy_when_every_dependency_is_reachable(): void
    {
        $this->getJson('/up')
            ->assertOk()
            ->assertJson(['status' => 'up']);
    }

    public function test_up_reports_down_when_the_database_is_unreachable(): void
    {
        config(['app.debug' => false]);
        config(['database.connections.mysql.port' => 1]);
        DB::purge('mysql');

        $this->getJson('/up')
            ->assertStatus(500)
            ->assertJson(['status' => 'down']);
    }
}
