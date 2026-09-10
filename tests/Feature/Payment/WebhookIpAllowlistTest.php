<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Source-IP allowlisting for gateway IPNs. Exercised against the
 * PayStation route because that is the one where it carries real weight:
 * PayStation's IPN has no signature, so this middleware is the only
 * network-layer check available there.
 */
class WebhookIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function ipn(): array
    {
        return [
            'invoice_number' => 'PAY-NONEXISTENT',
            'trx_status' => 'Success',
            'trx_id' => 'CG20D8AYB4',
            'trx_amount' => 2500,
        ];
    }

    public function test_an_empty_allowlist_is_a_no_op(): void
    {
        config(['services.paystation.ipn_ip_allowlist' => []]);

        $this->postJson(route('webhooks.paystation'), $this->ipn())->assertStatus(200);
    }

    public function test_a_configured_allowlist_rejects_an_unlisted_source_ip(): void
    {
        config(['services.paystation.ipn_ip_allowlist' => ['203.0.113.10']]);

        $this->postJson(route('webhooks.paystation'), $this->ipn())
            ->assertStatus(403)
            ->assertJsonPath('code', 'ipn_source_not_allowlisted');
    }

    public function test_a_configured_allowlist_admits_a_listed_source_ip(): void
    {
        config(['services.paystation.ipn_ip_allowlist' => ['127.0.0.1']]);

        $this->postJson(route('webhooks.paystation'), $this->ipn())->assertStatus(200);
    }
}
