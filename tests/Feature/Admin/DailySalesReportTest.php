<?php

namespace Tests\Feature\Admin;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Reporting\Support\DailySalesReport;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The daily takings report: one row per day, split into online (gateway) and
 * offline (counter cash and any other manually-settled payment) sales, with
 * date, SSC batch and ticket-type filters.
 *
 * The cases worth reading first are the ones that pin decisions rather than
 * plumbing: a refunded payment still counting on the day it was sold, the
 * day boundary being local midnight rather than UTC's, and both filters
 * applying to refunds as well as to sales.
 */
class DailySalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private TicketType $ticketType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['status' => 'active']);
        $this->admin->assignRole('Super Admin');
        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');

        $this->ticketType = TicketType::factory()->create();
    }

    /**
     * A paid payment on a given local date.
     */
    private function sale(
        string $paidAtLocal,
        string $channel = 'online',
        int $paisa = 250000,
        ?int $batchYear = null,
        ?TicketType $ticketType = null,
        int $adults = 1,
        int $children = 0,
        int $infants = 0,
        string $method = 'paystation',
        string $status = 'succeeded',
    ): Payment {
        // The factory clears `ssc_batch_year` for anyone who is not a
        // student, so the type has to match or the filter fixture is a lie.
        $attendee = Attendee::factory()->create($batchYear === null
            ? ['participant_type' => 'teacher']
            : ['participant_type' => 'former_student', 'ssc_batch_year' => $batchYear]);

        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => ($ticketType ?? $this->ticketType)->id,
            'adults_count' => $adults,
            'children_count' => $children,
            'infants_count' => $infants,
            'status' => 'confirmed',
        ]);

        return Payment::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'channel' => $channel,
            'method' => $method,
            'status' => $status,
            'amount_due_paisa' => $paisa,
            'amount_paid_paisa' => $paisa,
            'paid_at' => CarbonImmutable::parse($paidAtLocal, DailySalesReport::timezone())->utc(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function report(array $query = []): TestResponse
    {
        return $this->getJson(route('api.v1.admin.reports.daily-sales', $query));
    }

    /**
     * @param  array<int, array<string, mixed>>  $days
     * @return array<string, mixed>|null
     */
    private function day(array $days, string $date): ?array
    {
        foreach ($days as $row) {
            if ($row['date'] === $date) {
                return $row;
            }
        }

        return null;
    }

    public function test_a_gateway_sale_is_online_and_a_cash_sale_is_offline(): void
    {
        $this->sale('2026-09-10 14:00', channel: 'online', paisa: 250000);
        $this->sale('2026-09-10 15:00', channel: 'manual', paisa: 400000, method: 'cash');

        $data = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10'])
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(1, $data['totals']['online_payments']);
        $this->assertSame(250000, $data['totals']['online_paisa']);
        $this->assertSame(1, $data['totals']['offline_payments']);
        $this->assertSame(400000, $data['totals']['offline_paisa']);
        $this->assertSame(650000, $data['totals']['total_paisa']);
    }

    /**
     * Anything that is not the gateway channel counts as offline rather than
     * being dropped. Money taken through a channel nobody anticipated must
     * still appear in the totals.
     */
    public function test_an_unrecognised_channel_counts_as_offline_rather_than_vanishing(): void
    {
        $this->sale('2026-09-10 12:00', channel: 'kiosk', paisa: 111100, method: 'voucher');

        $data = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10'])->json('data');

        $this->assertSame(111100, $data['totals']['offline_paisa']);
        $this->assertSame(111100, $data['totals']['total_paisa']);
    }

    /**
     * The decision this report turns on. A refund is a later, separately
     * dated event; if `refunded` payments were excluded from the collected
     * set, a day closed and reconciled on Monday would report a different
     * number on Friday.
     */
    public function test_a_later_refund_does_not_erase_the_sale_from_the_day_it_was_made(): void
    {
        $payment = $this->sale('2026-09-01 10:00', paisa: 250000, status: 'refunded');

        Refund::factory()->create([
            'payment_id' => $payment->id,
            'registration_id' => $payment->registration_id,
            'amount_paisa' => 250000,
            'status' => 'completed',
            'processed_at' => CarbonImmutable::parse('2026-09-08 11:00', DailySalesReport::timezone())->utc(),
        ]);

        $days = $this->report(['from' => '2026-09-01', 'to' => '2026-09-08'])->json('data.days');

        $sold = $this->day($days, '2026-09-01');
        $this->assertSame(250000, $sold['total_paisa'], 'the sale stays on the day it was made');
        $this->assertSame(0, $sold['refunded_paisa']);
        $this->assertSame(250000, $sold['net_paisa']);

        $refunded = $this->day($days, '2026-09-08');
        $this->assertSame(0, $refunded['total_paisa']);
        $this->assertSame(250000, $refunded['refunded_paisa']);
        $this->assertSame(-250000, $refunded['net_paisa'], 'a refund-only day is negative, not zero');
    }

    /**
     * The whole reason `report.timezone` exists. Stored UTC, a sale at 9pm
     * Dhaka is 3pm UTC the same day — but one at 1am Dhaka is 7pm UTC the
     * day before, and grouping on the raw column would bank it on the wrong
     * date.
     */
    public function test_the_day_ends_at_local_midnight_not_utc_midnight(): void
    {
        // 2026-09-11 01:00 Asia/Dhaka === 2026-09-10 19:00 UTC.
        $this->sale('2026-09-11 01:00', paisa: 100000);
        // 2026-09-10 23:30 Asia/Dhaka === 2026-09-10 17:30 UTC.
        $this->sale('2026-09-10 23:30', paisa: 200000);

        $days = $this->report(['from' => '2026-09-10', 'to' => '2026-09-11'])->json('data.days');

        $this->assertSame(200000, $this->day($days, '2026-09-10')['total_paisa']);
        $this->assertSame(100000, $this->day($days, '2026-09-11')['total_paisa']);
    }

    public function test_the_timezone_comes_from_the_setting(): void
    {
        $this->assertSame('Asia/Dhaka', DailySalesReport::timezone());

        EventSetting::query()->create([
            'key' => 'report.timezone',
            'group' => 'report',
            'type' => 'string',
            'value' => 'UTC',
            'label' => 'Reporting day boundary timezone',
        ]);

        $this->assertSame('UTC', DailySalesReport::timezone());

        $this->assertSame('UTC', $this->report(['from' => '2026-09-10', 'to' => '2026-09-10'])
            ->json('data.filters.timezone'));
    }

    /**
     * A meaningless timezone must not take the whole report down with an
     * exception — it falls back rather than 500ing on a bad setting value.
     */
    public function test_an_unusable_timezone_setting_falls_back(): void
    {
        EventSetting::query()->create([
            'key' => 'report.timezone',
            'group' => 'report',
            'type' => 'string',
            'value' => 'Middle/Earth',
            'label' => 'Reporting day boundary timezone',
        ]);

        $this->assertSame('Asia/Dhaka', DailySalesReport::timezone());
        $this->report()->assertStatus(200);
    }

    public function test_days_outside_the_window_are_excluded_and_empty_days_are_filled_in(): void
    {
        $this->sale('2026-09-10 12:00', paisa: 250000);
        $this->sale('2026-09-20 12:00', paisa: 999900);

        $data = $this->report(['from' => '2026-09-08', 'to' => '2026-09-12'])->json('data');

        $this->assertCount(5, $data['days'], 'every date in the window, gaps included');
        $this->assertSame(250000, $data['totals']['total_paisa'], 'the 20th is outside the window');
        $this->assertSame(0, $this->day($data['days'], '2026-09-09')['total_paisa']);

        // Newest first, matching every other admin list.
        $this->assertSame('2026-09-12', $data['days'][0]['date']);
        $this->assertSame('2026-09-08', $data['days'][4]['date']);
    }

    public function test_the_window_boundaries_are_inclusive_at_both_ends(): void
    {
        // The first and last instants of the window in local time.
        $this->sale('2026-09-08 00:00:00', paisa: 100000);
        $this->sale('2026-09-10 23:59:59', paisa: 200000);
        $this->sale('2026-09-07 23:59:59', paisa: 400000);
        $this->sale('2026-09-11 00:00:00', paisa: 800000);

        $data = $this->report(['from' => '2026-09-08', 'to' => '2026-09-10'])->json('data');

        $this->assertSame(300000, $data['totals']['total_paisa']);
    }

    public function test_it_filters_by_batch_year(): void
    {
        $this->sale('2026-09-10 10:00', paisa: 100000, batchYear: 1995);
        $this->sale('2026-09-10 11:00', paisa: 200000, batchYear: 2005);
        $this->sale('2026-09-10 12:00', paisa: 400000, batchYear: null);

        $data = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10', 'ssc_batch_year' => 1995])->json('data');

        $this->assertSame(100000, $data['totals']['total_paisa']);
        $this->assertSame(1995, $data['filters']['ssc_batch_year']);
    }

    public function test_it_filters_by_ticket_type_ulid(): void
    {
        $other = TicketType::factory()->create();

        $this->sale('2026-09-10 10:00', paisa: 100000);
        $this->sale('2026-09-10 11:00', paisa: 200000, ticketType: $other);

        $data = $this->report([
            'from' => '2026-09-10',
            'to' => '2026-09-10',
            'ticket_type_ulid' => $other->ulid,
        ])->json('data');

        $this->assertSame(200000, $data['totals']['total_paisa']);
        $this->assertSame($other->ulid, $data['filters']['ticket_type_ulid']);
    }

    /**
     * A filtered net figure that subtracted everybody else's refunds would be
     * nonsense, so both filters apply to the refund query too.
     */
    public function test_the_filters_apply_to_refunds_as_well_as_to_sales(): void
    {
        $mine = $this->sale('2026-09-10 10:00', paisa: 100000, batchYear: 1995, status: 'refunded');
        $theirs = $this->sale('2026-09-10 11:00', paisa: 200000, batchYear: 2005, status: 'refunded');

        foreach ([$mine, $theirs] as $payment) {
            Refund::factory()->create([
                'payment_id' => $payment->id,
                'registration_id' => $payment->registration_id,
                'amount_paisa' => 50000,
                'status' => 'completed',
                'processed_at' => CarbonImmutable::parse('2026-09-10 12:00', DailySalesReport::timezone())->utc(),
            ]);
        }

        $data = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10', 'ssc_batch_year' => 1995])->json('data');

        $this->assertSame(1, $data['totals']['refund_count']);
        $this->assertSame(50000, $data['totals']['refunded_paisa']);
        $this->assertSame(50000, $data['totals']['net_paisa']);
    }

    public function test_only_paid_payments_count(): void
    {
        $this->sale('2026-09-10 10:00', paisa: 100000);

        // Never paid: pending, initiated, failed, expired.
        foreach (['pending', 'initiated', 'failed', 'expired'] as $status) {
            $attendee = Attendee::factory()->create();
            $registration = Registration::factory()->create([
                'attendee_id' => $attendee->id,
                'ticket_type_id' => $this->ticketType->id,
            ]);

            Payment::factory()->create([
                'registration_id' => $registration->id,
                'attendee_id' => $attendee->id,
                'status' => $status,
                'amount_due_paisa' => 900000,
                'amount_paid_paisa' => 900000,
                'paid_at' => CarbonImmutable::parse('2026-09-10 10:00', DailySalesReport::timezone())->utc(),
            ]);
        }

        $data = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10'])->json('data');

        $this->assertSame(100000, $data['totals']['total_paisa']);
        $this->assertSame(1, $data['totals']['total_payments']);
    }

    public function test_a_soft_deleted_registration_is_not_a_sale(): void
    {
        $payment = $this->sale('2026-09-10 10:00', paisa: 100000);
        $this->sale('2026-09-10 11:00', paisa: 200000);

        Registration::query()->whereKey($payment->registration_id)->delete();

        $data = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10'])->json('data');

        $this->assertSame(200000, $data['totals']['total_paisa']);
    }

    /**
     * Head count includes free infants — they pay nothing but still walk
     * through the gate, which is exactly the number a daily report is for.
     */
    public function test_person_counts_include_free_infants(): void
    {
        $this->sale('2026-09-10 10:00', adults: 2, children: 1, infants: 1);

        $data = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10'])->json('data');

        $this->assertSame(4, $data['totals']['online_persons']);
        $this->assertSame(4, $data['totals']['total_persons']);
    }

    public function test_the_method_breakdown_separates_cash_from_other_offline_money(): void
    {
        $this->sale('2026-09-10 10:00', channel: 'manual', paisa: 300000, method: 'cash');
        $this->sale('2026-09-10 11:00', channel: 'manual', paisa: 100000, method: 'bkash');
        $this->sale('2026-09-10 12:00', channel: 'online', paisa: 200000, method: 'paystation');

        $methods = $this->report(['from' => '2026-09-10', 'to' => '2026-09-10'])->json('data.methods');

        $this->assertSame(['cash', 'paystation', 'bkash'], array_column($methods, 'method'), 'largest first');
        $this->assertSame(['offline', 'online', 'offline'], array_column($methods, 'kind'));
        $this->assertSame(300000, $methods[0]['paisa']);
    }

    public function test_it_defaults_to_the_last_thirty_days_ending_today(): void
    {
        $today = CarbonImmutable::now(DailySalesReport::timezone())->startOfDay();

        $data = $this->report()->assertStatus(200)->json('data');

        $this->assertCount(DailySalesReport::DEFAULT_DAYS, $data['days']);
        $this->assertSame($today->toDateString(), $data['filters']['to']);
        $this->assertSame($today->subDays(DailySalesReport::DEFAULT_DAYS - 1)->toDateString(), $data['filters']['from']);
    }

    public function test_an_end_date_before_the_start_date_is_refused(): void
    {
        $this->report(['from' => '2026-09-10', 'to' => '2026-09-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    public function test_a_window_longer_than_the_ceiling_is_refused_naming_both_numbers(): void
    {
        $response = $this->report(['from' => '2020-01-01', 'to' => '2026-01-01'])->assertStatus(422);

        $message = $response->json('errors.to.0');
        $this->assertStringContainsString((string) DailySalesReport::MAX_DAYS, $message);
        $this->assertStringContainsString('2193 days', $message);
    }

    public function test_an_unknown_ticket_type_is_a_field_error_not_an_empty_report(): void
    {
        $this->report(['ticket_type_ulid' => str_repeat('0', 26)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ticket_type_ulid');
    }

    public function test_an_event_manager_may_read_it(): void
    {
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('Event Manager');
        Sanctum::actingAs($manager, ['admin'], 'web-admin');

        $this->assertTrue($manager->can('report.view_revenue'));
        $this->report()->assertStatus(200);
    }

    public function test_a_staff_member_without_the_revenue_permission_is_refused(): void
    {
        $volunteer = User::factory()->create(['status' => 'active']);
        $volunteer->assignRole('Volunteer');
        Sanctum::actingAs($volunteer, ['admin'], 'web-admin');

        $this->assertFalse($volunteer->can('report.view_revenue'));
        $this->report()->assertStatus(403);
    }
}
