<?php

namespace Tests\Feature\Payment;

use App\Domain\Payment\Actions\ReconcilePayments;
use App\Domain\Payment\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ReconcilePaymentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_settled_payment_is_classified_matched(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'succeeded',
            'method' => 'bkash',
            'gateway_reference' => 'FAKE-RECON-MATCH',
            'amount_due_paisa' => 50000,
            'reconciled_at' => null,
        ]);

        Cache::put('fake_gateway:session:FAKE-RECON-MATCH', [
            'status' => 'succeeded',
            'amount_paisa' => 50000,
            'gateway_transaction_id' => 'FAKETXN-MATCH',
        ]);

        $result = app(ReconcilePayments::class)->handle();

        $this->assertEquals(1, $result['matched']);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'reconciliation_status' => 'matched',
        ]);
    }

    public function test_an_amount_mismatch_is_flagged(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'succeeded',
            'method' => 'nagad',
            'gateway_reference' => 'FAKE-RECON-MISMATCH',
            'amount_due_paisa' => 50000,
            'reconciled_at' => null,
        ]);

        Cache::put('fake_gateway:session:FAKE-RECON-MISMATCH', [
            'status' => 'succeeded',
            'amount_paisa' => 45000,
            'gateway_transaction_id' => 'FAKETXN-MISMATCH',
        ]);

        $result = app(ReconcilePayments::class)->handle();

        $this->assertEquals(1, $result['amount_mismatch']);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'reconciliation_status' => 'amount_mismatch',
        ]);
    }

    public function test_a_payment_the_gateway_has_no_record_of_is_flagged_missing_at_gateway(): void
    {
        $payment = Payment::factory()->create([
            'status' => 'succeeded',
            'method' => 'rocket',
            'gateway_reference' => 'FAKE-RECON-ORPHAN',
            'amount_due_paisa' => 50000,
            'reconciled_at' => null,
        ]);

        $result = app(ReconcilePayments::class)->handle();

        $this->assertEquals(1, $result['missing_at_gateway']);
        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'reconciliation_status' => 'missing_at_gateway',
        ]);
    }

    public function test_manual_channel_payments_are_excluded(): void
    {
        Payment::factory()->create([
            'status' => 'succeeded',
            'channel' => 'manual',
            'reconciled_at' => null,
        ]);

        $result = app(ReconcilePayments::class)->handle();

        $this->assertEquals(0, array_sum($result));
    }

    public function test_already_reconciled_payments_are_not_re_checked(): void
    {
        Payment::factory()->create([
            'status' => 'succeeded',
            'reconciled_at' => now()->subDay(),
            'reconciliation_status' => 'matched',
        ]);

        $result = app(ReconcilePayments::class)->handle();

        $this->assertEquals(0, array_sum($result));
    }
}
