<?php

namespace App\Console\Commands;

use App\Domain\Payment\Actions\ExpirePaymentIntents;
use Illuminate\Console\Command;

/**
 * Scheduled every 5 minutes (docs/05 §"Payment intent expiry"). Closes D5.
 */
class ExpirePaymentIntentsCommand extends Command
{
    protected $signature = 'payments:expire-intents';

    protected $description = 'Release capacity for abandoned payment intents, after a fresh gateway pre-check.';

    public function handle(ExpirePaymentIntents $action): int
    {
        $result = $action->handle();

        $this->info(sprintf(
            'Expired %d, recovered %d, skipped %d.',
            $result['expired'],
            $result['recovered'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
