<?php

namespace Tests\Feature\Admin;

use App\Domain\Registration\Support\PartySize;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The console's "Family members allowed" switch on a ticket type.
 *
 * Off by default on a new type, saved as sent, and — the point of it —
 * decisive on its own: on, the party is bounded by the event's Max family
 * size; off, it is one. `max_admits` is not consulted either way.
 */
class TicketTypeFamilySwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $admin = User::factory()->create();
        $admin->syncRoles(['Super Admin']);

        Sanctum::actingAs($admin, ['admin'], 'web-admin');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'FAM',
            'name' => 'Family',
            'base_price_tk' => 150000,
            'additional_adult_price_tk' => 100000,
            'additional_child_price_tk' => 100000,
            'base_admits' => 1,
            'max_admits' => 6,
        ], $overrides);
    }

    public function test_a_new_type_does_not_allow_family_unless_asked(): void
    {
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.allows_family', false)
            ->assertJsonPath('data.max_party_size', 1);
    }

    public function test_the_switch_is_saved_and_widens_the_party_to_the_event_rule(): void
    {
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload(['allows_family' => true]))
            ->assertStatus(201)
            ->assertJsonPath('data.allows_family', true)
            ->assertJsonPath('data.max_party_size', PartySize::eventMax());
    }

    public function test_the_switch_works_on_a_ticket_left_at_one_max_admit(): void
    {
        // The case that first motivated a "needs max_admits >= 2" refusal,
        // withdrawn the same day: the organiser's checkbox must simply work.
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload(['max_admits' => 1, 'allows_family' => true]))
            ->assertStatus(201)
            ->assertJsonPath('data.max_party_size', PartySize::eventMax());
    }

    public function test_turning_it_off_caps_the_party_at_one(): void
    {
        $ticketType = TicketType::factory()->create(['max_admits' => 6, 'allows_family' => true]);

        $this->patchJson(route('api.v1.admin.ticket-types.update', $ticketType->ulid), ['allows_family' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.allows_family', false)
            ->assertJsonPath('data.max_party_size', 1);

        $this->assertSame(1, PartySize::limitFor($ticketType->fresh() ?? $ticketType));
    }

    public function test_the_switch_must_be_a_boolean(): void
    {
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload(['allows_family' => 'maybe']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['allows_family']);
    }
}
