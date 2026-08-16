<?php

namespace Tests\Feature\Public;

use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\TicketTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The one centennial ticket the public page sells, the optional family on
 * it, and the free-infant rule that sits across pricing and admission.
 *
 * The rule these exist to protect: a child under the ticket type's
 * `child_free_under_age` is never billed and always admitted. Getting one
 * half right and the other wrong is the failure mode — either the family is
 * overcharged, or an infant is turned away at the gate.
 */
class CentennialTicketFlowTest extends TestCase
{
    use RefreshDatabase;

    private function seedCentennialTypes(): void
    {
        $this->seed(TicketTypeSeeder::class);
    }

    private function centennialType(): TicketType
    {
        return TicketType::where('code', 'CEN')->firstOrFail();
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
            'tshirt_required' => true,
            'tshirt_size' => 'L',
            'idempotency_key' => (string) Str::ulid(),
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function register(array $payload): Registration
    {
        $response = $this->postJson(
            route('api.v1.public.registrations.store'),
            $payload,
            ['Idempotency-Key' => $payload['idempotency_key']],
        );

        $response->assertStatus(201);

        return Registration::where('ulid', $response->json('data.ulid'))->firstOrFail();
    }

    public function test_registering_alone_costs_only_the_base_seat(): void
    {
        $this->seedCentennialTypes();

        $registration = $this->register($this->payload($this->centennialType()));

        $this->assertSame(250000, $registration->total_paisa);
        $this->assertSame(1, $registration->adults_count);
        $this->assertSame(0, $registration->children_count);
        $this->assertSame(0, $registration->infants_count);
        $this->assertSame('single', $registration->participation_type);
    }

    /**
     * There is one ticket and family is optional on it, so the same row has
     * to price a party of one and a party of five without the registrant
     * choosing a "kind" of ticket.
     */
    public function test_the_same_ticket_prices_a_party_of_one_and_a_family(): void
    {
        $this->seedCentennialTypes();
        $ticket = $this->centennialType();

        $alone = $this->register($this->payload($ticket));

        $family = $this->register($this->payload($ticket, [
            'mobile' => '+8801712000002',
            'email' => 'family@example.com',
            'participation_type' => 'family',
            'adults_count' => 2,
            'children_count' => 1,
            'guests' => [
                ['full_name' => 'Nusrat Jahan', 'relation' => 'spouse', 'age_group' => 'adult', 'gender' => 'female', 'tshirt_required' => true, 'tshirt_size' => 'M'],
                ['full_name' => 'Arif Uddin', 'relation' => 'child', 'age_group' => 'child', 'age' => 9, 'gender' => 'male', 'tshirt_required' => true, 'tshirt_size' => 'S'],
            ],
        ]));

        $this->assertSame($alone->ticket_type_id, $family->ticket_type_id, 'one ticket type serves both');

        // 250000 base + 200000 extra adult + 200000 extra child
        $this->assertSame(650000, $family->total_paisa);
    }

    /**
     * The registrant pays the base seat price; everyone they bring pays the
     * member rate for their age group.
     */
    public function test_added_members_are_charged_the_member_rate(): void
    {
        $this->seedCentennialTypes();
        $family = $this->centennialType();

        $registration = $this->register($this->payload($family, [
            'participation_type' => 'family',
            'adults_count' => 2,
            'children_count' => 2,
            'guests' => [
                ['full_name' => 'Nusrat Jahan', 'relation' => 'spouse', 'age_group' => 'adult', 'gender' => 'female', 'tshirt_required' => true, 'tshirt_size' => 'M'],
                ['full_name' => 'Arif Uddin', 'relation' => 'child', 'age_group' => 'child', 'age' => 9, 'gender' => 'male', 'tshirt_required' => true, 'tshirt_size' => 'S'],
                ['full_name' => 'Sadia Uddin', 'relation' => 'child', 'age_group' => 'child', 'age' => 6, 'gender' => 'female', 'tshirt_required' => true, 'tshirt_size' => 'XS'],
            ],
        ]));

        // 2,500 for the registrant + 3 × 2,000 for the members.
        $this->assertSame(850000, $registration->total_paisa);
        $this->assertSame(0, $registration->infants_count);
    }

    public function test_a_child_under_two_is_free_but_still_occupies_an_admit(): void
    {
        $this->seedCentennialTypes();
        $family = $this->centennialType();

        $registration = $this->register($this->payload($family, [
            'participation_type' => 'family',
            'adults_count' => 2,
            // Two children attending, one of them an infant.
            'children_count' => 2,
            'guests' => [
                ['full_name' => 'Nusrat Jahan', 'relation' => 'spouse', 'age_group' => 'adult', 'gender' => 'female', 'tshirt_required' => true, 'tshirt_size' => 'M'],
                ['full_name' => 'Arif Uddin', 'relation' => 'child', 'age_group' => 'child', 'age' => 9, 'gender' => 'male', 'tshirt_required' => true, 'tshirt_size' => 'S'],
                ['full_name' => 'Baby Uddin', 'relation' => 'child', 'age_group' => 'child', 'age' => 1, 'gender' => 'female', 'tshirt_required' => false],
            ],
        ]));

        // Billed for 3 of the 4 heads: 2,500 + 2,000 + 2,000, infant free.
        $this->assertSame(650000, $registration->total_paisa);
        $this->assertSame(1, $registration->infants_count);
        $this->assertSame(1, $registration->children_count, 'the infant is moved out of the billable child count');

        // …but all 4 walk through the gate. Issuance runs off a paid
        // registration, which is the state the payment path leaves it in.
        $registration->transitionTo('paid');
        $registration->save();

        $ticket = app(IssueTicket::class)->execute($registration->fresh(['attendee', 'ticketType']));

        $this->assertSame(4, $ticket->admits_total);
    }

    /**
     * A child who has had their second birthday is billed. The boundary is
     * "strictly under", and this is the case the ticket page's own FAQ
     * calls out, so it gets its own test.
     */
    public function test_a_child_of_exactly_two_is_billed(): void
    {
        $this->seedCentennialTypes();
        $family = $this->centennialType();

        $registration = $this->register($this->payload($family, [
            'participation_type' => 'family',
            'adults_count' => 1,
            'children_count' => 1,
            'guests' => [
                ['full_name' => 'Just Turned Two', 'relation' => 'child', 'age_group' => 'child', 'age' => 2, 'gender' => 'male', 'tshirt_required' => true, 'tshirt_size' => 'XS'],
            ],
        ]));

        $this->assertSame(450000, $registration->total_paisa);
        $this->assertSame(0, $registration->infants_count);
    }

    /**
     * The free rate is granted from the guests' own ages, never from a
     * client-supplied count — otherwise a caller could mint free admits by
     * declaring a party of infants.
     */
    public function test_free_infants_cannot_be_claimed_without_matching_guest_rows(): void
    {
        $this->seedCentennialTypes();
        $family = $this->centennialType();

        $registration = $this->register($this->payload($family, [
            'participation_type' => 'family',
            'adults_count' => 1,
            'children_count' => 3,
            'infants_count' => 3, // ignored — not part of the accepted input
            'guests' => [
                ['full_name' => 'Child One', 'relation' => 'child', 'age_group' => 'child', 'age' => 7, 'gender' => 'male', 'tshirt_required' => true, 'tshirt_size' => 'S'],
                ['full_name' => 'Child Two', 'relation' => 'child', 'age_group' => 'child', 'age' => 5, 'gender' => 'female', 'tshirt_required' => true, 'tshirt_size' => 'XS'],
                ['full_name' => 'Child Three', 'relation' => 'child', 'age_group' => 'child', 'age' => 4, 'gender' => 'male', 'tshirt_required' => true, 'tshirt_size' => 'XS'],
            ],
        ]));

        $this->assertSame(0, $registration->infants_count);
        $this->assertSame(850000, $registration->total_paisa);
    }

    /** A child guest sent with no age is billed, not waved through. */
    public function test_a_child_guest_without_an_age_is_billed(): void
    {
        $this->seedCentennialTypes();
        $family = $this->centennialType();

        $registration = $this->register($this->payload($family, [
            'participation_type' => 'family',
            'adults_count' => 1,
            'children_count' => 1,
            'guests' => [
                ['full_name' => 'Ageless Child', 'relation' => 'child', 'age_group' => 'child', 'gender' => 'male', 'tshirt_required' => true, 'tshirt_size' => 'XS'],
            ],
        ]));

        $this->assertSame(0, $registration->infants_count);
        $this->assertSame(450000, $registration->total_paisa);
    }

    /**
     * Every ticket type that predates the centennial pair has no
     * `child_free_under_age`, so its pricing must be byte-identical to
     * before this rule existed.
     */
    public function test_a_ticket_type_without_the_rule_bills_infants_as_before(): void
    {
        $ticketType = TicketType::factory()->create([
            'base_price_paisa' => 100000,
            'additional_adult_price_paisa' => 50000,
            'additional_child_price_paisa' => 25000,
            'base_admits' => 1,
            'max_admits' => 6,
            'child_free_under_age' => null,
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
        ]);

        $registration = $this->register($this->payload($ticketType, [
            'participation_type' => 'family',
            'adults_count' => 1,
            'children_count' => 1,
            'guests' => [
                ['full_name' => 'Baby', 'relation' => 'child', 'age_group' => 'child', 'age' => 1, 'gender' => 'female', 'tshirt_required' => false],
            ],
        ]));

        $this->assertSame(0, $registration->infants_count);
        $this->assertSame(125000, $registration->total_paisa);
        $this->assertSame(1, $registration->children_count);
    }

    public function test_ticket_type_api_publishes_the_free_infant_threshold(): void
    {
        $this->seedCentennialTypes();

        $response = $this->getJson(route('api.v1.public.ticket-types.index'));

        $response->assertStatus(200)
            ->assertJsonFragment(['code' => 'CEN', 'child_free_under_age' => 2]);
    }

    public function test_every_allowed_participant_type_may_buy_the_one_ticket(): void
    {
        $this->seedCentennialTypes();
        $ticket = $this->centennialType();

        $mobile = 8801712000100;

        foreach (['former_student', 'current_student', 'teacher', 'staff', 'guardian', 'other'] as $type) {
            $needsBatch = in_array($type, ['former_student', 'current_student'], true);

            // Six different people, so six different emails: an address
            // identifies one attendee, and sharing one here would refuse
            // every registration after the first for a reason that has
            // nothing to do with participant type.
            $registration = $this->register($this->payload($ticket, [
                'mobile' => '+'.$mobile++,
                'email' => "{$type}@example.com",
                'participant_type' => $type,
                'ssc_batch_year' => $needsBatch ? 2004 : null,
            ]));

            $this->assertSame(250000, $registration->total_paisa, "{$type} should pay the base seat");
        }
    }

    /**
     * `allowed_participant_types` was stored and published since Phase 2 but
     * never checked (D7). The public form now builds its dropdown from it,
     * which only means anything if the server refuses the rest.
     */
    public function test_a_ticket_refuses_a_participant_type_it_does_not_allow(): void
    {
        $this->seedCentennialTypes();
        $ticket = $this->centennialType();

        $before = $ticket->quantity_reserved;

        $response = $this->postJson(
            route('api.v1.public.registrations.store'),
            $this->payload($ticket, ['participant_type' => 'sponsor', 'ssc_batch_year' => null]),
            ['Idempotency-Key' => (string) Str::ulid()],
        );

        // Caller error, not a server fault — the form shows this message.
        $response->assertStatus(422)
            ->assertJsonFragment(['code' => 'participant_type_not_allowed']);

        $this->assertSame(0, Registration::count());
        $this->assertSame(
            $before,
            $ticket->fresh()->quantity_reserved,
            'a refused registration must not hold capacity'
        );
    }

    public function test_a_ticket_with_no_restriction_sells_to_anyone(): void
    {
        $ticketType = TicketType::factory()->create([
            'base_price_paisa' => 100000,
            'allowed_participant_types' => [],
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
        ]);

        $registration = $this->register($this->payload($ticketType, [
            'participant_type' => 'sponsor',
            'ssc_batch_year' => null,
        ]));

        $this->assertSame(100000, $registration->total_paisa);
    }

    public function test_photo_upload_stores_a_private_reencoded_image_and_links_it(): void
    {
        Storage::fake('local');
        $this->seedCentennialTypes();

        $registration = $this->register($this->payload($this->centennialType()));

        $response = $this->post(
            route('api.v1.public.registrations.photo.store', ['registration' => $registration->ulid]),
            ['photo' => UploadedFile::fake()->image('badge.jpg', 400, 400)],
        );

        $response->assertStatus(201)
            ->assertJsonStructure(['data' => ['ulid', 'url', 'width', 'height']]);

        $media = MediaFile::where('ulid', $response->json('data.ulid'))->firstOrFail();

        $this->assertFalse($media->is_public, 'a photograph of a person must never land on the public disk');
        $this->assertSame('local', $media->disk);
        $this->assertSame('profile_photo', $media->collection);
        Storage::disk('local')->assertExists($media->path);

        // The randomised stored name must not carry the uploaded filename.
        $this->assertStringNotContainsString('badge', $media->path);

        $this->assertSame(
            $media->id,
            $registration->fresh(['attendee'])->attendee?->profile_photo_media_id,
        );

        // Served through a signed route, never a guessable public path.
        $this->assertStringContainsString('signature=', (string) $response->json('data.url'));
    }

    public function test_photo_upload_rejects_a_non_image_disguised_as_one(): void
    {
        Storage::fake('local');
        $this->seedCentennialTypes();

        $registration = $this->register($this->payload($this->centennialType()));

        // A PHP script wearing a .jpg extension and an image content-type —
        // the exact case an extension check would wave through.
        $response = $this->post(
            route('api.v1.public.registrations.photo.store', ['registration' => $registration->ulid]),
            ['photo' => UploadedFile::fake()->createWithContent('badge.jpg', '<?php echo "pwned";')],
        );

        $response->assertStatus(422);
        $this->assertSame(0, MediaFile::where('collection', 'profile_photo')->count());
    }

    public function test_photo_upload_is_refused_once_the_registration_has_left_checkout(): void
    {
        Storage::fake('local');
        $this->seedCentennialTypes();

        $registration = $this->register($this->payload($this->centennialType()));
        $registration->transitionTo('paid');

        $response = $this->post(
            route('api.v1.public.registrations.photo.store', ['registration' => $registration->ulid]),
            ['photo' => UploadedFile::fake()->image('badge.jpg', 400, 400)],
        );

        $response->assertStatus(422)
            ->assertJsonFragment(['code' => 'photo_rejected']);
    }
}
