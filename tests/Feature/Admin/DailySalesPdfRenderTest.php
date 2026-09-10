<?php

namespace Tests\Feature\Admin;

use App\Domain\Payment\Models\Payment;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Reporting\Support\DailySalesReport;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Models\TicketType;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The daily sales PDF through **real** headless Chrome.
 *
 * {@see DailySalesExportTest} asserts the document by reading the HTML the
 * renderer was handed, which is fast and covers the content. It cannot cover
 * the one thing only a real render can: that Chrome lays this stylesheet out
 * and produces a readable page. Kept to a single case, deliberately — a
 * render costs ~2.5s of process startup, and the suite's flakiness in
 * September came from paying that in tests that had no need of it.
 *
 * Unlike the ticket and directory PDFs, nothing here is Bengali except the ৳
 * sign, so this is not a text-shaping test; it is a layout smoke test.
 */
class DailySalesPdfRenderTest extends TestCase
{
    use RefreshDatabase;

    protected bool $rendersRealPdfs = true;

    public function test_chrome_renders_a_readable_daily_sales_sheet(): void
    {
        $this->seed(RbacSeeder::class);

        $admin = User::factory()->create(['status' => 'active']);
        $admin->assignRole('Super Admin');
        Sanctum::actingAs($admin, ['admin'], 'web-admin');

        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create(['participant_type' => 'teacher']);
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'confirmed',
        ]);

        Payment::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'channel' => 'manual',
            'method' => 'cash',
            'status' => 'succeeded',
            'amount_due_paisa' => 250000,
            'amount_paid_paisa' => 250000,
            'paid_at' => CarbonImmutable::parse('2026-09-10 12:00', DailySalesReport::timezone())->utc(),
        ]);

        $pdf = $this->get(route('api.v1.admin.reports.daily-sales.export', [
            'format' => 'pdf',
            'from' => '2026-09-08',
            'to' => '2026-09-11',
        ]))->assertStatus(200)->getContent();

        $this->assertStringStartsWith('%PDF-', (string) $pdf);
        $this->assertGreaterThan(1000, strlen((string) $pdf), 'a real render, not an empty page');

        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed in this environment.');
        }

        $path = tempnam(sys_get_temp_dir(), 'ds').'.pdf';
        file_put_contents($path, $pdf);

        try {
            $text = (string) shell_exec('pdftotext -enc UTF-8 -layout '.escapeshellarg($path).' - 2>/dev/null');
        } finally {
            @unlink($path);
        }

        // Whitespace is normalised first: pdftotext derives word breaks from
        // glyph advances, so a figure can arrive split without a character of
        // it being lost.
        $normalised = (string) preg_replace('/\s+/u', ' ', $text);

        $this->assertStringContainsString('Daily Sales', $normalised);
        $this->assertStringContainsString('2026-09-08 to 2026-09-11', $normalised);
        $this->assertStringContainsString('2026-09-10', $normalised);
        // The ৳ is the one non-Latin glyph in this document; asserting an
        // amount proves the face actually carries it rather than dropping it.
        $this->assertStringContainsString('৳2,500.00', $normalised);
        // Uppercase: the tile labels carry `text-transform: uppercase`, and
        // that transform is baked into the glyphs Chrome writes, so it is
        // what comes back out of the text layer.
        $this->assertStringContainsString('OFFLINE', $normalised);
        // Read off a real render: a single payment must not say "1 payments".
        $this->assertStringContainsString('1 payment ', $normalised);
        $this->assertStringContainsString('By payment method', $normalised);
    }
}
