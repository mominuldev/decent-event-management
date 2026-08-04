<?php

namespace App\Console\Commands;

use App\Domain\Payment\Actions\ReconcilePayments;
use Illuminate\Console\Command;

/**
 * Scheduled nightly (docs/06 §6.6 "Reconciliation as a security control").
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'payments:reconcile';

    protected $description = 'Nightly settlement diff against each gateway for succeeded payments not yet reconciled.';

    public function handle(ReconcilePayments $action): int
    {
        $result = $action->handle();

        $this->info(sprintf(
            'Matched %d, amount_mismatch %d, missing_at_gateway %d, skipped %d.',
            $result['matched'],
            $result['amount_mismatch'],
            $result['missing_at_gateway'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
