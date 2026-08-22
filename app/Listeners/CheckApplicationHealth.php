<?php

namespace App\Listeners;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Turns Laravel's built-in `/up` route (`bootstrap/app.php`'s `health: '/up'`)
 * from "the app booted" into "the app can actually serve traffic" — docs/07
 * §7.3 puts this route behind the load balancer's health check and Phase 9's
 * uptime/synthetic monitoring, both of which need it to reflect dependency
 * health, not just that PHP is running.
 *
 * Framework wiring (`ApplicationBuilder::buildRoutingCallback()`): `/up`
 * dispatches `DiagnosingHealth` inside a try/catch and turns any exception
 * into a 500 with `{"status": "down"}` for JSON callers — this listener only
 * needs to throw, never to construct a response itself.
 *
 * Checks every dependency the app cannot serve a single request without:
 * the primary database, the default filesystem disk (private media,
 * generated tickets/QR images), and Redis — but Redis only when this
 * deployment actually resolves something to it. Every check runs even if
 * an earlier one fails, so a single `/up` hit reports every broken
 * dependency at once instead of one per retry.
 *
 * The Redis check is conditional because a deployment that stores its
 * cache, sessions and queue in MySQL never opens a Redis connection, and
 * probing one anyway reports `down` on a host that is serving every
 * request perfectly well. A health check that is permanently red is worse
 * than no health check: it trains whoever is on call to ignore it, and it
 * will be ignored on the morning it finally means something. `driversInUse()`
 * reads the same config the framework resolves at runtime, so the probe
 * follows the deployment rather than an assumption about it.
 */
class CheckApplicationHealth
{
    public function handle(DiagnosingHealth $event): void
    {
        $failures = array_filter([
            'database' => $this->check($this->checkDatabase(...)),
            'redis' => $this->usesRedis() ? $this->check($this->checkRedis(...)) : null,
            'storage' => $this->check($this->checkStorage(...)),
        ]);

        if ($failures !== []) {
            throw new RuntimeException('Health check failed: '.implode('; ', $failures));
        }
    }

    /**
     * Whether anything this deployment serves a request through resolves to
     * Redis — the active cache store, the default queue connection, or the
     * session driver.
     *
     * Horizon is deliberately not consulted. `config/horizon.php` names the
     * redis connection on all four supervisors unconditionally, so reading it
     * would make the check unskippable on every deployment, which is the
     * behaviour this replaces. Horizon only has work to do when the queue
     * connection is redis, and that is already covered here.
     */
    private function usesRedis(): bool
    {
        $drivers = [
            $this->configString('cache.stores.'.$this->configString('cache.default').'.driver'),
            $this->configString('queue.connections.'.$this->configString('queue.default').'.driver'),
            $this->configString('session.driver'),
        ];

        return in_array('redis', $drivers, true);
    }

    private function configString(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : '';
    }

    private function check(callable $probe): ?string
    {
        try {
            $probe();

            return null;
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private function checkDatabase(): void
    {
        DB::connection()->select('select 1');
    }

    private function checkRedis(): void
    {
        // predis returns a Predis\Response\Status object whose __toString()
        // is "PONG", not a plain string or boolean — compare the string cast.
        $pong = (string) Redis::connection()->ping();

        if ($pong !== 'PONG') {
            throw new RuntimeException("unexpected PING response [{$pong}]");
        }
    }

    private function checkStorage(): void
    {
        $disk = Storage::disk(config('filesystems.default'));
        $probe = '.health-check-'.Str::random(16);

        $disk->put($probe, 'ok');

        try {
            if ($disk->get($probe) !== 'ok') {
                throw new RuntimeException('read back a different value than was written');
            }
        } finally {
            $disk->delete($probe);
        }
    }
}
