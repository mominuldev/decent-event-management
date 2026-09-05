<?php

namespace Tests\Feature\Admin;

use App\Domain\Registration\Actions\ExportAttendees;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Services\HtmlToPdfRenderer;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Not a correctness test — a measurement harness for the ceilings recorded in
 * config/exports.php, kept in the suite so those numbers can be re-derived
 * rather than trusted. Skipped unless EXPORT_BENCHMARK=1.
 */
class PdfExportBenchmarkTest extends TestCase
{
    // DatabaseMigrations, not RefreshDatabase: the export holds its
    // transaction open for the whole render, and a minutes-long wrapping
    // transaction deadlocks against the audit-log write at the end.
    use DatabaseMigrations;

    /** Reads the rendered bytes, so this one really does need Chrome. */
    protected bool $rendersRealPdfs = true;

    public function test_measure_pdf_export(): void
    {
        if (env('EXPORT_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set EXPORT_BENCHMARK=1 to run the export benchmark.');
        }

        $this->seed(RbacSeeder::class);
        Storage::fake('local');

        $user = User::factory()->create(['status' => 'active']);
        $user->assignRole('Super Admin');

        foreach ([(int) (env('EXPORT_BENCHMARK_ROWS') ?: 250)] as $count) {
            $this->seedAttendees($count);

            gc_collect_cycles();
            memory_reset_peak_usage();
            $before = memory_get_usage(true);
            $start = microtime(true);

            $file = app(ExportAttendees::class)->execute([], 'pdf', $user);

            $elapsed = microtime(true) - $start;
            $renderOnly = $this->timeRenderOnly($count);
            $peak = memory_get_peak_usage(true) - $before;

            fwrite(STDERR, sprintf(
                "\n  pdf, %d entries — %.1fs total (%.1fs chrome, %.1fs photo prep + query), ~%dMB peak, %.1fMB file\n",
                $count,
                $elapsed,
                $renderOnly,
                $elapsed - $renderOnly,
                (int) round($peak / 1048576),
                strlen($file->contents) / 1048576,
            ));

            Attendee::query()->forceDelete();
        }

        $this->assertTrue(true);
    }

    /**
     * Chrome's share alone, across sizes — no fixture photos, so nothing but
     * the rendering engine is being timed.
     */
    public function test_measure_render_only(): void
    {
        if (env('EXPORT_BENCHMARK') !== '1') {
            $this->markTestSkipped('Set EXPORT_BENCHMARK=1 to run the export benchmark.');
        }

        // One throwaway render first: the first Chrome launch in a process
        // pays for process start and font loading, and folding that into the
        // first data point makes it look like a per-entry cost when it is not.
        $cold = $this->timeRenderOnly(10);
        fwrite(STDERR, sprintf("\n  (cold start, 10 entries — %.1fs)\n", $cold));

        foreach ([250, 500, 1000] as $count) {
            $seconds = $this->timeRenderOnly($count);
            fwrite(STDERR, sprintf("\n  chrome render only, %d entries — %.1fs (%.0f ms/entry)\n", $count, $seconds, $seconds * 1000 / $count));
        }

        $this->assertTrue(true);
    }

    /** Chrome's share of the wall clock, measured on an equivalent document. */
    private function timeRenderOnly(int $count): float
    {
        $renderer = app(HtmlToPdfRenderer::class);

        $rows = array_chunk(array_fill(0, $count, [
            'photo' => null,
            'name' => 'বেঞ্চ ব্যক্তি',
            'father_name' => 'Abdul Karim',
            'occupation' => 'Engineer',
            'address' => 'Head Teacher, Baraipara High School, Pirgachi, Alampur',
            'mobile' => '+8801711223344',
        ]), 3);

        $html = view('exports.attendees', [
            'eventName' => 'Bench', 'generatedAt' => 'now', 'appliedFilters' => [],
            'total' => $count, 'rows' => $rows, 'columns' => 3, 'cellWidth' => '33.3333%',
            'fontFaceCss' => $renderer->fontFaceCss(), 'title' => 'Bench',
            'pageSize' => 'A4 landscape', 'pageMargin' => '12mm 10mm 14mm 10mm',
        ])->render();

        $start = microtime(true);
        $renderer->render($html);

        return microtime(true) - $start;
    }

    private function seedAttendees(int $count): void
    {
        // Adversarial by design, matching what config/exports.php records:
        // every entry carries a full-size photograph of incompressible noise.
        $photo = $this->noiseJpeg(900, 1200);

        for ($i = 0; $i < $count; $i++) {
            $path = "photos/bench-{$i}.jpg";
            Storage::disk('local')->put($path, $photo);

            $media = MediaFile::create([
                'collection' => 'profile_photo',
                'disk' => 'local',
                'path' => $path,
                'original_name' => 'bench.jpg',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'size_bytes' => strlen($photo),
                'checksum_sha256' => hash('sha256', $photo),
                'width' => 900,
                'height' => 1200,
                'is_public' => false,
                'scan_status' => 'clean',
                'uploaded_by_type' => 'system',
            ]);

            Attendee::factory()->create([
                'full_name' => "Bench Person {$i}",
                'full_name_bn' => 'বেঞ্চ ব্যক্তি '.$i,
                'profile_photo_media_id' => $media->id,
                'mobile' => '+8801900'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'email' => "bench{$i}@example.test",
            ]);
        }
    }

    private function noiseJpeg(int $w, int $h): string
    {
        $img = imagecreatetruecolor($w, $h);

        for ($x = 0; $x < $w; $x += 2) {
            for ($y = 0; $y < $h; $y += 2) {
                imagefilledrectangle($img, $x, $y, $x + 1, $y + 1, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
            }
        }

        ob_start();
        imagejpeg($img, null, 90);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
