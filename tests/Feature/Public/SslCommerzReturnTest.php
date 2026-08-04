<?php

namespace Tests\Feature\Public;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SslCommerzReturnTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.frontend.url' => 'https://frontend.example.test']);
    }

    public function test_it_redirects_to_the_frontend_when_next_matches_the_configured_origin(): void
    {
        $next = 'https://frontend.example.test/registrations/abc123';

        $response = $this->get(route('api.v1.public.payments.sslcommerz.return', ['status' => 'success', 'next' => $next]));

        $response->assertRedirect("{$next}?payment_status=success");
    }

    public function test_it_ignores_a_next_url_pointing_at_an_unrelated_host(): void
    {
        $response = $this->get(route('api.v1.public.payments.sslcommerz.return', [
            'status' => 'fail',
            'next' => 'https://attacker.example/steal',
        ]));

        $response->assertRedirect('https://frontend.example.test?payment_status=fail');
    }

    public function test_it_falls_back_to_the_frontend_url_when_next_is_missing(): void
    {
        $response = $this->get(route('api.v1.public.payments.sslcommerz.return', ['status' => 'cancel']));

        $response->assertRedirect('https://frontend.example.test?payment_status=cancel');
    }

    public function test_it_never_touches_the_database(): void
    {
        // No payment/registration exists at all — a genuinely stray hit on
        // this endpoint must still just redirect, never error, since it
        // reads nothing.
        $response = $this->post(route('api.v1.public.payments.sslcommerz.return', ['status' => 'success']));

        $response->assertRedirect('https://frontend.example.test?payment_status=success');
    }

    public function test_an_unknown_status_segment_is_not_found(): void
    {
        $response = $this->get('/api/v1/public/payments/sslcommerz/return/bogus');

        $response->assertStatus(404);
    }
}
