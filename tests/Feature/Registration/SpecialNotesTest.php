<?php

namespace Tests\Feature\Registration;

use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `special_notes` is the registration's only free-text field.
 *
 * `registrations.comments` used to sit beside it carrying exactly the same
 * thing — same type, same nullability, same `max:1000`, accepted at every
 * layer that accepted `special_notes` and distinguished from it by nothing.
 * The public form wrote to one and the admin console showed both, so staff
 * had to read two boxes to be sure they had not missed a dietary need or an
 * accessibility request. It was merged into `special_notes` and dropped
 * 2026-08-21.
 *
 * These tests exist to stop it coming back — either as a column, or as a
 * request field quietly accepted again.
 */
class SpecialNotesTest extends TestCase
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
            'father_name' => 'Abdul Karim',
            'mobile' => '+8801712345678',
            'email' => 'rahim@example.com',
            'gender' => 'male',
            'occupation' => 'Civil Engineer',
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
    private function submit(array $payload): Registration
    {
        $response = $this->postJson(
            route('api.v1.public.registrations.store'),
            $payload,
            ['Idempotency-Key' => $payload['idempotency_key']],
        );

        $response->assertStatus(201);

        return Registration::where('ulid', $response->json('data.ulid'))->firstOrFail();
    }

    public function test_the_comments_column_no_longer_exists(): void
    {
        $this->assertFalse(Schema::hasColumn('registrations', 'comments'));
        $this->assertTrue(Schema::hasColumn('registrations', 'special_notes'));
    }

    public function test_the_public_form_stores_special_notes(): void
    {
        $registration = $this->submit($this->payload($this->ticketType(), [
            'special_notes' => 'My father is 82 and uses a wheelchair.',
        ]));

        $this->assertSame('My father is 82 and uses a wheelchair.', $registration->special_notes);
    }

    public function test_the_registration_response_exposes_special_notes_and_not_comments(): void
    {
        $registration = $this->submit($this->payload($this->ticketType(), [
            'special_notes' => 'Vegetarian meal, please.',
        ]));

        $this->getJson(route('api.v1.public.registrations.show', ['registration' => $registration->ulid]))
            ->assertStatus(200)
            ->assertJsonPath('data.special_notes', 'Vegetarian meal, please.')
            ->assertJsonMissingPath('data.comments');
    }

    /**
     * An old client — a cached build of the public site, say — may still
     * send `comments`. It must be ignored, not 500 on a column that is gone
     * and not silently swallow the note as though it had been recorded.
     */
    public function test_a_legacy_comments_key_is_ignored_rather_than_erroring(): void
    {
        $registration = $this->submit($this->payload($this->ticketType(), [
            'comments' => 'sent by an old client',
        ]));

        $this->assertNull($registration->special_notes);
        $this->assertArrayNotHasKey('comments', $registration->getAttributes());
    }

    public function test_an_admin_may_edit_special_notes(): void
    {
        $this->seed(RbacSeeder::class);

        /** @var User $admin */
        $admin = User::factory()->create();
        $admin->assignRole('Super Admin');
        Sanctum::actingAs($admin, ['admin'], 'web-admin');

        $registration = $this->submit($this->payload($this->ticketType()));

        $this->patchJson(
            route('api.v1.admin.registrations.update', ['registration' => $registration->ulid]),
            ['special_notes' => 'Called to confirm the wheelchair access.'],
        )->assertStatus(200)
            ->assertJsonPath('data.special_notes', 'Called to confirm the wheelchair access.');

        $this->assertSame('Called to confirm the wheelchair access.', $registration->refresh()->special_notes);
    }
}
