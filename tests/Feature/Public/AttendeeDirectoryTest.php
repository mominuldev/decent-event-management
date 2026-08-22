<?php

namespace Tests\Feature\Public;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Models\RegistrationGuest;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The public attendees directory at `GET /public/attendees`.
 *
 * Two things these tests exist to protect, both of which fail silently rather
 * than loudly if they regress:
 *
 *  1. **Only a succeeded registration is listed.** Anyone can POST a
 *     registration anonymously; it sits at `pending_payment` until money is
 *     verified. If the directory listed those, a stranger could put any name
 *     on the public site for free, and people who abandoned checkout would be
 *     published as attending.
 *  2. **The card carries no contact details.** The audience is the whole
 *     internet. A field added to the resource without thinking leaks a real
 *     person's phone number, address or family members' names.
 */
class AttendeeDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private ?TicketType $ticketType = null;

    /** One shared type per test — `ticket_types.code` is unique. */
    private function ticketType(): TicketType
    {
        return $this->ticketType ??= TicketType::factory()->create([
            'code' => 'CEN',
            'name' => 'Centennial Ticket',
            'name_bn' => 'শতবর্ষ টিকিট',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attendee
     * @param  array<string, mixed>  $registration
     */
    private function listedAttendee(array $attendee = [], array $registration = []): Registration
    {
        // The type is pinned rather than left to the factory because
        // AttendeeFactory clears `ssc_batch_year` for anyone who is not a
        // student — a fixture that leaves the type random silently loses the
        // batch year these tests filter and sort on.
        $attendee = array_merge(['participant_type' => 'former_student'], $attendee);

        return Registration::factory()
            ->for(Attendee::factory()->create($attendee))
            ->for($this->ticketType())
            ->create(array_merge(['status' => 'confirmed'], $registration));
    }

    public function test_it_lists_an_attendee_whose_registration_succeeded(): void
    {
        $this->listedAttendee([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => 'রহিম উদ্দিন',
            'participant_type' => 'former_student',
            'ssc_batch_year' => 1994,
            'designation' => 'Head of Engineering',
            'organization' => 'Beximco',
        ]);

        $response = $this->getJson('/api/v1/public/attendees');

        $response->assertOk()
            ->assertJsonPath('data.0.full_name', 'Rahim Uddin')
            ->assertJsonPath('data.0.full_name_bn', 'রহিম উদ্দিন')
            ->assertJsonPath('data.0.ssc_batch_year', 1994)
            ->assertJsonPath('data.0.organization', 'Beximco')
            ->assertJsonPath('data.0.ticket_type_name', 'Centennial Ticket')
            ->assertJsonCount(1, 'data');
    }

    /**
     * `paid` and `confirmed` are the two states where money has actually been
     * verified; everything else is either not yet paid or no longer coming.
     */
    public function test_it_lists_paid_as_well_as_confirmed(): void
    {
        $this->listedAttendee(['full_name' => 'Paid Person'], ['status' => 'paid']);
        $this->listedAttendee(['full_name' => 'Confirmed Person'], ['status' => 'confirmed']);

        $names = $this->getJson('/api/v1/public/attendees')->json('data.*.full_name');

        $this->assertEqualsCanonicalizing(['Paid Person', 'Confirmed Person'], $names);
    }

    /**
     * The load-bearing case. A registration is created by an anonymous caller
     * and only reaches `paid` through gateway verification — so anything
     * short of that must not appear, or the directory becomes a free
     * write-any-name-you-like billboard.
     */
    public function test_it_hides_every_registration_that_did_not_succeed(): void
    {
        foreach (['draft', 'pending_payment', 'cancelled', 'expired', 'refunded'] as $status) {
            $this->listedAttendee(['full_name' => "Not Listed {$status}"], ['status' => $status]);
        }

        $response = $this->getJson('/api/v1/public/attendees');

        $response->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(0, $response->json('meta.stats.total_registered'));
    }

    public function test_it_hides_a_soft_deleted_attendee_and_a_soft_deleted_registration(): void
    {
        $deletedAttendee = $this->listedAttendee(['full_name' => 'Deleted Person']);
        $deletedAttendee->attendee?->delete();

        $this->listedAttendee(['full_name' => 'Deleted Registration'])->delete();

        $this->listedAttendee(['full_name' => 'Still Here']);

        $response = $this->getJson('/api/v1/public/attendees');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Still Here');
        $this->assertSame(1, $response->json('meta.stats.total_registered'));
    }

    /**
     * The allowlist, asserted as an exact set rather than a handful of
     * `assertJsonMissing` calls — a new field added to the resource should
     * fail here and make someone decide whether the public may see it.
     */
    public function test_the_card_exposes_only_the_public_allowlist(): void
    {
        $registration = $this->listedAttendee([
            'mobile' => '+8801799887766',
            'email' => 'private@example.com',
            'father_name' => 'Abdul Karim',
            'current_address' => 'House 4, Road 2, Dhanmondi',
            'blood_group' => 'B+',
            'emergency_contact_phone' => '+8801700000000',
        ]);

        RegistrationGuest::factory()->for($registration)->create(['full_name' => 'Spouse Name']);

        $card = $this->getJson('/api/v1/public/attendees')->json('data.0');

        $this->assertSame([
            'address_district',
            'adults_count',
            'avatar_variant',
            'children_count',
            'country',
            'current_class',
            'designation',
            'full_name',
            'full_name_bn',
            'guests_count',
            'infants_count',
            'is_verified',
            'occupation',
            'organization',
            'participant_type',
            'participation_type',
            'profile_photo_url',
            'registered_at',
            'ssc_batch_year',
            'ticket_type_name',
            'ticket_type_name_bn',
            'ulid',
        ], collect(array_keys($card))->sort()->values()->all());

        // The guest was counted but never named.
        $this->assertSame(1, $card['guests_count']);
        $this->assertStringNotContainsString('Spouse Name', json_encode($card, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_it_filters_by_participant_type(): void
    {
        $this->listedAttendee(['full_name' => 'An Alumnus', 'participant_type' => 'former_student']);
        $this->listedAttendee(['full_name' => 'A Teacher', 'participant_type' => 'teacher']);

        $response = $this->getJson('/api/v1/public/attendees?participant_type=teacher');

        $response->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'A Teacher');
    }

    public function test_it_filters_by_exact_batch_year_and_by_a_decade_range(): void
    {
        $this->listedAttendee(['full_name' => 'Class of 1994', 'ssc_batch_year' => 1994]);
        $this->listedAttendee(['full_name' => 'Class of 1999', 'ssc_batch_year' => 1999]);
        $this->listedAttendee(['full_name' => 'Class of 2005', 'ssc_batch_year' => 2005]);

        $this->getJson('/api/v1/public/attendees?batch_year=1994')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Class of 1994');

        $nineties = $this->getJson('/api/v1/public/attendees?batch_from=1990&batch_to=1999')->json('data.*.full_name');

        $this->assertEqualsCanonicalizing(['Class of 1994', 'Class of 1999'], $nineties);
    }

    public function test_it_filters_by_whether_family_came_along(): void
    {
        $withFamily = $this->listedAttendee(['full_name' => 'Came With Family']);
        RegistrationGuest::factory()->for($withFamily)->create();

        $this->listedAttendee(['full_name' => 'Came Alone']);

        $this->getJson('/api/v1/public/attendees?has_guests=yes')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Came With Family');

        $this->getJson('/api/v1/public/attendees?has_guests=no')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Came Alone');
    }

    public function test_it_searches_name_organization_and_batch_year(): void
    {
        $this->listedAttendee(['full_name' => 'Rahim Uddin', 'organization' => 'Beximco', 'ssc_batch_year' => 1994]);
        $this->listedAttendee(['full_name' => 'Karim Mia', 'organization' => 'Grameenphone', 'ssc_batch_year' => 2001]);

        $this->getJson('/api/v1/public/attendees?search=Beximco')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Rahim Uddin');

        $this->getJson('/api/v1/public/attendees?search=Karim')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Karim Mia');

        // A bare year is matched against the batch column, which a LIKE on a
        // SMALLINT would not do.
        $this->getJson('/api/v1/public/attendees?search=1994')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.full_name', 'Rahim Uddin');
    }

    /**
     * `%` is a LIKE wildcard. Unescaped it matches everyone, which turns the
     * search box into a way to page through the whole roster while looking
     * like a filtered result.
     */
    public function test_a_wildcard_in_the_search_box_is_a_literal(): void
    {
        $this->listedAttendee(['full_name' => 'Rahim Uddin']);

        $this->getJson('/api/v1/public/attendees?search=%')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_it_sorts_by_batch_in_both_directions_and_puts_attendees_without_a_batch_last(): void
    {
        $this->listedAttendee(['full_name' => 'Older', 'ssc_batch_year' => 1975]);
        $this->listedAttendee(['full_name' => 'Newer', 'ssc_batch_year' => 2010]);
        $this->listedAttendee(['full_name' => 'No Batch', 'participant_type' => 'guest', 'ssc_batch_year' => null]);

        $this->assertSame(
            ['Older', 'Newer', 'No Batch'],
            $this->getJson('/api/v1/public/attendees?sort=batch_asc')->json('data.*.full_name'),
        );

        $this->assertSame(
            ['Newer', 'Older', 'No Batch'],
            $this->getJson('/api/v1/public/attendees?sort=batch_desc')->json('data.*.full_name'),
        );
    }

    public function test_an_unknown_sort_falls_back_to_the_default_instead_of_failing(): void
    {
        $this->listedAttendee(['full_name' => 'Older', 'ssc_batch_year' => 1975]);
        $this->listedAttendee(['full_name' => 'Newer', 'ssc_batch_year' => 2010]);

        $this->assertSame(
            ['Older', 'Newer'],
            $this->getJson('/api/v1/public/attendees?sort=drop%20table')->json('data.*.full_name'),
        );
    }

    public function test_it_sorts_by_name_and_by_most_recently_registered(): void
    {
        $this->listedAttendee(['full_name' => 'Zahir Ahmed'], ['created_at' => now()->subDays(2)]);
        $this->listedAttendee(['full_name' => 'Abdul Karim'], ['created_at' => now()->subDay()]);

        $this->assertSame(
            ['Abdul Karim', 'Zahir Ahmed'],
            $this->getJson('/api/v1/public/attendees?sort=name_asc')->json('data.*.full_name'),
        );

        $this->assertSame(
            ['Abdul Karim', 'Zahir Ahmed'],
            $this->getJson('/api/v1/public/attendees?sort=recent')->json('data.*.full_name'),
        );
    }

    public function test_it_paginates_and_caps_the_page_size(): void
    {
        Registration::factory()
            ->count(5)
            ->for($this->ticketType())
            ->sequence(fn ($sequence) => ['attendee_id' => Attendee::factory()->create()->id])
            ->create(['status' => 'confirmed']);

        $this->getJson('/api/v1/public/attendees?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);

        // A caller asking for the whole roster in one request gets the cap,
        // not the roster.
        $this->getJson('/api/v1/public/attendees?per_page=5000')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 48);
    }

    /**
     * Pagination must be a total order — a batch year is shared by everyone
     * in it, so ties are the norm here, not an edge case.
     */
    public function test_paging_through_a_tied_sort_never_repeats_or_skips_a_row(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->listedAttendee(['full_name' => "Batchmate {$i}", 'ssc_batch_year' => 1994]);
        }

        $seen = [];

        for ($page = 1; $page <= 3; $page++) {
            $seen = array_merge($seen, $this->getJson("/api/v1/public/attendees?per_page=3&page={$page}")->json('data.*.ulid'));
        }

        $this->assertCount(9, $seen);
        $this->assertCount(9, array_unique($seen));
    }

    /**
     * The header counters describe the whole directory, not the filtered
     * page — "1,240 registered" must not drop to 3 because someone typed a
     * name into the search box.
     */
    public function test_the_summary_counters_cover_the_whole_directory_not_the_filtered_page(): void
    {
        $this->listedAttendee(['full_name' => 'Alumnus One', 'participant_type' => 'former_student', 'ssc_batch_year' => 1994]);
        $this->listedAttendee(['full_name' => 'Alumnus Two', 'participant_type' => 'former_student', 'ssc_batch_year' => 1994]);
        $this->listedAttendee(['full_name' => 'Student', 'participant_type' => 'current_student', 'ssc_batch_year' => 2024]);
        $this->listedAttendee(['full_name' => 'Teacher', 'participant_type' => 'teacher', 'ssc_batch_year' => null]);
        $withGuests = $this->listedAttendee(['full_name' => 'Staff', 'participant_type' => 'staff', 'ssc_batch_year' => null]);
        RegistrationGuest::factory()->count(2)->for($withGuests)->create();

        $response = $this->getJson('/api/v1/public/attendees?search=Alumnus%20One');

        $response->assertOk()->assertJsonCount(1, 'data');

        $this->assertSame([
            'total_registered' => 5,
            'total_alumni' => 2,
            'total_students' => 1,
            'total_teachers_staff' => 2,
            'total_guests' => 2,
            'total_batches' => 2,
        ], $response->json('meta.stats'));

        // Only batch years someone actually holds, newest first — offering a
        // year with nobody behind it only leads to an empty result.
        $this->assertSame([2024, 1994], $response->json('meta.available_batches'));
    }

    /**
     * The card draws a gendered placeholder when there is no photo, but the
     * `gender` column itself stays private — what is published is a hint, and
     * anything that is not plainly male or female collapses to `neutral` so
     * the placeholder is never a guess about somebody.
     */
    public function test_the_avatar_hint_never_guesses_a_gender_and_never_leaks_the_column(): void
    {
        // A list of pairs, not a keyed map — `null` used as a PHP array key
        // silently becomes `''`, which would quietly drop the null case.
        $cases = [
            ['male', 'male'],
            ['female', 'female'],
            ['other', 'neutral'],
            // The one value whose entire meaning is "do not publish this".
            ['prefer_not_to_say', 'neutral'],
            [null, 'neutral'],
        ];

        foreach ($cases as [$stored, $expected]) {
            $this->listedAttendee([
                'full_name' => 'Gender '.($stored ?? 'null'),
                'gender' => $stored,
            ]);
        }

        $cards = collect($this->getJson('/api/v1/public/attendees?sort=recent&per_page=48')->json('data'))
            ->keyBy('full_name');

        $this->assertSame('male', $cards['Gender male']['avatar_variant']);
        $this->assertSame('female', $cards['Gender female']['avatar_variant']);
        $this->assertSame('neutral', $cards['Gender other']['avatar_variant']);
        $this->assertSame('neutral', $cards['Gender prefer_not_to_say']['avatar_variant']);
        $this->assertSame('neutral', $cards['Gender null']['avatar_variant']);

        // The hint is published; the column it was derived from is not.
        $this->assertArrayNotHasKey('gender', $cards['Gender male']);
    }

    public function test_it_publishes_the_badge_photo_as_a_signed_thumbnail_url(): void
    {
        Storage::fake('local');

        $thumbnail = MediaFile::factory()->create(['collection' => 'thumbnail', 'is_public' => false]);
        $photo = MediaFile::factory()->create(['collection' => 'profile_photo', 'is_public' => false]);
        $photo->forceFill(['thumbnail_media_id' => $thumbnail->id])->save();

        Storage::disk('local')->put($thumbnail->path, 'thumbnail-bytes');

        $this->listedAttendee(['full_name' => 'Has A Photo', 'profile_photo_media_id' => $photo->id]);

        $url = $this->getJson('/api/v1/public/attendees')->assertOk()->json('data.0.profile_photo_url');

        $this->assertIsString($url);
        // The thumbnail, never the ~1024px original the ticket PDF prints.
        $this->assertStringContainsString($thumbnail->ulid, $url);
        $this->assertStringNotContainsString($photo->ulid, $url);
        $this->assertStringContainsString('signature=', $url);

        // An anonymous caller can actually fetch it — the card is on a public
        // page, so a URL the browser cannot load is no better than none.
        $this->get($url)->assertOk();
    }

    public function test_an_attendee_without_a_photo_gets_null_rather_than_a_broken_url(): void
    {
        $this->listedAttendee(['full_name' => 'No Photo', 'profile_photo_media_id' => null]);

        $this->getJson('/api/v1/public/attendees')
            ->assertOk()
            ->assertJsonPath('data.0.profile_photo_url', null);
    }

    /**
     * The regression the bucketed expiry exists to prevent. A signed URL
     * stamped `now() + 15min` differs on every request, so the body — and
     * therefore the ETag — would change every second, silently killing the
     * 304 path and any shared cache in front of this endpoint.
     */
    public function test_the_photo_url_is_stable_between_requests_so_the_etag_still_matches(): void
    {
        $photo = MediaFile::factory()->create(['collection' => 'profile_photo', 'is_public' => false]);
        $this->listedAttendee(['full_name' => 'Has A Photo', 'profile_photo_media_id' => $photo->id]);

        $first = $this->getJson('/api/v1/public/attendees')->assertOk();

        $this->travel(30)->seconds();

        $second = $this->getJson('/api/v1/public/attendees')->assertOk();

        $this->assertSame(
            $first->json('data.0.profile_photo_url'),
            $second->json('data.0.profile_photo_url'),
        );

        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->getJson('/api/v1/public/attendees', ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_it_answers_304_when_the_caller_already_has_the_page(): void
    {
        $this->listedAttendee(['full_name' => 'Rahim Uddin']);

        $etag = $this->getJson('/api/v1/public/attendees')->assertOk()->headers->get('ETag');

        $this->assertNotNull($etag);

        $this->getJson('/api/v1/public/attendees', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }
}
