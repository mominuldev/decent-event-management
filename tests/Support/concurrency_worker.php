<?php

use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Standalone worker process spawned by tests/Feature/Concurrency/*Test.php
 * to prove the atomic reservation/admission primitives are actually safe
 * under real concurrent database connections — not just sequential
 * in-process calls, which cannot exercise MySQL's row-locking behaviour
 * at all. Each invocation is a genuinely separate OS process with its own
 * database connection, calling the exact same production methods
 * (`TicketType::tryReserve()`/`confirmSale()`, `Ticket::tryAdmit()`) the
 * real request path uses.
 *
 * Usage: php tests/Support/concurrency_worker.php <action> <id>
 * Exit codes: 0 = the action succeeded; 1 = it was correctly rejected
 * (sold out / already admitted); 2 = an unexpected error occurred.
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $action, $id] = $argv + [null, null, null];

try {
    $ok = match ($action) {
        'reserve-and-confirm' => (function () use ($id): bool {
            $ticketType = TicketType::find((int) $id);

            if ($ticketType === null || ! $ticketType->tryReserve(1)) {
                return false;
            }

            return $ticketType->confirmSale(1);
        })(),
        'admit' => (function () use ($id): bool {
            $ticket = Ticket::find((int) $id);

            return $ticket !== null && $ticket->tryAdmit(1);
        })(),
        default => throw new InvalidArgumentException("Unknown action: {$action}"),
    };
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(2);
}

exit($ok ? 0 : 1);
