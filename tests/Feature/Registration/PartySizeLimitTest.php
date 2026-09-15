<?php

namespace Tests\Feature\Registration;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Support\PartySize;
use App\Domain\Shared\Support\EventSettingCatalogue;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\TicketTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * How many people one registration may bring, and who decides.
 *
 * The rule under test: a party is bounded by the lower of the ticket type's
 * `max_admits` and the event-wide `registration.max_family_size` setting,
 * and the server refuses anything larger *before* it reserves a seat — the
 * public form's "up to N members" used to be a promise only the browser
 * kept (CLAUDE.md D7).
 */
class PartySizeLimitTest extends TestCase
{
    use RefreshDatabase;

    private function centennialType(): TicketType
    {
        $this->seed(TicketTypeSeeder::class);

        return TicketType::where('code', 'CEN')->firstOrFail();
    }

    private function setMaxFamilySize(int $size): void
    {
        $setting = EventSettingCatalogue::resolve(PartySize::SETTING_KEY);
        self::assertNotNull($setting);
        $setting->value = $setting->castForStorage($size);
        $setting->save();
    }

    /**
     * A party of `$members` beside the registrant, all adults, with the
     * head counts and guest rows agreeing the way the public form sends them.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(TicketType $ticketType, int $members, array $overrides = []): array
    {
        $guests = [];
        for ($i = 1; $i <= $members; $i++) {
            $guests[] = ['full_name' => "Guest {$i}", 'relation' => 'sibling', 'age_group' => 'adult', 'tshirt_required' => true, 'tshirt_size' => 'M'];
        }

        return array_merge([
            'full_name' => 'Rahim Uddin',
            'mobile' => '+8801712345678',
            'gender' => 'male',
            'father_name' => 'Abdul Karim',
            'password' => 'checkout-pass-123',
            'password_confirmation' => 'checkout-pass-123',
            'occupation' => 'Engineer',
            'current_address' => 'House 12, Road 5, Dhanmondi, Dhaka',
            'post_office' => 'Dhanmondi',
            'upazila' => 'Dhanmondi',
            'address_district' => 'Dhaka',
            'date_of_birth' => '1988-04-17',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 2004,
            'ticket_type_ulid' => $ticketType->ulid,
            'participation_type' => $members > 0 ? 'family' : 'single',
            'adults_count' => 1 + $members,
            'children_count' => 0,
            'guests' => $guests,
            'tshirt_required' => true,
            'tshirt_size' => 'L',
            'idempotency_key' => (string) Str::ulid(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function submit(array $payload): TestResponse
    {
        return $this->postJson(
            route('api.v1.public.registrations.store'),
            $payload,
            ['Idempotency-Key' => $payload['idempotency_key']],
        );
    }

    public function test_the_event_setting_is_the_limit_when_family_is_allowed(): void
    {
        $ticketType = $this->centennialType();
        $this->setMaxFamilySize(4);

        $this->assertSame(4, PartySize::limitFor($ticketType));
        $this->assertSame(3, PartySize::maxMembersFor($ticketType));
    }

    public function test_max_admits_does_not_bound_the_party(): void
    {
        $this->setMaxFamilySize(6);

        // Neither a small figure nor the untouched default of 1 caps a
        // ticket whose switch is on — the switch is the whole answer.
        $this->assertSame(6, PartySize::limitFor(TicketType::factory()->create(['max_admits' => 2, 'allows_family' => true])));
        $this->assertSame(6, PartySize::limitFor(TicketType::factory()->create(['max_admits' => 1, 'allows_family' => true])));
    }

    public function test_a_ticket_type_with_family_off_admits_one(): void
    {
        $this->setMaxFamilySize(6);
        $ticketType = TicketType::factory()->create(['base_admits' => 1, 'max_admits' => 9, 'allows_family' => false, 'sale_starts_at' => now()->subDay()]);

        $this->assertSame(1, PartySize::limitFor($ticketType));
        $this->assertSame(0, PartySize::maxMembersFor($ticketType));

        $this->submit($this->payload($ticketType, 1))
            ->assertStatus(422)
            ->assertJsonPath('code', 'party_too_large');

        $this->getJson(route('api.v1.public.ticket-types.index'))
            ->assertJsonFragment(['code' => $ticketType->code, 'allows_family' => false, 'max_party_size' => 1]);
    }

    public function test_the_seeded_centennial_ticket_allows_family(): void
    {
        $this->assertTrue($this->centennialType()->allows_family);
    }

    public function test_the_factory_follows_max_admits_unless_told_otherwise(): void
    {
        $this->assertFalse(TicketType::factory()->create()->allows_family);
        $this->assertTrue(TicketType::factory()->create(['max_admits' => 3])->allows_family);
    }

    public function test_the_catalogue_default_applies_when_nothing_is_stored(): void
    {
        $this->assertDatabaseMissing('event_settings', ['key' => PartySize::SETTING_KEY]);

        $this->assertSame((int) config('event_settings')[PartySize::SETTING_KEY]['default'], PartySize::eventMax());
    }

    public function test_a_setting_of_zero_still_admits_the_registrant(): void
    {
        $this->setMaxFamilySize(0);
        $ticketType = $this->centennialType();

        $this->assertSame(1, PartySize::limitFor($ticketType));

        $this->submit($this->payload($ticketType, 0))->assertStatus(201);
    }

    public function test_a_party_at_the_limit_is_accepted(): void
    {
        $ticketType = $this->centennialType();
        $this->setMaxFamilySize(4);

        $this->submit($this->payload($ticketType, 3))
            ->assertStatus(201)
            ->assertJsonPath('data.adults_count', 4);
    }

    public function test_a_party_over_the_limit_is_refused_without_holding_a_seat(): void
    {
        $ticketType = $this->centennialType();
        $this->setMaxFamilySize(4);

        $response = $this->submit($this->payload($ticketType, 4));

        $response->assertStatus(422)
            ->assertJsonPath('code', 'party_too_large')
            ->assertJsonPath('message', 'This ticket admits at most 4 people including you — up to 3 family members. Remove someone and try again.');

        $this->assertSame(0, Registration::count());
        $this->assertSame(0, (int) $ticketType->fresh()?->quantity_reserved);
    }

    public function test_head_counts_alone_cannot_exceed_the_limit(): void
    {
        $ticketType = $this->centennialType();
        $this->setMaxFamilySize(4);

        // One guest row, but counts claiming a party of five: the counts are
        // what gets priced and stored, so they are bounded on their own.
        $this->submit($this->payload($ticketType, 1, ['adults_count' => 3, 'children_count' => 2]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'party_too_large');
    }

    public function test_guest_rows_alone_cannot_exceed_the_limit(): void
    {
        $ticketType = $this->centennialType();
        $this->setMaxFamilySize(4);

        // Counts inside the limit, but five guest rows: each row is a badge
        // at the gate, so the list is bounded on its own too.
        $this->submit($this->payload($ticketType, 5, ['adults_count' => 2, 'children_count' => 0]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'party_too_large');
    }

    public function test_a_free_infant_still_occupies_an_admit(): void
    {
        $ticketType = $this->centennialType();
        $this->setMaxFamilySize(3);

        $payload = $this->payload($ticketType, 1, [
            'adults_count' => 2,
            'children_count' => 2,
            'guests' => [
                ['full_name' => 'Spouse', 'relation' => 'spouse', 'age_group' => 'adult', 'tshirt_required' => true, 'tshirt_size' => 'M'],
                ['full_name' => 'Baby One', 'relation' => 'child', 'age_group' => 'child', 'age' => 0],
                ['full_name' => 'Baby Two', 'relation' => 'child', 'age_group' => 'child', 'age' => 0],
            ],
        ]);

        $this->submit($payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'party_too_large');
    }

    public function test_a_one_seat_ticket_names_its_rule_plainly(): void
    {
        $ticketType = TicketType::factory()->create(['base_admits' => 1, 'max_admits' => 1, 'allowed_participant_types' => []]);

        $this->submit($this->payload($ticketType, 1))
            ->assertStatus(422)
            ->assertJsonPath('message', 'This ticket admits one person only; family members cannot be added to it.');
    }

    public function test_the_public_ticket_type_api_publishes_the_effective_limit(): void
    {
        $this->centennialType();
        $this->setMaxFamilySize(4);

        $this->getJson(route('api.v1.public.ticket-types.index'))
            ->assertStatus(200)
            ->assertJsonFragment(['code' => 'CEN', 'max_party_size' => 4]);
    }

    public function test_an_attendee_cannot_grow_the_party_past_the_limit_afterwards(): void
    {
        $ticketType = $this->centennialType();
        $this->setMaxFamilySize(3);

        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'pending_payment',
        ]);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $guest = fn (string $name): array => ['full_name' => $name, 'relation' => 'sibling', 'age_group' => 'adult'];

        $this->patchJson(route('api.v1.attendee.registrations.update', ['registration' => $registration->ulid]), [
            'guests' => [$guest('A'), $guest('B'), $guest('C')],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guests']);

        $this->assertSame(0, $registration->guests()->count());

        $this->patchJson(route('api.v1.attendee.registrations.update', ['registration' => $registration->ulid]), [
            'guests' => [$guest('A'), $guest('B')],
        ])->assertStatus(200);

        $this->assertSame(2, $registration->guests()->count());
    }
}
