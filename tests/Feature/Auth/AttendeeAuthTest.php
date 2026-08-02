<?php

namespace Tests\Feature\Auth;

use App\Domain\Registration\Models\Attendee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendeeAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_request_link_issues_a_debug_token_in_local_env(): void
    {
        $attendee = Attendee::factory()->create(['mobile' => '+8801711111111']);

        $response = $this->postJson('/api/v1/attendee/auth/request-link', [
            'mobile' => '+8801711111111',
        ])->assertOk();

        $this->assertNotNull($response->json('debug_token'));
        $this->assertNotNull($attendee->fresh()->auth_token_hash);
    }

    public function test_request_link_for_unknown_number_gives_the_same_generic_response(): void
    {
        $response = $this->postJson('/api/v1/attendee/auth/request-link', [
            'mobile' => '+8801799999999',
        ])->assertOk();

        $this->assertNull($response->json('debug_token'));
    }

    public function test_verify_exchanges_a_valid_token_for_a_session(): void
    {
        $attendee = Attendee::factory()->create(['mobile' => '+8801711111111']);

        $token = $this->postJson('/api/v1/attendee/auth/request-link', [
            'mobile' => '+8801711111111',
        ])->json('debug_token');

        $response = $this->postJson('/api/v1/attendee/auth/verify', ['token' => $token])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'attendee']);

        // Single-use: the same link cannot be replayed.
        $this->postJson('/api/v1/attendee/auth/verify', ['token' => $token])
            ->assertStatus(401);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/attendee/me')
            ->assertOk();
    }

    public function test_verify_rejects_an_unknown_token(): void
    {
        $this->postJson('/api/v1/attendee/auth/verify', ['token' => 'not-a-real-token'])
            ->assertStatus(401);
    }
}
