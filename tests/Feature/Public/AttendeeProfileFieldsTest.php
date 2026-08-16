<?php

namespace Tests\Feature\Public;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The four biographical fields the registration form asks every registrant
 * for: their name in Bangla, their father's name, their occupation, and
 * where they currently live.
 *
 * The rule worth protecting here is the asymmetry between them: the public
 * form refuses a submission missing any of the four, while the columns stay
 * nullable and the admin edit path leaves them optional. Tightening the admin
 * path to match the public one would make every unrelated edit to an attendee
 * who predates these fields impossible to save.
 */
class AttendeeProfileFieldsTest extends TestCase
{
    use RefreshDatabase;

    private function ticketType(): TicketType
    {
        return TicketType::factory()->create([
            'base_price_paisa' => 100000,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(TicketType $ticketType, array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => 'রহিম উদ্দিন',
            'father_name' => 'Abdul Karim Uddin',
            'mobile' => '+8801712345678',
            'email' => 'rahim@example.com',
            'gender' => 'male',
            'occupation' => 'Civil Engineer',
            'current_address' => 'House 12, Road 5, Dhanmondi, Dhaka-1205',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 2004,
            'ticket_type_ulid' => $ticketType->ulid,
            'participation_type' => 'single',
            'adults_count' => 1,
            'children_count' => 0,
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

    public function test_registration_stores_all_four_fields(): void
    {
        $this->submit($this->payload($this->ticketType()))->assertStatus(201);

        $attendee = Attendee::where('mobile', '+8801712345678')->firstOrFail();

        $this->assertSame('রহিম উদ্দিন', $attendee->full_name_bn);
        $this->assertSame('Abdul Karim Uddin', $attendee->father_name);
        $this->assertSame('Civil Engineer', $attendee->occupation);
        $this->assertSame('House 12, Road 5, Dhanmondi, Dhaka-1205', $attendee->current_address);
    }

    public function test_each_of_the_four_fields_is_required(): void
    {
        $ticketType = $this->ticketType();

        foreach (['full_name_bn', 'father_name', 'occupation', 'current_address'] as $field) {
            $payload = $this->payload($ticketType);
            unset($payload[$field]);

            $this->submit($payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        // A refused submission must not have consumed capacity, the same way
        // the participant-type and email refusals do not.
        $this->assertSame(0, (int) $ticketType->refresh()->sold_count);
        $this->assertSame(0, Registration::count());
    }

    public function test_a_blank_string_does_not_satisfy_the_requirement(): void
    {
        $this->submit($this->payload($this->ticketType(), [
            'full_name_bn' => '',
            'father_name' => '',
            'occupation' => '   ',
            'current_address' => '',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['full_name_bn', 'father_name', 'occupation', 'current_address']);
    }

    public function test_a_returning_registrant_updates_all_four(): void
    {
        $ticketType = $this->ticketType();

        $this->submit($this->payload($ticketType))->assertStatus(201);

        // Same mobile — the dedupe key — so this resolves to the existing
        // attendee rather than creating a second one.
        $this->submit($this->payload($ticketType, [
            'idempotency_key' => (string) Str::ulid(),
            'full_name_bn' => 'আব্দুল করিম উদ্দিন',
            'father_name' => 'Abdul Karim',
            'occupation' => 'Retired',
            'current_address' => 'Flat 3B, Uttara Sector 7, Dhaka-1230',
        ]))->assertStatus(201);

        $this->assertSame(1, Attendee::where('mobile', '+8801712345678')->count());

        $attendee = Attendee::where('mobile', '+8801712345678')->firstOrFail();

        $this->assertSame('আব্দুল করিম উদ্দিন', $attendee->full_name_bn);
        $this->assertSame('Abdul Karim', $attendee->father_name);
        $this->assertSame('Retired', $attendee->occupation);
        $this->assertSame('Flat 3B, Uttara Sector 7, Dhaka-1230', $attendee->current_address);
    }

    public function test_the_public_registration_response_exposes_all_four(): void
    {
        $response = $this->submit($this->payload($this->ticketType()))->assertStatus(201);

        $this->getJson(route('api.v1.public.registrations.show', $response->json('data.ulid')))
            ->assertStatus(200)
            ->assertJsonPath('data.attendee.full_name_bn', 'রহিম উদ্দিন')
            ->assertJsonPath('data.attendee.father_name', 'Abdul Karim Uddin')
            ->assertJsonPath('data.attendee.occupation', 'Civil Engineer')
            ->assertJsonPath('data.attendee.current_address', 'House 12, Road 5, Dhanmondi, Dhaka-1205');
    }

    public function test_an_admin_may_edit_the_new_fields_without_supplying_the_others(): void
    {
        $attendee = Attendee::factory()->create([
            'father_name' => null,
            'occupation' => null,
            'current_address' => null,
        ]);

        $this->actingAsAdmin();

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'father_name' => 'Mohammad Ali',
            'occupation' => 'Teacher',
            'current_address' => 'Village Shibpur, Narsingdi',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.father_name', 'Mohammad Ali')
            ->assertJsonPath('data.occupation', 'Teacher')
            ->assertJsonPath('data.current_address', 'Village Shibpur, Narsingdi');
    }

    /**
     * An attendee predating these columns must stay editable — this is the
     * whole reason the admin rules are `nullable` rather than `required`.
     */
    public function test_an_admin_may_edit_a_legacy_attendee_that_has_none_of_them(): void
    {
        $attendee = Attendee::factory()->create([
            'father_name' => null,
            'occupation' => null,
            'current_address' => null,
        ]);

        $this->actingAsAdmin();

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'notes' => 'Called to confirm attendance.',
        ])
            ->assertStatus(200);

        $this->assertNull($attendee->refresh()->father_name);
    }

    private function actingAsAdmin(): void
    {
        $this->seed(RbacSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('Super Admin');

        Sanctum::actingAs($admin, ['admin'], 'web-admin');
    }
}
