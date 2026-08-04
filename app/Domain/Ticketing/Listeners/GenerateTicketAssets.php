<?php

namespace App\Domain\Ticketing\Listeners;

use App\Domain\Ticketing\Events\TicketIssued;
use App\Jobs\GenerateTicketAssetsJob;

class GenerateTicketAssets
{
    public function handle(TicketIssued $event): void
    {
        GenerateTicketAssetsJob::dispatch($event->ticket->id)->afterCommit();
    }
}
