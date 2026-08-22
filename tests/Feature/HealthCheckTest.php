<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Point the redis connection at a closed port, so any probe that does run
     * fails rather than quietly succeeding against a Redis that happens to be
     * running on the machine executing the suite.
     */
    private function breakRedis(): void
    {
        config(['database.redis.default.port' => 1]);
        Redis::purge('default');
    }

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

    public function test_up_ignores_redis_when_nothing_is_configured_to_use_it(): void
    {
        // What a shared-hosting deployment runs: cache, sessions and the queue
        // all in MySQL, no Redis anywhere. Probing one regardless reported the
        // host down while it was serving every request perfectly well.
        config([
            'app.debug' => false,
            'cache.default' => 'database',
            'queue.default' => 'database',
            'session.driver' => 'database',
        ]);
        $this->breakRedis();

        $this->getJson('/up')
            ->assertOk()
            ->assertJson(['status' => 'up']);
    }

    public function test_up_reports_down_when_a_configured_redis_is_unreachable(): void
    {
        config([
            'app.debug' => false,
            'cache.default' => 'redis',
            'queue.default' => 'database',
            'session.driver' => 'database',
        ]);
        $this->breakRedis();

        $this->getJson('/up')
            ->assertStatus(500)
            ->assertJson(['status' => 'down']);
    }

    public function test_the_session_driver_alone_is_enough_to_make_redis_a_dependency(): void
    {
        // Each of the three is checked independently: a deployment can keep
        // sessions in Redis while the cache and queue sit in MySQL, and that
        // still cannot serve a request without it.
        config([
            'app.debug' => false,
            'cache.default' => 'database',
            'queue.default' => 'database',
            'session.driver' => 'redis',
        ]);
        $this->breakRedis();

        $this->getJson('/up')
            ->assertStatus(500)
            ->assertJson(['status' => 'down']);
    }
}
