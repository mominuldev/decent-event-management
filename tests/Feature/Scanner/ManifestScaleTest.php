<?php

namespace Tests\Feature\Scanner;

use App\Domain\CheckIn\Models\CheckInDevice;
use App\Domain\CheckIn\Models\EventSession;
use App\Domain\CheckIn\Models\Gate;
use App\Domain\CheckIn\Models\VolunteerGateAssignment;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * docs/08 Phase 6 exit criterion: the manifest "correctly handles a
 * 12,000-ticket cold start". Previously unverified, and it did not — the
 * endpoint called ->get(), hydrating every ticket as an Eloquent model plus
 * a Resource object before writing a byte. Measured here before the fix:
 * 0.39s but **42 MB of PHP memory for a single request**, on a stock 128M
 * php-fpm worker. Time was never the problem; the memory was, because
 * docs/08's own gate rehearsal has 20+ devices cold-starting at once.
 */
class ManifestScaleTest extends TestCase
{
    use RefreshDatabase;

    private const TICKET_COUNT = 12000;

    /**
     * Comfortably under the ~42 MB the old ->get() implementation used, and
     * comfortably over the ~9 MB this costs now — of which most is the test
     * harness buffering the 3.4 MB body into a PHP string, something a real
     * socket write does not do. The point of the ceiling is to fail loudly
     * if anyone reintroduces a non-streaming fetch, not to pin an exact
     * figure that drifts with PHP versions.
     */
    private const PEAK_MEMORY_CEILING_BYTES = 20 * 1048576;

    private CheckInDevice $device;

    private Gate $gate;

    private EventSession $session;

    private string $plainTextToken;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->session = EventSession::factory()->create([
            'checkin_opens_at' => now()->subHour(),
            'checkin_closes_at' => now()->addHours(5),
            'is_active' => true,
        ]);

        $this->gate = Gate::factory()->create([
            'event_session_id' => $this->session->id,
            'is_active' => true,
        ]);

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('Volunteer');

        $profile = VolunteerProfile::factory()->create([
            'user_id' => $user->id,
            'is_active' => true,
        ]);

        VolunteerGateAssignment::create([
            'volunteer_profile_id' => $profile->id,
            'gate_id' => $this->gate->id,
            'event_session_id' => $this->session->id,
        ]);

        $token = $user->createToken('scanner-device', ['scanner']);
        $this->plainTextToken = $token->plainTextToken;

        $this->device = CheckInDevice::factory()->create([
            'assigned_volunteer_profile_id' => $profile->id,
            'sanctum_token_id' => $token->accessToken->id,
            'status' => 'active',
            'manifest_version' => 0,
        ]);
    }

    public function test_cold_start_streams_every_ticket_without_hydrating_them_all(): void
    {
        $this->seedTickets(self::TICKET_COUNT);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $start = microtime(true);

        $response = $this->fetch();

        // Draining the body is part of the work — a streamed response does
        // no querying or serialising until something reads it.
        $body = $this->bodyOf($response);

        $elapsed = microtime(true) - $start;
        $peakDelta = memory_get_peak_usage(true) - $before;

        $response->assertStatus(200);

        /** @var array{data: list<array<string, mixed>>, meta: array<string, mixed>} $decoded */
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

        fwrite(STDERR, sprintf(
            "\n  manifest cold start @ %d tickets: %.2fs, peak delta %.1f MB, payload %.2f MB\n",
            self::TICKET_COUNT,
            $elapsed,
            $peakDelta / 1048576,
            strlen($body) / 1048576,
        ));

        $this->assertCount(
            self::TICKET_COUNT,
            $decoded['data'],
            'Streaming must not truncate the manifest — every admissible ticket has to reach the gate.'
        );

        $this->assertLessThan(
            self::PEAK_MEMORY_CEILING_BYTES,
            $peakDelta,
            'The manifest is loading rows into memory rather than streaming them.'
        );
    }

    public function test_paginated_cold_start_walks_every_ticket_exactly_once(): void
    {
        $this->seedTickets(self::TICKET_COUNT);

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $response = $this->fetch(array_filter([
                'limit' => 2500,
                'after' => $cursor,
            ]));

            $response->assertStatus(200);
            /** @var array{data: list<array{ticket_ulid: string}>, meta: array{next_cursor: ?string}} $body */
            $body = json_decode($this->bodyOf($response), true, 512, JSON_THROW_ON_ERROR);

            foreach ($body['data'] as $entry) {
                $seen[] = $entry['ticket_ulid'];
            }

            $cursor = $body['meta']['next_cursor'];
            $pages++;

            $this->assertLessThan(20, $pages, 'Cursor pagination is not terminating.');
        } while ($cursor !== null);

        $this->assertSame(5, $pages);
        $this->assertCount(self::TICKET_COUNT, $seen);
        $this->assertCount(
            self::TICKET_COUNT,
            array_unique($seen),
            'A cursor page boundary is repeating or skipping tickets.'
        );
    }

    public function test_an_interrupted_stream_does_not_record_the_device_as_synced(): void
    {
        $this->seedTickets(50);

        // Response built, body never read — the shape of a connection that
        // dropped before the last row was written.
        $this->fetch();

        $this->assertSame(0, $this->device->refresh()->manifest_version);
        $this->assertNull($this->device->last_sync_at);

        // Same request, body fully drained.
        $this->bodyOf($this->fetch());

        $this->assertGreaterThan(0, $this->device->refresh()->manifest_version);
        $this->assertNotNull($this->device->last_sync_at);
    }

    public function test_a_partial_page_does_not_record_the_device_as_synced(): void
    {
        $this->seedTickets(50);

        $response = $this->fetch(['limit' => 10]);
        /** @var array{meta: array{next_cursor: ?string}} $body */
        $body = json_decode($this->bodyOf($response), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNotNull($body['meta']['next_cursor']);
        $this->assertSame(0, $this->device->refresh()->manifest_version, 'A device holding one page of five is not synced.');

        // Walk to the last page.
        $cursor = $body['meta']['next_cursor'];
        do {
            $body = json_decode(
                $this->bodyOf($this->fetch(['limit' => 10, 'after' => $cursor])),
                true, 512, JSON_THROW_ON_ERROR
            );
            $cursor = $body['meta']['next_cursor'];
        } while ($cursor !== null);

        $this->assertGreaterThan(0, $this->device->refresh()->manifest_version);
    }

    public function test_limit_is_capped_and_cursor_is_validated(): void
    {
        $this->seedTickets(5);

        $this->fetch(['limit' => 99999])->assertStatus(422);
        $this->fetch(['limit' => 0])->assertStatus(422);
        $this->fetch(['after' => 'too-short'])->assertStatus(422);
        $this->fetch(['since' => -1])->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return TestResponse<Response>
     */
    private function fetch(array $query = []): TestResponse
    {
        return $this->withToken($this->plainTextToken)
            ->withHeaders(['X-Gate-Id' => $this->gate->ulid])
            ->getJson(route('scanner.v1.manifest.show', $query));
    }

    /**
     * @param  TestResponse<Response>  $response
     */
    private function bodyOf(TestResponse $response): string
    {
        return $response->baseResponse instanceof StreamedJsonResponse
            || $response->baseResponse instanceof StreamedResponse
                ? (string) $response->streamedContent()
                : (string) $response->getContent();
    }

    private function seedTickets(int $count): void
    {
        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->create(['attendee_id' => $attendee->id]);

        $now = now()->toDateTimeString();
        $statuses = ['active', 'active', 'active', 'partially_admitted', 'fully_admitted'];

        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            $rows = [];

            foreach ($chunk as $i) {
                $rows[] = [
                    'ulid' => (string) Str::ulid(),
                    'ticket_number' => sprintf('DEC100-SCALE-%06d', $i),
                    'registration_id' => $registration->id,
                    'attendee_id' => $attendee->id,
                    'ticket_type_id' => $ticketType->id,
                    'event_session_id' => $this->session->id,
                    'status' => $statuses[$i % count($statuses)],
                    'admits_total' => 2,
                    'admitted_count' => 0,
                    'price_paid_paisa' => 250000,
                    'currency' => 'BDT',
                    'holder_name' => 'Scale Test Holder '.$i,
                    'holder_name_bn' => 'পরীক্ষা '.$i,
                    'holder_batch_year' => 1990 + ($i % 30),
                    'holder_type_label' => 'Alumnus',
                    'issued_at' => $now,
                    'manifest_version' => 1 + ($i % 50),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::table('tickets')->insert($rows);
        }
    }
}
