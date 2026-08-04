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
 * the primary database, Redis (both the cache store and the Horizon queue
 * connection resolve here per `config/cache.php`/`config/queue.php`), and
 * the default filesystem disk (private media, generated tickets/QR images).
 * All three run even if the first one fails, so a single `/up` hit reports
 * every broken dependency at once instead of one per retry.
 */
class CheckApplicationHealth
{
    public function handle(DiagnosingHealth $event): void
    {
        $failures = array_filter([
            'database' => $this->check($this->checkDatabase(...)),
            'redis' => $this->check($this->checkRedis(...)),
            'storage' => $this->check($this->checkStorage(...)),
        ]);

        if ($failures !== []) {
            throw new RuntimeException('Health check failed: '.implode('; ', $failures));
        }
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
