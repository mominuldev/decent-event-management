<?php

namespace Tests\Feature\Concurrency;

use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Phase 2 exit criteria (docs/07 §7.8 "duplicate scan race"): 20 concurrent
 * scans of one ticket admit exactly once.
 *
 * Phase 8 finding: the test that previously carried this name never
 * actually ran anything concurrently — it called Ticket::tryAdmit() twice,
 * sequentially, in the same process, and a `writeConcurrencyScript()`
 * helper that would have proven the real thing was written but never
 * invoked (docs/08 R12 territory — see PurchaseConcurrencyTest for the
 * same finding on the capacity race). This version spawns real OS
 * subprocesses via tests/Support/concurrency_worker.php, each with its own
 * MySQL connection, all racing Ticket::tryAdmit() — the same atomic
 * conditional UPDATE ProcessCheckIn uses on the real admission path.
 * DatabaseMigrations (not RefreshDatabase) is deliberate: RefreshDatabase's
 * uncommitted per-test transaction would be invisible to every child
 * connection.
 */
class CheckInConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_20_concurrent_scans_admit_exactly_once(): void
    {
        $ticketType = TicketType::factory()->create([
            'name' => 'Standard Entry',
            'quantity_total' => 1000,
            'base_admits' => 1,
            'max_admits' => 1,
            'base_price_paisa' => 5000,
        ]);

        $registration = Registration::factory()->create([
            'ticket_type_id' => $ticketType->id,
            'status' => 'confirmed',
        ]);

        $ticket = Ticket::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $registration->attendee_id,
            'ticket_type_id' => $ticketType->id,
            'ticket_number' => 'CONCUR-TEST-'.bin2hex(random_bytes(8)),
            'status' => 'issued',
            'admits_total' => 1,
            'admitted_count' => 0,
        ]);

        $results = $this->runConcurrently(20, ['admit', (string) $ticket->id]);

        $succeeded = count(array_filter($results, fn (int $code) => $code === 0));
        $rejected = count(array_filter($results, fn (int $code) => $code === 1));
        $errored = count(array_filter($results, fn (int $code) => $code === 2));

        $this->assertSame(0, $errored, 'No worker should error — every outcome must be a clean admission or a clean rejection.');
        $this->assertSame(1, $succeeded, 'Exactly one of the 20 concurrent scans should admit.');
        $this->assertSame(19, $rejected, 'The remaining 19 concurrent scans should be cleanly rejected, not silently lost or double-counted.');

        $ticket->refresh();
        $this->assertSame(1, $ticket->admitted_count, 'admitted_count must land at exactly 1, never over-admitted.');
        $this->assertSame('fully_admitted', $ticket->status);
    }

    /**
     * @return array<int, int> exit codes, one per worker
     */
    private function runConcurrently(int $workerCount, array $args): array
    {
        // Read the connection back off the parent rather than naming a
        // database literally: under `--parallel` each process is given its
        // own (`..._test_1`, `_test_2`), so a hardcoded name would point every
        // worker at a database the test is not using. The race would then run
        // against rows nobody wrote and pass while proving nothing -- the
        // docs/08 R12 failure mode these two tests exist to close.
        $db = DB::connection()->getConfig();

        $env = array_merge($_SERVER, [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'] ?? '127.0.0.1',
            'DB_PORT' => (string) ($db['port'] ?? 3306),
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'] ?? 'root',
            'DB_PASSWORD' => $db['password'] ?? '',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
        ]);

        $script = base_path('tests/Support/concurrency_worker.php');

        $pending = [];
        for ($i = 0; $i < $workerCount; $i++) {
            $pending[] = Process::env($env)->start(array_merge(['php', $script], $args));
        }

        return array_map(fn ($process) => $process->wait()->exitCode(), $pending);
    }
}
