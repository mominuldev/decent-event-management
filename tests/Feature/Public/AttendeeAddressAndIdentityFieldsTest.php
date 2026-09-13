<?php

namespace Tests\Feature\Public;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The six fields the registration form gained on 2026-09-13: post office,
 * upazila, district, date of birth, NID or birth registration number and
 * blood group.
 *
 * Three rules are worth protecting here and each has its own group below.
 *
 *  - **Required means required at the public form, not in the column.** The
 *    same asymmetry the 2026-08-16 fields established: four of the six
 *    refuse a submission that omits them, while the columns stay nullable
 *    and the admin edit path leaves them all optional, so an attendee row
 *    that predates them is still editable.
 *  - **NID and blood group are optional on purpose.** An under-18 current
 *    student has no NID, and a forced blood group is a guessed one.
 *  - **An NID must not leak to a lookup session.** `AttendeeResource` is
 *    returned to a "find my ticket" caller, whose credential is a mobile
 *    number plus a name the public directory publishes. That session may
 *    read the record and may not read a government ID number out of it, nor
 *    overwrite one it was never shown.
 */
class AttendeeAddressAndIdentityFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ip:127.0.0.1');
    }

    private function ticketType(): TicketType
    {
        return TicketType::factory()->create([
            'base_price_tk' => 100000,
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
            'current_address' => 'Village Pirgachi, Road 5',
            'post_office' => 'Alampur',
            'upazila' => 'Bholahat',
            'address_district' => 'Chapainawabganj',
            'date_of_birth' => '1988-04-17',
            'nid_number' => '1234567890',
            'blood_group' => 'B+',
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

    private function registrant(): Attendee
    {
        return Attendee::where('mobile', '+8801712345678')->firstOrFail();
    }

    // ------------------------------------------------------------- stored

    public function test_registration_stores_all_six_fields(): void
    {
        $this->submit($this->payload($this->ticketType()))->assertStatus(201);

        $attendee = $this->registrant();

        $this->assertSame('Alampur', $attendee->post_office);
        $this->assertSame('Bholahat', $attendee->upazila);
        $this->assertSame('Chapainawabganj', $attendee->address_district);
        $this->assertSame('1988-04-17', $attendee->date_of_birth?->format('Y-m-d'));
        $this->assertSame('1234567890', $attendee->nid_number);
        $this->assertSame('B+', $attendee->blood_group);
    }

    public function test_a_returning_registrant_updates_all_six(): void
    {
        $ticketType = $this->ticketType();

        $this->submit($this->payload($ticketType))->assertStatus(201);

        // One live registration per attendee is the rule, and cancelled is a
        // state it deliberately does not count.
        Registration::query()->each(
            fn (Registration $registration) => $registration->forceFill(['status' => 'cancelled'])->save()
        );

        // Same mobile — the dedupe key — so this resolves to the existing row.
        $this->submit($this->payload($ticketType, [
            'idempotency_key' => (string) Str::ulid(),
            'post_office' => 'Uttara',
            'upazila' => 'Uttara',
            'address_district' => 'Dhaka',
            'date_of_birth' => '1988-05-01',
            'nid_number' => '1990123456789',
            'blood_group' => 'O-',
        ]))->assertStatus(201);

        $this->assertSame(1, Attendee::where('mobile', '+8801712345678')->count());

        $attendee = $this->registrant();

        $this->assertSame('Uttara', $attendee->post_office);
        $this->assertSame('Uttara', $attendee->upazila);
        $this->assertSame('Dhaka', $attendee->address_district);
        $this->assertSame('1988-05-01', $attendee->date_of_birth?->format('Y-m-d'));
        $this->assertSame('1990123456789', $attendee->nid_number);
        $this->assertSame('O-', $attendee->blood_group);
    }

    public function test_the_public_registration_response_exposes_the_address_but_never_the_nid(): void
    {
        $response = $this->submit($this->payload($this->ticketType()))->assertStatus(201);

        $body = $this->getJson(route('api.v1.public.registrations.show', $response->json('data.ulid')))
            ->assertStatus(200)
            ->assertJsonPath('data.attendee.post_office', 'Alampur')
            ->assertJsonPath('data.attendee.upazila', 'Bholahat')
            ->assertJsonPath('data.attendee.address_district', 'Chapainawabganj')
            ->json();

        // This endpoint is unauthenticated — anyone holding a registration
        // ULID may read it — so the NID must not be in the shape at all.
        $this->assertArrayNotHasKey('nid_number', $body['data']['attendee']);
    }

    // ----------------------------------------------------------- required

    public function test_each_of_the_four_new_required_fields_is_required(): void
    {
        $ticketType = $this->ticketType();

        foreach (['post_office', 'upazila', 'address_district', 'date_of_birth'] as $field) {
            $payload = $this->payload($ticketType);
            unset($payload[$field]);

            $this->submit($payload)
                ->assertStatus(422)
                ->assertJsonValidationErrors($field);
        }

        // A refused submission must not have consumed capacity.
        $this->assertSame(0, (int) $ticketType->refresh()->sold_count);
        $this->assertSame(0, Registration::count());
    }

    public function test_a_blank_string_does_not_satisfy_the_address_requirement(): void
    {
        $this->submit($this->payload($this->ticketType(), [
            'post_office' => '',
            'upazila' => '   ',
            'address_district' => '',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['post_office', 'upazila', 'address_district']);
    }

    public function test_the_nid_and_blood_group_are_optional(): void
    {
        $payload = $this->payload($this->ticketType());
        unset($payload['nid_number'], $payload['blood_group']);

        $this->submit($payload)->assertStatus(201);

        $attendee = $this->registrant();

        $this->assertNull($attendee->nid_number);
        $this->assertNull($attendee->blood_group);
    }

    // -------------------------------------------------------------- shapes

    /**
     * The three NID widths Bangladesh has actually issued, plus the two
     * birth-registration widths (17 online, 16 on a pre-2013 certificate),
     * since 2026-09-13 the field takes either. A number typed with the
     * spaces printed on the card or certificate must be accepted, because
     * refusing it would look like the form rejecting a genuine ID.
     */
    public function test_every_real_nid_or_birth_registration_width_is_accepted_and_stored_as_digits(): void
    {
        $cases = [
            '1234 5678-90' => '1234567890',
            '1990 123 456 789' => '1990123456789',
            'NID: 1990 1234 5678 90123' => '19901234567890123',
            // A pre-2013 birth certificate: 16 digits, stored as typed rather
            // than padded to 17 — see NationalId's docblock.
            '2008 1234 5678 9012' => '2008123456789012',
        ];

        $ticketType = $this->ticketType();
        $i = 0;

        foreach ($cases as $typed => $stored) {
            $mobile = '+88017123456'.str_pad((string) $i++, 2, '0', STR_PAD_LEFT);

            $this->submit($this->payload($ticketType, [
                'mobile' => $mobile,
                'email' => "nid{$i}@example.com",
                'nid_number' => $typed,
                'idempotency_key' => (string) Str::ulid(),
            ]))->assertStatus(201);

            $this->assertSame($stored, Attendee::where('mobile', $mobile)->value('nid_number'));
        }
    }

    public function test_a_number_that_is_not_a_real_nid_width_is_refused(): void
    {
        // 12 digits: between two real widths, so a plain digits_between rule
        // would have let it through.
        $this->submit($this->payload($this->ticketType(), ['nid_number' => '123456789012']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('nid_number');
    }

    /**
     * An empty box and a box holding "N/A" are the same answer — no number —
     * and both must reach the column as NULL rather than as a string. A
     * column holding both NULL and `''` for one fact is a filter everyone
     * gets wrong exactly once.
     */
    public function test_an_answer_with_no_digits_in_it_is_stored_as_no_number(): void
    {
        $ticketType = $this->ticketType();

        foreach (['', 'N/A'] as $i => $typed) {
            $mobile = '+880171234599'.$i;

            $this->submit($this->payload($ticketType, [
                'mobile' => $mobile,
                'email' => "blank{$i}@example.com",
                'nid_number' => $typed,
                'idempotency_key' => (string) Str::ulid(),
            ]))->assertStatus(201);

            $this->assertNull(Attendee::where('mobile', $mobile)->value('nid_number'));
        }
    }

    public function test_a_date_of_birth_in_the_future_is_refused(): void
    {
        $this->submit($this->payload($this->ticketType(), [
            'date_of_birth' => now()->addDay()->format('Y-m-d'),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_a_blood_group_outside_the_catalogue_is_refused(): void
    {
        $this->submit($this->payload($this->ticketType(), ['blood_group' => 'O positive']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('blood_group');
    }

    /**
     * `address_district` is VARCHAR(80). The self-service profile validated
     * it at 100 until 2026-09-13, so an 81–100 character value reached MySQL
     * and died there — a 500 where a field-level 422 belongs, exactly the
     * mismatch the name fields once had at max:200 against VARCHAR(150).
     */
    public function test_an_over_length_district_is_a_validation_error_not_a_database_error(): void
    {
        $this->submit($this->payload($this->ticketType(), [
            'address_district' => str_repeat('a', 81),
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('address_district');
    }

    // ------------------------------------------------- the lookup session

    /**
     * @return array{0: Attendee, 1: string}
     */
    private function openLookupSession(): array
    {
        $attendee = Attendee::factory()->create([
            'full_name' => 'Rahim Uddin',
            'mobile' => '+8801711111111',
            'nid_number' => '1234567890',
        ]);

        $token = $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim Uddin',
        ])->assertStatus(200)->json('token');

        return [$attendee, $token];
    }

    public function test_a_lookup_session_is_told_an_nid_exists_but_never_shown_it(): void
    {
        [, $token] = $this->openLookupSession();

        $body = $this->withToken($token)
            ->getJson(route('api.v1.attendee.find-my-ticket.me.show'))
            ->assertStatus(200)
            ->assertJsonPath('data.nid_number_set', true)
            ->json();

        $this->assertArrayNotHasKey('nid_number', $body['data']);
    }

    public function test_a_lookup_session_cannot_overwrite_the_nid_it_cannot_read(): void
    {
        [$attendee, $token] = $this->openLookupSession();

        // The rest of the correction is applied, so this proves the field is
        // dropped from the ruleset rather than the whole request failing.
        $this->withToken($token)
            ->patchJson(route('api.v1.attendee.find-my-ticket.me.update'), [
                'occupation' => 'Teacher',
                'nid_number' => '9999999999',
            ])
            ->assertStatus(200);

        $attendee->refresh();

        $this->assertSame('Teacher', $attendee->occupation);
        $this->assertSame('1234567890', $attendee->nid_number);
    }

    public function test_a_signed_in_attendee_reads_their_own_nid(): void
    {
        $attendee = Attendee::factory()->create(['nid_number' => '1234567890']);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $this->getJson(route('api.v1.attendee.me.show'))
            ->assertStatus(200)
            ->assertJsonPath('data.nid_number', '1234567890')
            ->assertJsonPath('data.nid_number_set', true);
    }

    // --------------------------------------------------------- batch year

    /**
     * A current student has not sat SSC, so they may register without a
     * batch year; a former student may not. Both directions are pinned so
     * the rule cannot quietly collapse back to "students, plural".
     */
    public function test_a_current_student_may_register_without_a_batch_year_but_a_former_student_may_not(): void
    {
        $ticketType = $this->ticketType();

        $current = $this->payload($ticketType, ['participant_type' => 'current_student', 'current_class' => '10']);
        unset($current['ssc_batch_year']);
        $this->submit($current)->assertStatus(201);

        $this->assertNull($this->registrant()->ssc_batch_year);

        $former = $this->payload($ticketType, [
            'participant_type' => 'former_student',
            'mobile' => '+8801712345699',
            'email' => 'former@example.com',
            'idempotency_key' => (string) Str::ulid(),
        ]);
        unset($former['ssc_batch_year']);
        $this->submit($former)->assertStatus(422)->assertJsonValidationErrors('ssc_batch_year');
    }

    // -------------------------------------------------------------- admin

    /**
     * An attendee predating these columns must stay editable, which is the
     * whole reason the admin rules are `nullable` rather than `required`.
     */
    public function test_an_admin_may_correct_the_new_fields_on_a_legacy_attendee(): void
    {
        $attendee = Attendee::factory()->create([
            'post_office' => null,
            'upazila' => null,
            'address_district' => null,
            'date_of_birth' => null,
            'nid_number' => null,
            'blood_group' => null,
        ]);

        $this->actingAsAdmin();

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'post_office' => 'Alampur',
            'upazila' => 'Bholahat',
            'address_district' => 'Chapainawabganj',
            'date_of_birth' => '1975-01-09',
            'nid_number' => '1975 1234 5678 90123',
            'blood_group' => 'AB-',
        ])
            ->assertStatus(200)
            ->assertJsonPath('data.post_office', 'Alampur')
            ->assertJsonPath('data.upazila', 'Bholahat')
            ->assertJsonPath('data.address_district', 'Chapainawabganj')
            ->assertJsonPath('data.blood_group', 'AB-')
            // Normalised on the admin path too, not only the public one.
            ->assertJsonPath('data.nid_number', '19751234567890123');
    }

    public function test_an_admin_may_still_edit_an_attendee_that_has_none_of_them(): void
    {
        $attendee = Attendee::factory()->create([
            'post_office' => null,
            'upazila' => null,
            'address_district' => null,
            'date_of_birth' => null,
        ]);

        $this->actingAsAdmin();

        $this->patchJson(route('api.v1.admin.attendees.update', $attendee->ulid), [
            'notes' => 'Called to confirm attendance.',
        ])->assertStatus(200);

        $this->assertNull($attendee->refresh()->post_office);
    }

    /**
     * The counter form mirrors the public one, so a record taken at a desk
     * is as complete as one taken online. A counter sale that could skip
     * these would quietly become the way incomplete records get into the
     * printed directory.
     */
    public function test_a_counter_registration_requires_the_same_address_and_date_of_birth(): void
    {
        $this->actingAsAdmin();

        $ticketType = $this->ticketType();
        $payload = $this->payload($ticketType);
        unset(
            $payload['idempotency_key'],
            $payload['post_office'],
            $payload['upazila'],
            $payload['address_district'],
            $payload['date_of_birth'],
        );

        $this->withHeader('Idempotency-Key', (string) Str::ulid())
            ->postJson(route('api.v1.admin.registrations.store'), $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['post_office', 'upazila', 'address_district', 'date_of_birth']);

        $this->assertSame(0, (int) $ticketType->refresh()->sold_count);
    }

    private function actingAsAdmin(): void
    {
        $this->seed(RbacSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('Super Admin');

        Sanctum::actingAs($admin, ['admin'], 'web-admin');
    }
}
