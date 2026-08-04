<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebhookIpAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_allowlist_is_a_no_op(): void
    {
        config(['services.sslcommerz.ipn_ip_allowlist' => []]);

        $response = $this->postJson(route('webhooks.sslcommerz'), [
            'tran_id' => 'NONEXISTENT',
            'status' => 'FAILED',
            'verify_key' => 'tran_id,status',
            'verify_sign' => 'irrelevant',
        ]);

        $response->assertStatus(200);
    }

    public function test_a_configured_allowlist_rejects_an_unlisted_source_ip(): void
    {
        config(['services.sslcommerz.ipn_ip_allowlist' => ['203.0.113.10']]);

        $response = $this->postJson(route('webhooks.sslcommerz'), [
            'tran_id' => 'NONEXISTENT',
            'status' => 'FAILED',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'ipn_source_not_allowlisted');
    }

    public function test_a_configured_allowlist_admits_a_listed_source_ip(): void
    {
        config(['services.sslcommerz.ipn_ip_allowlist' => ['127.0.0.1']]);

        $response = $this->postJson(route('webhooks.sslcommerz'), [
            'tran_id' => 'NONEXISTENT',
            'status' => 'FAILED',
            'verify_key' => 'tran_id,status',
            'verify_sign' => 'irrelevant',
        ]);

        $response->assertStatus(200);
    }
}
