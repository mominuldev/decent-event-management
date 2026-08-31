<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `ticket_types.allowed_participant_types` is `json NOT NULL` with no default
 * and both FormRequests mark it `nullable`, so a create that omitted it
 * reached MySQL as an insert with the column missing:
 *
 *   SQLSTATE[HY000]: General error: 1364 Field 'allowed_participant_types'
 *   doesn't have a default value
 *
 * Which is what the admin console did on every create — its payload type had
 * no such key. No test caught it because every existing one passes the field;
 * the only path nobody exercised was the one the console actually takes.
 */
class TicketTypeAudienceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->syncRoles(['Super Admin']);

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'code' => 'ALM',
            'name' => 'Alumni',
            'base_price_paisa' => 150000,
            'additional_adult_price_paisa' => 0,
            'additional_child_price_paisa' => 0,
            'base_admits' => 1,
            'max_admits' => 1,
        ], $overrides);
    }

    /** The reported failure, exactly as the console sent it. */
    public function test_a_ticket_type_can_be_created_without_naming_an_audience(): void
    {
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload())
            ->assertStatus(201)
            ->assertJsonPath('data.allowed_participant_types', []);

        $this->assertSame([], TicketType::where('code', 'ALM')->firstOrFail()->allowed_participant_types);
    }

    /**
     * The column is NOT NULL, so passing the null through would swap a 1364
     * for a 1048 rather than fixing anything.
     */
    public function test_an_explicit_null_audience_is_stored_as_an_empty_list(): void
    {
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload([
            'allowed_participant_types' => null,
        ]))->assertStatus(201)->assertJsonPath('data.allowed_participant_types', []);

        $this->assertSame([], TicketType::where('code', 'ALM')->firstOrFail()->allowed_participant_types);
    }

    public function test_a_named_audience_is_stored_as_given(): void
    {
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload([
            'allowed_participant_types' => ['sponsor'],
        ]))->assertStatus(201);

        $this->assertSame(['sponsor'], TicketType::where('code', 'ALM')->firstOrFail()->allowed_participant_types);
    }

    public function test_an_update_may_clear_the_audience_back_to_everyone(): void
    {
        $ulid = $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload([
            'allowed_participant_types' => ['sponsor'],
        ]))->json('data.ulid');

        $this->patchJson(route('api.v1.admin.ticket-types.update', $ulid), [
            'allowed_participant_types' => null,
        ])->assertOk()->assertJsonPath('data.allowed_participant_types', []);
    }

    /** Absent has to keep meaning "leave this alone", or every edit widens the audience. */
    public function test_an_update_that_omits_the_audience_leaves_it_untouched(): void
    {
        $ulid = $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload([
            'allowed_participant_types' => ['sponsor'],
        ]))->json('data.ulid');

        $this->patchJson(route('api.v1.admin.ticket-types.update', $ulid), ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('data.allowed_participant_types', ['sponsor']);
    }

    /**
     * The default is not merely "valid", it already means something: an empty
     * list is open to everyone, which is how CreateRegistration reads it.
     */
    public function test_a_type_created_without_an_audience_sells_to_any_participant_type(): void
    {
        $this->postJson(route('api.v1.admin.ticket-types.store'), $this->payload([
            'quantity_total' => 10,
        ]))->assertStatus(201);

        $ticketType = TicketType::where('code', 'ALM')->firstOrFail();

        $this->assertSame([], $ticketType->allowed_participant_types);
        // The same check CreateRegistration performs before reserving.
        $this->assertTrue($ticketType->allowed_participant_types === []);
    }

    /** Nothing else may create a row that MySQL refuses, either. */
    public function test_the_model_supplies_the_default_for_any_writer(): void
    {
        $ticketType = TicketType::create([
            'code' => 'DIRECT',
            'name' => 'Created outside the console',
            'base_price_paisa' => 0,
            'base_admits' => 1,
            'max_admits' => 1,
        ]);

        $this->assertSame([], $ticketType->refresh()->allowed_participant_types);
    }
}
