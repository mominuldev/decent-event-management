<?php

namespace Tests\Feature\Admin;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `attendees.is_verified` is staff identity confirmation (docs/03 §attendees:
 * "Alumni identity confirmed by an Event Manager") — not payment status and
 * not email/mobile confirmation. Nothing in the registration or payment path
 * sets it, deliberately, so every self-registered attendee starts unverified.
 *
 * The admin console's Verified switch is the only way to change it, and it
 * silently did nothing until 2026-09-11: `is_verified` is not `$fillable`, so
 * `$attendee->update($request->validated())` dropped it with no error while
 * reporting a successful save.
 */
class AttendeeVerificationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
    }

    public function test_a_registered_attendee_starts_unverified(): void
    {
        $attendee = Attendee::factory()->create(['is_verified' => false]);

        $this->getJson(route('api.v1.admin.attendees.show', ['attendee' => $attendee->ulid]))
            ->assertStatus(200)
            ->assertJsonPath('data.is_verified', false)
            ->assertJsonPath('data.verified_at', null);
    }

    /**
     * The regression this file exists for. Confirmed to fail against the
     * previous code, which answered 200 with `is_verified` still false.
     */
    public function test_verifying_an_attendee_persists(): void
    {
        $attendee = Attendee::factory()->create(['is_verified' => false]);

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'is_verified' => true,
        ])->assertStatus(200)->assertJsonPath('data.is_verified', true);

        $attendee->refresh();

        $this->assertTrue((bool) $attendee->is_verified);
        $this->assertSame($this->admin->id, $attendee->verified_by_user_id);
        $this->assertNotNull($attendee->verified_at);
    }

    /**
     * Who vouched for an attendee is the point of the record — a boolean with
     * no attribution is not an audit trail.
     */
    public function test_the_response_reports_when_it_was_confirmed(): void
    {
        $attendee = Attendee::factory()->create(['is_verified' => false]);

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'is_verified' => true,
        ])->assertStatus(200)->assertJsonPath(
            'data.verified_at',
            fn (?string $value) => $value !== null,
        );
    }

    /**
     * A row reading "not verified, verified by Rahim on 3 May" is a lie, and
     * this table is read during reconciliation.
     */
    public function test_withdrawing_verification_clears_the_attribution(): void
    {
        $attendee = Attendee::factory()->create([
            'is_verified' => true,
            'verified_by_user_id' => $this->admin->id,
            'verified_at' => now()->subDay(),
        ]);

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'is_verified' => false,
        ])->assertStatus(200)->assertJsonPath('data.is_verified', false);

        $attendee->refresh();

        $this->assertFalse((bool) $attendee->is_verified);
        $this->assertNull($attendee->verified_by_user_id);
        $this->assertNull($attendee->verified_at);
    }

    /**
     * An edit that does not mention verification must not disturb it — an
     * admin adding a note to a verified attendee is not re-vouching for them,
     * and must not overwrite who did.
     */
    public function test_an_unrelated_edit_leaves_the_verification_alone(): void
    {
        $other = User::factory()->create(['status' => 'active']);
        $confirmedAt = now()->subWeek();

        $attendee = Attendee::factory()->create([
            'is_verified' => true,
            'verified_by_user_id' => $other->id,
            'verified_at' => $confirmedAt,
        ]);

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'notes' => 'Called about seating.',
        ])->assertStatus(200);

        $attendee->refresh();

        $this->assertTrue((bool) $attendee->is_verified);
        $this->assertSame($other->id, $attendee->verified_by_user_id);
        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $attendee->verified_at?->toDateTimeString(),
        );
    }

    /**
     * Re-sending the state it already holds is not a fresh confirmation, so
     * it must not restamp the date or move the attribution to whoever
     * happened to save the form.
     */
    public function test_re_saving_the_same_state_does_not_restamp_it(): void
    {
        $other = User::factory()->create(['status' => 'active']);
        $confirmedAt = now()->subWeek();

        $attendee = Attendee::factory()->create([
            'is_verified' => true,
            'verified_by_user_id' => $other->id,
            'verified_at' => $confirmedAt,
        ]);

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'is_verified' => true,
        ])->assertStatus(200);

        $attendee->refresh();

        $this->assertSame($other->id, $attendee->verified_by_user_id);
        $this->assertSame(
            $confirmedAt->toDateTimeString(),
            $attendee->verified_at?->toDateTimeString(),
        );
    }

    /**
     * It is an authority column: it must not be settable by any array that
     * happens to carry the key.
     */
    public function test_the_column_is_not_mass_assignable(): void
    {
        $attendee = new Attendee;
        $attendee->fill(['full_name' => 'Someone', 'is_verified' => true]);

        $this->assertNull($attendee->is_verified);
    }

    /**
     * The change lands in the audit trail through the endpoint's existing
     * before/after diff, which is what records the actor and their IP.
     */
    public function test_the_change_is_audited(): void
    {
        $attendee = Attendee::factory()->create(['is_verified' => false]);

        $this->putJson(route('api.v1.admin.attendees.update', ['attendee' => $attendee->ulid]), [
            'is_verified' => true,
        ])->assertStatus(200);

        $log = ActivityLog::query()
            ->where('log_name', 'attendee')
            ->where('subject_id', $attendee->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertFalse((bool) ($log->properties['old']['is_verified'] ?? null));
        $this->assertTrue((bool) ($log->properties['new']['is_verified'] ?? null));
    }
}
