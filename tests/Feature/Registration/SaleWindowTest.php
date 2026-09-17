<?php

namespace Tests\Feature\Registration;

use App\Domain\Registration\Support\RegistrationWindow;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\EventSettingCatalogue;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The organiser's registration window, and who it binds.
 *
 * The window is the event-wide `registration.opens_at` / `closes_at`
 * settings narrowed by a type's own sale dates ({@see RegistrationWindow}).
 * Two halves. The public catalogue *lists* a type ahead of its opening,
 * carrying `registration_opens_at`, so the tickets page can say when
 * registration opens instead of "nothing is on sale". And the public
 * checkout *refuses* to sell on it until then — the row being visible must
 * never mean the ulid is buyable. A counter sale is exempt: staff register
 * whoever the organiser puts in front of them.
 */
class SaleWindowTest extends TestCase
{
    use RefreshDatabase;

    /** A type with no sale dates of its own, so only the settings bind it. */
    private function openType(string $code = 'OPEN'): TicketType
    {
        return TicketType::factory()->create([
            'code' => $code,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => null,
            'sale_ends_at' => null,
        ]);
    }

    /** A type whose own `sale_starts_at` is three days out. */
    private function upcomingType(): TicketType
    {
        return TicketType::factory()->create([
            'code' => 'UPC',
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->addDays(3),
            'sale_ends_at' => null,
        ]);
    }

    private function setWindow(?Carbon $opensAt, ?Carbon $closesAt = null): void
    {
        foreach ([RegistrationWindow::OPENS_AT_KEY => $opensAt, RegistrationWindow::CLOSES_AT_KEY => $closesAt] as $key => $value) {
            $setting = EventSettingCatalogue::resolve($key);
            self::assertNotNull($setting);
            $setting->value = $setting->castForStorage($value?->toIso8601String());
            $setting->save();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(TicketType $ticketType, array $overrides = []): array
    {
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

    // ------------------------------------------------------------- catalogue

    public function test_the_public_catalogue_lists_a_type_before_registration_opens(): void
    {
        $opensAt = now()->addDays(2)->startOfMinute();
        $this->setWindow($opensAt);
        $this->openType();

        $this->getJson(route('api.v1.public.ticket-types.index'))
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'OPEN',
                'registration_opens_at' => $opensAt->toJSON(),
            ]);
    }

    public function test_the_public_catalogue_lists_a_type_before_its_own_sale_opens(): void
    {
        $this->setWindow(now()->subDay());
        $upcoming = $this->upcomingType();

        $this->getJson(route('api.v1.public.ticket-types.index'))
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'UPC',
                'sale_starts_at' => $upcoming->sale_starts_at?->toJSON(),
                'registration_opens_at' => $upcoming->sale_starts_at?->toJSON(),
            ]);
    }

    public function test_the_published_window_is_the_narrower_of_the_setting_and_the_type(): void
    {
        $opensAt = now()->addDays(5)->startOfMinute();
        $closesAt = now()->addDays(30)->startOfMinute();
        $this->setWindow($opensAt, $closesAt);
        // Opens sooner than the event and closes later: both bounds lose.
        TicketType::factory()->create([
            'code' => 'WIDE',
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->addDay(),
            'sale_ends_at' => now()->addDays(60),
        ]);

        $this->getJson(route('api.v1.public.ticket-types.index'))
            ->assertOk()
            ->assertJsonFragment([
                'code' => 'WIDE',
                'registration_opens_at' => $opensAt->toJSON(),
                'registration_closes_at' => $closesAt->toJSON(),
            ]);
    }

    public function test_the_public_catalogue_still_hides_ended_inactive_and_private_types(): void
    {
        $this->setWindow(now()->subDays(10));
        TicketType::factory()->create(['code' => 'END', 'is_active' => true, 'is_public' => true, 'sale_starts_at' => now()->subDays(9), 'sale_ends_at' => now()->subDay()]);
        TicketType::factory()->create(['code' => 'OFF', 'is_active' => false, 'is_public' => true, 'sale_starts_at' => now()->subDay()]);
        TicketType::factory()->create(['code' => 'PRV', 'is_active' => true, 'is_public' => false, 'sale_starts_at' => now()->subDay()]);

        $this->getJson(route('api.v1.public.ticket-types.index'))
            ->assertOk()
            ->assertJsonMissing(['code' => 'END'])
            ->assertJsonMissing(['code' => 'OFF'])
            ->assertJsonMissing(['code' => 'PRV']);
    }

    // -------------------------------------------------------------- checkout

    public function test_the_public_checkout_refuses_a_type_before_registration_opens(): void
    {
        $this->setWindow(now()->addDays(2));
        $type = $this->openType();

        $this->submit($this->payload($type))
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_on_sale')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'opens on'));

        $this->assertDatabaseCount('registrations', 0);
        $this->assertSame(0, (int) $type->fresh()?->quantity_reserved);
    }

    public function test_the_public_checkout_refuses_a_type_before_its_own_sale_opens(): void
    {
        $this->setWindow(now()->subDay());
        $upcoming = $this->upcomingType();

        $this->submit($this->payload($upcoming))
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_on_sale')
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'opens on'));

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_the_public_checkout_refuses_a_type_after_registration_closes(): void
    {
        $this->setWindow(now()->subDays(9), now()->subDay());
        $type = $this->openType();

        $this->submit($this->payload($type))
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_on_sale')
            ->assertJsonPath('message', 'Registration for this ticket has closed.');
    }

    public function test_the_public_checkout_refuses_a_type_after_its_own_sale_ends(): void
    {
        $this->setWindow(now()->subDays(9), now()->addMonth());
        $ended = TicketType::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDays(9),
            'sale_ends_at' => now()->subDay(),
        ]);

        $this->submit($this->payload($ended))
            ->assertStatus(422)
            ->assertJsonPath('code', 'not_on_sale')
            ->assertJsonPath('message', 'Registration for this ticket has closed.');
    }

    public function test_the_public_checkout_sells_once_registration_opens(): void
    {
        $this->setWindow(now()->addDays(2));
        $type = $this->openType();

        $this->travelTo(now()->addDays(2)->addMinute());

        $this->submit($this->payload($type))->assertStatus(201);
    }

    public function test_the_public_checkout_sells_with_the_settings_unset(): void
    {
        // The catalogue default: opens "now", closes months out. Nothing
        // saved on the settings screen must not mean nothing is buyable.
        $this->assertDatabaseMissing('event_settings', ['key' => RegistrationWindow::OPENS_AT_KEY]);
        $type = $this->openType();

        $this->submit($this->payload($type))->assertStatus(201);
    }

    public function test_a_counter_sale_is_not_held_to_the_window(): void
    {
        $this->seed(RbacSeeder::class);
        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('Super Admin');
        Sanctum::actingAs($admin, ['admin'], 'web-admin');

        $this->setWindow(now()->addDays(2));
        $type = $this->openType();

        $this->postJson(
            route('api.v1.admin.registrations.store'),
            $this->payload($type, ['mobile' => '01712345678']),
            ['Idempotency-Key' => (string) Str::ulid()],
        )->assertStatus(201);
    }

    // ---------------------------------------------------------------- window

    public function test_the_window_is_the_narrower_of_the_setting_and_the_type(): void
    {
        // Whole minutes: a setting is stored to the second.
        $opensAt = now()->addDays(5)->startOfMinute();
        $closesAt = now()->addDays(30)->startOfMinute();
        $this->setWindow($opensAt, $closesAt);

        $wide = TicketType::factory()->make(['sale_starts_at' => now()->addDay(), 'sale_ends_at' => now()->addDays(60)]);
        $this->assertTrue($opensAt->equalTo(RegistrationWindow::opensFor($wide)));
        $this->assertTrue($closesAt->equalTo(RegistrationWindow::closesFor($wide)));

        $narrow = TicketType::factory()->make(['sale_starts_at' => now()->addDays(10), 'sale_ends_at' => now()->addDays(20)]);
        $this->assertTrue($narrow->sale_starts_at?->equalTo(RegistrationWindow::opensFor($narrow)));
        $this->assertTrue($narrow->sale_ends_at?->equalTo(RegistrationWindow::closesFor($narrow)));

        $unbounded = TicketType::factory()->make(['sale_starts_at' => null, 'sale_ends_at' => null]);
        $this->assertTrue($opensAt->equalTo(RegistrationWindow::opensFor($unbounded)));
        $this->assertTrue($closesAt->equalTo(RegistrationWindow::closesFor($unbounded)));
        $this->assertFalse(RegistrationWindow::isOpenFor($unbounded));
    }
}
