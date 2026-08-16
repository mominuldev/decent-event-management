<?php

namespace Tests\Feature\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Database\Seeders\TicketTypeSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * An attendee's mobile number and email address each identify exactly one
 * person.
 *
 * `mobile` has been unique in the database since the table was created, but
 * nothing validated it, so an admin edit onto a taken number hit the
 * constraint and surfaced as a 500. `email` was not unique at all. Both are
 * enforced now — and the interesting part is that they cannot be enforced
 * the same way at every entry point: the public registration path matches a
 * *returning* registrant on their mobile number (ADR-08), so there a
 * repeated mobile is expected and only the email can conflict.
 */
class AttendeeUniquenessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
    }

    private function centennialType(): TicketType
    {
        $this->seed(TicketTypeSeeder::class);

        return TicketType::where('code', 'CEN')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registrationPayload(TicketType $ticketType, array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Rahim Uddin',
            'mobile' => '+8801712345678',
            'email' => 'rahim@example.com',
            'gender' => 'male',
            'full_name_bn' => 'রহিম উদ্দিন',
            'father_name' => 'Abdul Karim',
            'occupation' => 'Engineer',
            'current_address' => 'House 12, Road 5, Dhanmondi, Dhaka',
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
    private function register(array $payload): TestResponse
    {
        return $this->postJson(
            route('api.v1.public.registrations.store'),
            $payload,
            ['Idempotency-Key' => $payload['idempotency_key']],
        );
    }

    // ---------------------------------------------------------------
    // The database constraints themselves
    // ---------------------------------------------------------------

    public function test_both_identifiers_carry_a_unique_index(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM attendees'))
            ->filter(fn (object $index): bool => in_array($index->Column_name, ['email', 'mobile'], true))
            ->filter(fn (object $index): bool => (int) $index->Non_unique === 0)
            ->pluck('Column_name')
            ->unique()
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(['email', 'mobile'], $indexes);
    }

    public function test_the_database_refuses_a_duplicate_email_outright(): void
    {
        Attendee::factory()->create(['email' => 'shared@example.com']);

        $this->expectException(QueryException::class);

        Attendee::factory()->create(['email' => 'shared@example.com']);
    }

    /**
     * Nullable and unique have to coexist: most attendees register by mobile
     * alone, so "no email" must remain repeatable while an actual address
     * stays exclusive.
     */
    public function test_many_attendees_may_have_no_email(): void
    {
        Attendee::factory()->count(3)->create(['email' => null]);

        $this->assertSame(3, Attendee::whereNull('email')->count());
    }

    // ---------------------------------------------------------------
    // Admin edits
    // ---------------------------------------------------------------

    public function test_admin_cannot_move_an_email_onto_a_second_attendee(): void
    {
        Attendee::factory()->create(['email' => 'taken@example.com']);
        $target = Attendee::factory()->create(['email' => 'mine@example.com']);

        $this->actingAsAdmin();

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $target->ulid]), [
            'email' => 'taken@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('mine@example.com', $target->refresh()->email);
    }

    public function test_admin_cannot_move_a_mobile_onto_a_second_attendee(): void
    {
        Attendee::factory()->create(['mobile' => '+8801700000001']);
        $target = Attendee::factory()->create(['mobile' => '+8801700000002']);

        $this->actingAsAdmin();

        // Before this was validated, the unique index turned this into an
        // unhandled QueryException — a 500 with a SQL error in the log
        // rather than a field-level message on the form.
        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $target->ulid]), [
            'mobile' => '+8801700000001',
        ])->assertStatus(422)->assertJsonValidationErrors('mobile');

        $this->assertSame('+8801700000002', $target->refresh()->mobile);
    }

    /**
     * Formatting must not be a way through. `+880 1700-000001` and
     * `+8801700000001` are one number, and the check normalises before
     * comparing — otherwise the validator passes and the database rejects.
     */
    public function test_a_differently_formatted_mobile_is_still_a_duplicate(): void
    {
        Attendee::factory()->create(['mobile' => '+8801700000001']);
        $target = Attendee::factory()->create(['mobile' => '+8801700000002']);

        $this->actingAsAdmin();

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $target->ulid]), [
            'mobile' => '+880 1700-000001',
        ])->assertStatus(422)->assertJsonValidationErrors('mobile');
    }

    /**
     * The column collates case-insensitively, so a capitalised duplicate is
     * a duplicate at the database level too — the validator has to agree.
     */
    public function test_a_differently_cased_email_is_still_a_duplicate(): void
    {
        Attendee::factory()->create(['email' => 'taken@example.com']);
        $target = Attendee::factory()->create(['email' => 'mine@example.com']);

        $this->actingAsAdmin();

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $target->ulid]), [
            'email' => '  TAKEN@Example.COM ',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_an_attendee_may_be_saved_with_their_own_unchanged_identifiers(): void
    {
        $attendee = Attendee::factory()->create([
            'email' => 'self@example.com',
            'mobile' => '+8801700000003',
        ]);

        $this->actingAsAdmin();

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'full_name' => 'Renamed Person',
            'email' => 'Self@Example.com',
            'mobile' => '+8801700000003',
        ])->assertStatus(200);

        $attendee->refresh();
        $this->assertSame('Renamed Person', $attendee->full_name);
        $this->assertSame('self@example.com', $attendee->email, 'The stored address is normalised, not echoed back as typed.');
    }

    /**
     * A soft-deleted attendee keeps their identifiers, because the unique
     * index counts soft-deleted rows. The point of this test is that the
     * *validator* agrees with the index: the conflict has to be reported as
     * a 422, not left to become a 500 nobody can act on.
     */
    public function test_a_soft_deleted_attendee_still_holds_their_email(): void
    {
        $deleted = Attendee::factory()->create(['email' => 'gone@example.com']);
        $deleted->delete();

        $target = Attendee::factory()->create(['email' => 'here@example.com']);

        $this->actingAsAdmin();

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $target->ulid]), [
            'email' => 'gone@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // ---------------------------------------------------------------
    // Attendee self-service
    // ---------------------------------------------------------------

    public function test_an_attendee_cannot_claim_another_attendees_email(): void
    {
        Attendee::factory()->create(['email' => 'someone.else@example.com']);
        $attendee = Attendee::factory()->create(['email' => 'own@example.com']);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $this->patchJson(route('api.v1.attendee.me.update'), [
            'email' => 'someone.else@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('email');

        $this->assertSame('own@example.com', $attendee->refresh()->email);
    }

    public function test_an_attendee_can_set_an_unclaimed_email(): void
    {
        $attendee = Attendee::factory()->create(['email' => null]);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $this->patchJson(route('api.v1.attendee.me.update'), [
            'email' => 'Fresh@Example.com',
        ])->assertStatus(200);

        $this->assertSame('fresh@example.com', $attendee->refresh()->email);
    }

    // ---------------------------------------------------------------
    // Public registration
    // ---------------------------------------------------------------

    /**
     * The email is refused *before* capacity is reserved, for the same
     * reason participant type is: a registration that cannot be created
     * must not sit on a seat somebody else could have bought.
     */
    public function test_registering_with_another_persons_email_is_refused_without_holding_a_seat(): void
    {
        $ticketType = $this->centennialType();
        Attendee::factory()->create([
            'mobile' => '+8801799999999',
            'email' => 'already@example.com',
        ]);

        $soldBefore = $ticketType->refresh()->sold_count;

        $this->register($this->registrationPayload($ticketType, [
            'mobile' => '+8801712345678',
            'email' => 'already@example.com',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'email_already_registered');

        $this->assertSame($soldBefore, $ticketType->refresh()->sold_count);
        $this->assertDatabaseCount('registrations', 0);
    }

    /**
     * The counterpart, and the reason the public path cannot simply carry a
     * `unique` rule: a returning registrant arrives with both identifiers
     * already on file and must be matched, not rejected.
     */
    public function test_a_returning_registrant_may_reuse_their_own_mobile_and_email(): void
    {
        $ticketType = $this->centennialType();

        $this->register($this->registrationPayload($ticketType))->assertStatus(201);
        $this->register($this->registrationPayload($ticketType))->assertStatus(201);

        $this->assertSame(1, Attendee::where('mobile', '+8801712345678')->count());
        $this->assertDatabaseCount('registrations', 2);
    }

    /**
     * Registering on a new mobile number with an email that a soft-deleted
     * attendee still holds is refused as a 422 rather than reaching the
     * unique index — the constraint counts soft-deleted rows, so the guard
     * in front of it has to as well.
     */
    public function test_registration_is_refused_when_the_email_belongs_to_a_deleted_attendee(): void
    {
        $ticketType = $this->centennialType();

        $deleted = Attendee::factory()->create([
            'mobile' => '+8801788888888',
            'email' => 'retired@example.com',
        ]);
        $deleted->delete();

        $this->register($this->registrationPayload($ticketType, [
            'email' => 'retired@example.com',
        ]))
            ->assertStatus(422)
            ->assertJsonPath('code', 'email_already_registered');
    }

    /**
     * Two people sharing one household mobile is not a supported case — the
     * second registration updates the first attendee rather than creating
     * one — but the email they each supply must not silently overwrite the
     * other's. Here the second caller is the same attendee by mobile, so
     * their own new address is accepted.
     */
    public function test_a_returning_registrant_may_change_their_email(): void
    {
        $ticketType = $this->centennialType();

        $this->register($this->registrationPayload($ticketType))->assertStatus(201);

        $this->register($this->registrationPayload($ticketType, [
            'email' => 'New.Address@example.com',
        ]))->assertStatus(201);

        $this->assertSame('new.address@example.com', Attendee::where('mobile', '+8801712345678')->value('email'));
    }

    public function test_an_omitted_email_never_conflicts(): void
    {
        $ticketType = $this->centennialType();

        $this->register($this->registrationPayload($ticketType, ['mobile' => '+8801711111111', 'email' => null]))
            ->assertStatus(201);
        $this->register($this->registrationPayload($ticketType, ['mobile' => '+8801722222222', 'email' => null]))
            ->assertStatus(201);

        $this->assertSame(2, Attendee::whereNull('email')->count());
    }
}
