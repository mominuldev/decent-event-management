<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Phase 2 exit criteria (docs/07 §7.8 "capacity race"): 300 concurrent
 * purchases against a 100-capacity tier sell exactly 100.
 *
 * Phase 8 finding: the test that previously carried this name only ever
 * called tryReserve()/confirmSale() sequentially in-process — it never
 * exercised a second real database connection, so it could not have
 * caught a genuine race condition (docs/08 R12: "a phase is marked done
 * on the strength of a test that exercises the wrong code path"). This
 * version spawns real OS subprocesses (tests/Support/concurrency_worker.php),
 * each with its own MySQL connection, all racing the same row. It uses
 * DatabaseMigrations rather than RefreshDatabase deliberately —
 * RefreshDatabase wraps the test in an uncommitted transaction, which a
 * separate connection can never see, so every child process would find
 * the ticket type missing.
 */
class PurchaseConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_300_concurrent_purchases_against_100_capacity_sell_exactly_100(): void
    {
        $ticketType = TicketType::factory()->create([
            'name' => 'Limited Tier - Concurrency Test',
            'quantity_total' => 100,
            'quantity_reserved' => 0,
            'quantity_sold' => 0,
            'base_price_paisa' => 10000,
            'sale_starts_at' => now()->subHour(),
            'sale_ends_at' => now()->addHour(),
            'is_active' => true,
        ]);

        $results = $this->runConcurrently(300, ['reserve-and-confirm', (string) $ticketType->id]);

        $succeeded = count(array_filter($results, fn (int $code) => $code === 0));
        $rejected = count(array_filter($results, fn (int $code) => $code === 1));
        $errored = count(array_filter($results, fn (int $code) => $code === 2));

        $this->assertSame(0, $errored, 'No worker should error — every outcome must be a clean success or a clean rejection.');
        $this->assertSame(100, $succeeded, 'Exactly 100 of the 300 concurrent purchases should succeed.');
        $this->assertSame(200, $rejected, 'The remaining 200 concurrent purchases should be cleanly rejected, not silently lost.');

        $ticketType->refresh();
        $this->assertSame(100, $ticketType->quantity_sold, 'quantity_sold must land at exactly capacity, never over-sold.');
        $this->assertSame(0, $ticketType->quantity_reserved, 'No reservation should be left dangling once every worker has resolved.');
    }

    /**
     * Spawns workers in batches of at most `maxBatch` rather than all at
     * once. MySQL's out-of-the-box `max_connections` is 151 — 300
     * simultaneous worker processes (each its own connection, on top of
     * the test's own) reliably exhausts it, and the excess workers fail
     * with a connection error rather than a clean reject (found running
     * this against a default-configured local MySQL: exactly 149 of 300
     * errored, i.e. 300 minus headroom below 151). Each batch still races
     * 60 real concurrent connections against the same row, which is
     * plenty to exercise the atomic reservation path — this caps
     * simultaneous connections without weakening the race being tested.
     *
     * @return array<int, int> exit codes, one per worker
     */
    private function runConcurrently(int $workerCount, array $args, int $maxBatch = 60): array
    {
        $env = array_merge($_SERVER, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3306',
            'DB_DATABASE' => 'decent_event_testing',
            'DB_USERNAME' => 'root',
            'DB_PASSWORD' => '',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
        ]);

        $script = base_path('tests/Support/concurrency_worker.php');
        $exitCodes = [];

        foreach (array_chunk(range(1, $workerCount), $maxBatch) as $batch) {
            $pending = [];
            foreach ($batch as $ignored) {
                $pending[] = Process::env($env)->start(array_merge(['php', $script], $args));
            }

            foreach ($pending as $process) {
                $exitCodes[] = $process->wait()->exitCode();
            }
        }

        return $exitCodes;
    }
}
