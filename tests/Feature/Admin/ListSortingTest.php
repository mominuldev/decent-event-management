<?php

namespace Tests\Feature\Admin;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Registration\Support\AttendeeListFilters;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\ListSort;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ordering on the four admin data tables — attendees, registrations, payments
 * and tickets.
 *
 * Three separate properties are under test here, and they fail in different
 * ways:
 *
 *  - **Newest first by default.** Visible, and the thing an operator notices.
 *  - **Only allowlisted columns reach ORDER BY.** `orderBy()` does not bind its
 *    column argument, so this is the difference between a sortable table and an
 *    SQL injection. Asserted by handing the endpoint a payload that would drop
 *    a table and watching it answer the default page.
 *  - **Every ordering is total.** Invisible until it is not: MySQL may answer
 *    successive LIMIT/OFFSET pages inconsistently when the ORDER BY has ties,
 *    so rows repeat across pages and others never appear. Two of these four
 *    lists had no ORDER BY at all before, and a third had one without a
 *    tiebreaker, so this is a regression test for a live bug rather than a
 *    hypothetical one.
 */
class ListSortingTest extends TestCase
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

    /* ------------------------------------------------------------ helpers */

    /**
     * Attendees created oldest-first, so "newest first" is the reverse of
     * insertion order rather than accidentally matching it.
     *
     * @return list<string> full names, oldest first
     */
    private function seedAttendees(int $count = 3): array
    {
        $names = [];

        for ($i = 0; $i < $count; $i++) {
            $attendee = Attendee::factory()->create([
                'full_name' => 'Attendee '.chr(ord('C') - $i), // C, B, A — deliberately not creation order
                'created_at' => now()->subDays($count - $i),
            ]);
            $names[] = $attendee->full_name;
        }

        return $names;
    }

    private function ticketType(): TicketType
    {
        return TicketType::factory()->create();
    }

    /* ------------------------------------------- default order is "latest" */

    public function test_attendees_default_to_newest_first(): void
    {
        $this->seedAttendees();

        $names = collect($this->getJson(route('api.v1.admin.attendees.index'))
            ->assertOk()
            ->json('data'))
            ->pluck('full_name')
            ->all();

        $this->assertSame(['Attendee A', 'Attendee B', 'Attendee C'], $names);
    }

    public function test_registrations_default_to_newest_first(): void
    {
        $type = $this->ticketType();

        foreach ([3, 2, 1] as $daysAgo) {
            Registration::factory()->create([
                'attendee_id' => Attendee::factory()->create()->id,
                'ticket_type_id' => $type->id,
                'registration_number' => 'REG-'.$daysAgo,
                'created_at' => now()->subDays($daysAgo),
            ]);
        }

        $numbers = collect($this->getJson(route('api.v1.admin.registrations.index'))
            ->assertOk()
            ->json('data'))
            ->pluck('registration_number')
            ->all();

        $this->assertSame(['REG-1', 'REG-2', 'REG-3'], $numbers);
    }

    public function test_payments_default_to_newest_first(): void
    {
        $this->seedPayments();

        $numbers = collect($this->getJson(route('api.v1.admin.payments.index'))
            ->assertOk()
            ->json('data'))
            ->pluck('payment_number')
            ->all();

        $this->assertSame(['PAY-1', 'PAY-2', 'PAY-3'], $numbers);
    }

    public function test_tickets_default_to_newest_first(): void
    {
        $this->seedTickets();

        $numbers = collect($this->getJson(route('api.v1.admin.tickets.index'))
            ->assertOk()
            ->json('data'))
            ->pluck('ticket_number')
            ->all();

        $this->assertSame(['TKT-1', 'TKT-2', 'TKT-3'], $numbers);
    }

    /* --------------------------------------------------- explicit ordering */

    public function test_attendees_may_be_ordered_by_name_in_either_direction(): void
    {
        $this->seedAttendees();

        $ascending = collect($this->getJson(route('api.v1.admin.attendees.index', [
            'sort' => 'full_name',
            'direction' => 'asc',
        ]))->assertOk()->json('data'))->pluck('full_name')->all();

        $this->assertSame(['Attendee A', 'Attendee B', 'Attendee C'], $ascending);

        $descending = collect($this->getJson(route('api.v1.admin.attendees.index', [
            'sort' => 'full_name',
            'direction' => 'desc',
        ]))->assertOk()->json('data'))->pluck('full_name')->all();

        $this->assertSame(['Attendee C', 'Attendee B', 'Attendee A'], $descending);
    }

    public function test_payments_may_be_ordered_by_amount(): void
    {
        $this->seedPayments();

        $amounts = collect($this->getJson(route('api.v1.admin.payments.index', [
            'sort' => 'amount_paid_paisa',
            'direction' => 'asc',
        ]))->assertOk()->json('data'))->pluck('amount_paid_paisa')->all();

        $this->assertSame([100, 200, 300], $amounts);
    }

    public function test_tickets_may_be_ordered_by_holder_name(): void
    {
        $this->seedTickets();

        $holders = collect($this->getJson(route('api.v1.admin.tickets.index', [
            'sort' => 'holder_name',
            'direction' => 'asc',
        ]))->assertOk()->json('data'))->pluck('holder_name')->all();

        $this->assertSame(['Holder A', 'Holder B', 'Holder C'], $holders);
    }

    public function test_registrations_may_be_ordered_by_total(): void
    {
        $type = $this->ticketType();

        foreach ([300, 100, 200] as $i => $total) {
            Registration::factory()->create([
                'attendee_id' => Attendee::factory()->create()->id,
                'ticket_type_id' => $type->id,
                'registration_number' => 'REG-'.$i,
                'total_paisa' => $total,
            ]);
        }

        $totals = collect($this->getJson(route('api.v1.admin.registrations.index', [
            'sort' => 'total_paisa',
            'direction' => 'desc',
        ]))->assertOk()->json('data'))->pluck('total_paisa')->all();

        $this->assertSame([300, 200, 100], $totals);
    }

    /* ------------------------------------------------- allowlist behaviour */

    /**
     * A column name the allowlist does not know falls back to the default
     * column instead of raising. These lists are reached by bookmarked and
     * hand-edited URLs; answering the default page beats failing one.
     *
     * The direction is resolved independently, so a valid `asc` still applies
     * — the fallback replaces only the half of the request that was unusable.
     * Attendee C is the oldest of the three, so ascending puts it first.
     */
    public function test_an_unknown_sort_column_falls_back_to_the_default_column(): void
    {
        $this->seedAttendees();

        $names = collect($this->getJson(route('api.v1.admin.attendees.index', [
            'sort' => 'auth_token_hash', // a real column, deliberately not sortable
            'direction' => 'asc',
        ]))->assertOk()->json('data'))->pluck('full_name')->all();

        $this->assertSame(['Attendee C', 'Attendee B', 'Attendee A'], $names);
    }

    public function test_an_unknown_direction_falls_back_to_the_default(): void
    {
        $this->seedAttendees();

        $names = collect($this->getJson(route('api.v1.admin.attendees.index', [
            'sort' => 'full_name',
            'direction' => 'sideways',
        ]))->assertOk()->json('data'))->pluck('full_name')->all();

        // Default direction is desc, so the requested column still applies.
        $this->assertSame(['Attendee C', 'Attendee B', 'Attendee A'], $names);
    }

    /**
     * The allowlist is what stands between a query parameter and raw SQL —
     * `orderBy()` interpolates its column argument rather than binding it.
     */
    public function test_a_sort_parameter_cannot_inject_sql(): void
    {
        $this->seedAttendees();

        foreach ([
            'id) FROM attendees; DROP TABLE attendees;--',
            'full_name`, (SELECT 1)',
            '(CASE WHEN 1=1 THEN 1 ELSE 2 END)',
        ] as $payload) {
            $response = $this->getJson(route('api.v1.admin.attendees.index', [
                'sort' => $payload,
                'direction' => 'asc; DROP TABLE attendees;--',
            ]));

            $response->assertOk();
            $this->assertSame(
                ['Attendee A', 'Attendee B', 'Attendee C'],
                collect($response->json('data'))->pluck('full_name')->all(),
                "Payload should have fallen back to the default order: {$payload}",
            );
        }

        // Still standing.
        $this->assertSame(3, Attendee::count());
    }

    /* ------------------------------------------------- pagination is total */

    /**
     * The tiebreaker, asserted on the generated SQL rather than on observed
     * row order.
     *
     * That distinction matters: a MySQL that *may* answer tied LIMIT/OFFSET
     * pages inconsistently is not one that reliably *does*. Removing the
     * tiebreaker and re-running the paging test below leaves it green, because
     * at 30 rows the engine happens to walk the table in insertion order — so
     * that test cannot fail for the reason it exists, and on its own it would
     * be the exact docs/08 R12 trap of proving something adjacent to the
     * claim. This one pins the mechanism directly, and it is the real guard.
     */
    public function test_every_sort_ends_with_the_primary_key_so_the_order_is_total(): void
    {
        $simple = ['status' => 'status', 'method' => 'method', 'created_at' => 'created_at'];

        $cases = [
            [Attendee::query(), ['sort' => 'participant_type', 'direction' => 'asc'], AttendeeListFilters::SORTABLE, 'attendees', 'participant_type', 'asc'],
            [Attendee::query(), [], AttendeeListFilters::SORTABLE, 'attendees', 'created_at', 'desc'],
            [Registration::query(), ['sort' => 'status'], $simple, 'registrations', 'status', 'desc'],
            [Payment::query(), ['sort' => 'method', 'direction' => 'asc'], $simple, 'payments', 'method', 'asc'],
            [Ticket::query(), ['sort' => 'status'], $simple, 'tickets', 'status', 'desc'],
        ];

        foreach ($cases as [$query, $input, $sortable, $table, $column, $direction]) {
            $sql = ListSort::apply($query, $input, $sortable, 'created_at')->toSql();

            $this->assertStringContainsString(
                "order by `{$table}`.`{$column}` {$direction}, `{$table}`.`id` {$direction}",
                $sql,
                "The {$table} sort must fall back to the primary key, in the same direction.",
            );
        }
    }

    /**
     * An end-to-end check that paging a heavily-tied sort returns each row
     * exactly once. Weaker than it looks on its own — see the test above for
     * why — but it exercises the real HTTP path, which the SQL assertion does
     * not.
     */
    public function test_paging_a_sort_with_many_ties_neither_repeats_nor_skips_a_row(): void
    {
        $sharedTimestamp = now()->subDay();

        for ($i = 0; $i < 30; $i++) {
            Attendee::factory()->create([
                'full_name' => 'Tied Attendee',       // every name identical
                'participant_type' => 'former_student', // every type identical
                'created_at' => $sharedTimestamp,     // every timestamp identical
            ]);
        }

        $seen = [];

        foreach ([1, 2, 3] as $page) {
            $ulids = collect($this->getJson(route('api.v1.admin.attendees.index', [
                'sort' => 'participant_type',
                'direction' => 'asc',
                'per_page' => 10,
                'page' => $page,
            ]))->assertOk()->json('data'))->pluck('ulid')->all();

            $this->assertCount(10, $ulids, "Page {$page} should be full.");
            $seen = [...$seen, ...$ulids];
        }

        $this->assertCount(30, array_unique($seen), 'Every attendee should appear exactly once across the three pages.');
    }

    /* -------------------------------------------- the export follows suit */

    /**
     * The export and the list share AttendeeListFilters precisely so the file
     * cannot come back in a different order from the screen it was launched
     * from — the sort has to travel with the filters, not just alongside them.
     */
    public function test_the_export_records_the_sort_it_was_run_with(): void
    {
        $this->seedAttendees();

        $this->get(route('api.v1.admin.attendees.export', [
            'format' => 'xlsx',
            'sort' => 'full_name',
            'direction' => 'asc',
        ]))->assertOk();

        $log = ActivityLog::where('event', 'exported')->latest('id')->firstOrFail();

        $this->assertSame(
            ['field' => 'full_name', 'direction' => 'asc'],
            $log->properties['sort'] ?? null,
        );
    }

    public function test_the_export_falls_back_to_the_same_default_as_the_list(): void
    {
        $this->seedAttendees();

        $this->get(route('api.v1.admin.attendees.export', ['format' => 'xlsx']))->assertOk();

        $log = ActivityLog::where('event', 'exported')->latest('id')->firstOrFail();

        $this->assertSame(
            ['field' => 'created_at', 'direction' => 'desc'],
            $log->properties['sort'] ?? null,
        );
    }

    /* ----------------------------------------------------------- fixtures */

    private function seedPayments(): void
    {
        $type = $this->ticketType();

        foreach ([3, 2, 1] as $i => $daysAgo) {
            $attendee = Attendee::factory()->create();
            $registration = Registration::factory()->create([
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $type->id,
            ]);

            Payment::factory()->create([
                'registration_id' => $registration->id,
                'attendee_id' => $attendee->id,
                'payment_number' => 'PAY-'.$daysAgo,
                'amount_paid_paisa' => ($i + 1) * 100,
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    }

    private function seedTickets(): void
    {
        $type = $this->ticketType();

        foreach ([3, 2, 1] as $i => $daysAgo) {
            $attendee = Attendee::factory()->create();
            $registration = Registration::factory()->create([
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $type->id,
            ]);

            Ticket::factory()->create([
                'registration_id' => $registration->id,
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $type->id,
                'ticket_number' => 'TKT-'.$daysAgo,
                'holder_name' => 'Holder '.chr(ord('C') - $i),
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    }
}
