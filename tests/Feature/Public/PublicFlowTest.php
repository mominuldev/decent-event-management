<?php

namespace Tests\Feature\Public;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_browse_event_settings(): void
    {
        EventSetting::factory()->create([
            'key' => 'event_name',
            'value' => 'Decent Reunion 2026',
            'is_public' => true,
        ]);

        EventSetting::factory()->create([
            'key' => 'secret_key',
            'value' => 'private',
            'is_public' => false,
        ]);

        $response = $this->getJson(route('api.v1.public.event.show'));

        $response->assertStatus(200)
            ->assertJsonFragment(['key' => 'event_name'])
            ->assertJsonMissing(['key' => 'secret_key']);
    }

    public function test_can_browse_ticket_types(): void
    {
        TicketType::factory()->create([
            'name' => 'General Alumni',
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addDays(10),
        ]);

        $response = $this->getJson(route('api.v1.public.ticket-types.index'));

        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'General Alumni']);
    }

    public function test_can_create_registration(): void
    {
        $ticketType = TicketType::factory()->create([
            'base_price_paisa' => 100000,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
        ]);

        $payload = [
            'full_name' => 'Rahim Uddin',
            'mobile' => '+8801712345678',
            'email' => 'rahim@example.com',
            'gender' => 'male',
            'full_name_bn' => 'রহিম উদ্দিন',
            'father_name' => 'Abdul Karim',
            'occupation' => 'Engineer',
            'current_address' => 'House 12, Road 5, Dhanmondi, Dhaka',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 2010,
            'ticket_type_ulid' => $ticketType->ulid,
            'participation_type' => 'single',
            'adults_count' => 1,
            'children_count' => 0,
            'idempotency_key' => 'test-key-12345',
        ];

        $response = $this->withHeader('Idempotency-Key', 'test-key-12345')
            ->postJson(route('api.v1.public.registrations.store'), $payload);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending_payment');

        $this->assertDatabaseHas('attendees', [
            'mobile' => '+8801712345678',
            'full_name' => 'Rahim Uddin',
        ]);

        $this->assertDatabaseHas('registrations', [
            'status' => 'pending_payment',
        ]);
    }
}
