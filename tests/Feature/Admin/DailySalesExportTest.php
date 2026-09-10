<?php

namespace Tests\Feature\Admin;

use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Reporting\Support\DailySalesReport;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\HtmlToPdfRenderer;
use App\Domain\Ticketing\Models\TicketType;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\Support\FakePdfRenderer;
use Tests\TestCase;

/**
 * Downloading the daily sales report as CSV, Excel or PDF.
 *
 * The PDF is rendered through {@see FakePdfRenderer} here, so these cases
 * assert the *document* — the HTML Chrome was handed — without paying for a
 * layout pass. That the layout itself renders is covered separately by
 * {@see DailySalesPdfRenderTest}, which is the split the suite already uses.
 */
class DailySalesExportTest extends TestCase
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

        $this->ticketType = TicketType::factory()->create(['name' => 'Centennial Ticket']);
    }

    private function sale(
        string $paidAtLocal,
        string $channel = 'online',
        int $paisa = 250000,
        ?int $batchYear = null,
        ?TicketType $ticketType = null,
        string $method = 'paystation',
        string $status = 'succeeded',
    ): Payment {
        $attendee = Attendee::factory()->create($batchYear === null
            ? ['participant_type' => 'teacher']
            : ['participant_type' => 'former_student', 'ssc_batch_year' => $batchYear]);

        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => ($ticketType ?? $this->ticketType)->id,
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
    private function download(string $format, array $query = []): TestResponse
    {
        return $this->get(route('api.v1.admin.reports.daily-sales.export', $query + [
            'format' => $format,
            'from' => '2026-09-08',
            'to' => '2026-09-11',
        ]));
    }

    private function seedTwoDays(): void
    {
        $this->sale('2026-09-10 14:00', channel: 'online', paisa: 250000);
        $this->sale('2026-09-11 15:00', channel: 'manual', paisa: 400000, method: 'cash');
    }

    // ---------------------------------------------------------------- CSV

    public function test_the_csv_is_one_header_row_and_one_row_per_day(): void
    {
        $this->seedTwoDays();

        $response = $this->download('csv')->assertStatus(200);
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $rows = $this->csvRows($response->getContent());

        // 4 days in the window, header included, and no totals row.
        $this->assertCount(5, $rows);
        $this->assertSame('Date', $rows[0][0]);
        $this->assertSame(['2026-09-11', '2026-09-10', '2026-09-09', '2026-09-08'], array_column(array_slice($rows, 1), 0));
    }

    /**
     * A trailing "Total" line double-counts the moment anyone sums the column
     * or loads the file into anything, which is why it lives in the other two
     * formats and not this one.
     */
    public function test_the_csv_carries_no_totals_row_and_no_preamble(): void
    {
        $this->seedTwoDays();

        $body = $this->download('csv')->getContent();

        $this->assertStringNotContainsString('Total,', $body);
        $this->assertStringNotContainsString('Window', $body);
        // The header is the first line after the BOM, not the fourth.
        $this->assertStringStartsWith("\xEF\xBB\xBFDate,", $body);
    }

    public function test_csv_amounts_are_taka_with_two_decimals_not_paisa(): void
    {
        $this->seedTwoDays();

        $rows = $this->csvRows($this->download('csv')->getContent());
        $headings = $rows[0];

        $day = $this->csvRowFor($rows, '2026-09-10');

        $this->assertSame('2500.00', $day[array_search('Online amount (BDT)', $headings, true)]);
        $this->assertSame('0.00', $day[array_search('Offline amount (BDT)', $headings, true)]);
        $this->assertSame('2500.00', $day[array_search('Total amount (BDT)', $headings, true)]);
    }

    /**
     * A day of nothing but refunds has a negative net. Naive
     * intdiv/modulo formatting renders -250050 paisa as "-2500.-50".
     */
    public function test_a_negative_net_is_formatted_as_a_negative_amount(): void
    {
        $payment = $this->sale('2026-09-08 10:00', paisa: 250050, status: 'refunded');

        Refund::factory()->create([
            'payment_id' => $payment->id,
            'registration_id' => $payment->registration_id,
            'amount_paisa' => 250050,
            'status' => 'completed',
            'processed_at' => CarbonImmutable::parse('2026-09-11 10:00', DailySalesReport::timezone())->utc(),
        ]);

        $rows = $this->csvRows($this->download('csv')->getContent());
        $net = array_search('Net amount (BDT)', $rows[0], true);

        $this->assertSame('-2500.50', $this->csvRowFor($rows, '2026-09-11')[$net]);
        $this->assertSame('2500.50', $this->csvRowFor($rows, '2026-09-08')[$net]);
    }

    // --------------------------------------------------------------- XLSX

    public function test_the_workbook_carries_provenance_a_grid_and_a_totals_row(): void
    {
        $this->seedTwoDays();

        $response = $this->download('xlsx')->assertStatus(200);
        $sheet = $this->firstSheet($response->getContent());

        $this->assertStringContainsString('Daily sales', (string) $sheet->getCell('A1')->getValue());
        $this->assertSame('Window', $sheet->getCell('A2')->getValue());
        $this->assertSame('2026-09-08 to 2026-09-11', $sheet->getCell('B2')->getValue());
        // Named explicitly: the same payment falls on a different date under
        // a different timezone, so a sheet that omits it cannot be reconciled.
        $this->assertSame('Midnight Asia/Dhaka', $sheet->getCell('B3')->getValue());

        // Raw values, not `toArray()`'s default: that applies the number
        // format and would hand back '2,500.00', hiding whether the cell is
        // really a number or a string that merely looks like one.
        $values = $sheet->toArray(null, true, false);
        $total = null;

        foreach ($values as $row) {
            if (($row[0] ?? null) === 'Total') {
                $total = $row;
            }
        }

        $this->assertNotNull($total, 'the workbook carries a totals row');
        $this->assertSame(2500.0, $total[2], 'online amount');
        $this->assertSame(4000.0, $total[5], 'offline amount');
        $this->assertSame(6500.0, $total[8], 'total amount');
    }

    /**
     * A pre-formatted "৳2,500.00" is text to a spreadsheet, and every total
     * an operator builds on it comes out as zero with no error.
     */
    public function test_money_cells_are_numbers_so_they_can_be_summed(): void
    {
        $this->seedTwoDays();

        $sheet = $this->firstSheet($this->download('xlsx')->getContent());

        foreach ($sheet->toArray(null, true, false) as $i => $row) {
            if (($row[0] ?? null) === '2026-09-10') {
                $this->assertIsFloat($row[2], 'a stored string would SUM() to zero with no error');
                $this->assertSame(2500.0, $row[2]);

                // The taka rendering is the cell's number format, which is
                // what keeps the value summable and the display readable.
                $this->assertSame(
                    '#,##0.00',
                    $sheet->getStyle('C'.($i + 1))->getNumberFormat()->getFormatCode(),
                );

                return;
            }
        }

        $this->fail('the 10th was not in the grid');
    }

    public function test_the_workbook_has_a_second_sheet_breaking_takings_down_by_method(): void
    {
        $this->seedTwoDays();

        $spreadsheet = $this->readWorkbook($this->download('xlsx')->getContent());

        $this->assertSame(['Daily sales', 'By method'], $spreadsheet->getSheetNames());
        // Opens on the day grid, not the sheet that was added last.
        $this->assertSame(0, $spreadsheet->getActiveSheetIndex());

        $rows = $spreadsheet->getSheetByName('By method')?->toArray(null, true, false) ?? [];

        $this->assertSame(['Method', 'Channel', 'Online / offline', 'Payments', 'Amount'], array_slice($rows[0], 0, 5));
        // Largest first: cash 4,000 ahead of paystation 2,500.
        $this->assertSame(['cash', 'manual', 'offline', 1, 4000.0], array_slice($rows[1], 0, 5));
        $this->assertSame(['paystation', 'online', 'online', 1, 2500.0], array_slice($rows[2], 0, 5));
    }

    // ---------------------------------------------------------------- PDF

    public function test_the_pdf_is_a_pdf_and_names_the_window_and_the_totals(): void
    {
        $this->seedTwoDays();

        $response = $this->download('pdf')->assertStatus(200);
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());

        $html = $this->renderedHtml();

        $this->assertStringContainsString('2026-09-08 to 2026-09-11', $html);
        $this->assertStringContainsString('midnight Asia/Dhaka', $html);
        $this->assertStringContainsString('৳6,500.00', $html, 'the total taken');
        $this->assertStringContainsString('৳2,500.00', $html, 'the online half');
        $this->assertStringContainsString('৳4,000.00', $html, 'the offline half');
    }

    /**
     * Paper has no "show empty days" control, so the cut is final — and the
     * header has to say it was made, or the reader cannot tell a quiet window
     * from a truncated report.
     */
    public function test_the_pdf_omits_days_with_no_activity_and_says_how_many(): void
    {
        $this->seedTwoDays();

        $this->download('pdf')->assertStatus(200);
        $html = $this->renderedHtml();

        $this->assertStringContainsString('2 with no sales omitted', $html);
        $this->assertStringNotContainsString('2026-09-09', $html);
        // The totals still cover the whole window; the omitted days simply
        // contribute nothing to it.
        $this->assertStringContainsString('৳6,500.00', $html);
    }

    public function test_the_pdf_names_the_ticket_type_rather_than_its_ulid(): void
    {
        $this->seedTwoDays();

        $this->download('pdf', ['ticket_type_ulid' => $this->ticketType->ulid])->assertStatus(200);
        $html = $this->renderedHtml();

        $this->assertStringContainsString('Ticket type: Centennial Ticket', $html);
        $this->assertStringNotContainsString($this->ticketType->ulid, $html);
    }

    // ----------------------------------------------------- shared contract

    /**
     * The export builds from the same DailySalesReport call the screen makes,
     * so the two cannot disagree — this pins that the filters really reach it.
     */
    public function test_the_export_applies_the_same_filters_as_the_screen(): void
    {
        $this->sale('2026-09-10 10:00', paisa: 100000, batchYear: 1995);
        $this->sale('2026-09-10 11:00', paisa: 200000, batchYear: 2005);

        $rows = $this->csvRows($this->download('csv', ['ssc_batch_year' => 1995])->getContent());
        $total = array_search('Total amount (BDT)', $rows[0], true);

        $this->assertSame('1000.00', $this->csvRowFor($rows, '2026-09-10')[$total]);
    }

    public function test_the_filename_carries_the_window_and_the_generation_time(): void
    {
        $disposition = (string) $this->download('csv')->headers->get('content-disposition');

        $this->assertMatchesRegularExpression(
            '/filename="daily-sales-2026-09-08_2026-09-11-\d{8}-\d{6}\.csv"/',
            $disposition,
        );
    }

    public function test_every_format_is_downloaded_as_an_attachment_and_never_cached(): void
    {
        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $response = $this->download($format)->assertStatus(200);

            $this->assertStringStartsWith('attachment;', (string) $response->headers->get('content-disposition'), $format);
            // Laravel normalises the header, so assert the directive that
            // matters rather than the exact string it happens to emit.
            $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'), $format);
            $this->assertSame('nosniff', $response->headers->get('x-content-type-options'), $format);
        }
    }

    /**
     * An export takes every day's takings out of the system in one file, which
     * CLAUDE.md names among the acts that must reach `activity_logs`. Written
     * from the action (D8), so a console caller cannot skip it.
     */
    public function test_an_export_is_audited_with_the_window_and_the_totals(): void
    {
        $this->seedTwoDays();

        $this->download('xlsx', ['ssc_batch_year' => ''])->assertStatus(200);

        $log = ActivityLog::query()->where('log_name', 'report')->where('event', 'exported')->sole();

        $this->assertSame($this->admin->id, $log->causer_id);
        $this->assertSame('daily_sales', $log->properties['report_key']);
        $this->assertSame('xlsx', $log->properties['format']);
        $this->assertSame('2026-09-08', $log->properties['from']);
        $this->assertSame('2026-09-11', $log->properties['to']);
        $this->assertSame('Asia/Dhaka', $log->properties['timezone']);
        $this->assertSame(650000, $log->properties['total_paisa']);
        $this->assertSame(250000, $log->properties['online_paisa']);
        $this->assertSame(400000, $log->properties['offline_paisa']);
    }

    // ------------------------------------------------------- authorization

    /**
     * The format permission governs taking a file out; the revenue permission
     * governs seeing these numbers at all. Holding only the former must not
     * become a way to read what the screen hides.
     */
    public function test_the_format_permission_alone_is_not_enough_without_the_revenue_one(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('report.export_csv');
        Sanctum::actingAs($user, ['admin'], 'web-admin');

        $this->assertTrue($user->can('report.export_csv'));
        $this->assertFalse($user->can('report.view_revenue'));

        $this->download('csv')->assertStatus(403);
    }

    public function test_the_revenue_permission_alone_is_not_enough_without_the_format_one(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo('report.view_revenue');
        Sanctum::actingAs($user, ['admin'], 'web-admin');

        $this->download('pdf')->assertStatus(403);
    }

    public function test_an_event_manager_holds_both_and_may_download_every_format(): void
    {
        $manager = User::factory()->create(['status' => 'active']);
        $manager->assignRole('Event Manager');
        Sanctum::actingAs($manager, ['admin'], 'web-admin');

        foreach (['csv', 'xlsx', 'pdf'] as $format) {
            $this->download($format)->assertStatus(200);
        }
    }

    // --------------------------------------------------------- validation

    public function test_the_format_is_required_and_allowlisted(): void
    {
        $this->getJson(route('api.v1.admin.reports.daily-sales.export'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');

        $this->getJson(route('api.v1.admin.reports.daily-sales.export', ['format' => 'docx']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    /**
     * The export request extends the screen's own, so the window ceiling and
     * the date rules cannot drift between them.
     */
    public function test_it_inherits_the_reports_own_window_rules(): void
    {
        $this->getJson(route('api.v1.admin.reports.daily-sales.export', [
            'format' => 'csv', 'from' => '2020-01-01', 'to' => '2026-01-01',
        ]))->assertStatus(422)->assertJsonValidationErrors('to');

        $this->getJson(route('api.v1.admin.reports.daily-sales.export', [
            'format' => 'csv', 'from' => '2026-09-10', 'to' => '2026-09-01',
        ]))->assertStatus(422)->assertJsonValidationErrors('to');
    }

    // ------------------------------------------------------------ helpers

    /**
     * @return list<list<string>>
     */
    private function csvRows(string $body): array
    {
        $body = preg_replace('/^\xEF\xBB\xBF/', '', $body) ?? $body;

        $handle = fopen('php://temp', 'r+');
        self::assertIsResource($handle);
        fwrite($handle, $body);
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = array_map(static fn ($cell): string => (string) $cell, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<list<string>>  $rows
     * @return list<string>
     */
    private function csvRowFor(array $rows, string $date): array
    {
        foreach (array_slice($rows, 1) as $row) {
            if ($row[0] === $date) {
                return $row;
            }
        }

        $this->fail("no CSV row for {$date}");
    }

    private function readWorkbook(string $body): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'ds').'.xlsx';
        file_put_contents($path, $body);

        try {
            return (new XlsxReader)->load($path);
        } finally {
            @unlink($path);
        }
    }

    private function firstSheet(string $body): Worksheet
    {
        return $this->readWorkbook($body)->getSheet(0);
    }

    /** The HTML the (faked) renderer was handed for the most recent render. */
    private function renderedHtml(): string
    {
        $renderer = $this->app->make(HtmlToPdfRenderer::class);

        $this->assertInstanceOf(FakePdfRenderer::class, $renderer);
        $this->assertNotEmpty($renderer->rendered, 'no PDF was rendered');

        return (string) end($renderer->rendered);
    }
}
