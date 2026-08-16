<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Services\GenerateMediaThumbnail;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Derives the small rendition for images stored before thumbnails existed.
 *
 * Scoped to `profile_photo` by default because that is the only collection
 * anything reads a thumbnail for today — generating them for every CMS image
 * would write rows and files that no code path fetches. Pass `--collection`
 * when a new consumer starts needing them.
 *
 * Safe to re-run: GenerateMediaThumbnail no-ops on media that already carries
 * a derivative, so an interrupted run resumes rather than duplicating. Rows
 * skipped for a missing file or an unreadable image are reported individually
 * — a silent count would turn "nothing left to do" and "nothing worked" into
 * the same output.
 */
class BackfillMediaThumbnails extends Command
{
    protected $signature = 'media:backfill-thumbnails
        {--collection=profile_photo : Media collection to process}
        {--chunk=100 : Rows loaded per batch}
        {--dry-run : Report what would be generated, write nothing}';

    protected $description = 'Generate thumbnails for existing images that have none';

    public function handle(GenerateMediaThumbnail $thumbnailer): int
    {
        $collection = (string) $this->option('collection');
        $chunk = max(1, (int) $this->option('chunk'));
        $dryRun = (bool) $this->option('dry-run');

        $query = MediaFile::query()
            ->where('collection', $collection)
            ->whereNull('thumbnail_media_id')
            ->orderBy('id');

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->info("Nothing to do — every '{$collection}' media file already has a thumbnail.");

            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry run] ' : '')."Processing {$total} '{$collection}' media file(s) without a thumbnail.");

        $generated = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // chunkById, not chunk: generating a thumbnail sets the very column
        // this query filters on, so an offset-paginated walk would skip a
        // row on every page boundary as the result set shrinks beneath it.
        $query->chunkById($chunk, function (Collection $batch) use (
            $bar, $dryRun, &$failed, &$generated, &$skipped, $thumbnailer
        ): void {
            /** @var MediaFile $media */
            foreach ($batch as $media) {
                $bar->advance();

                if ($dryRun) {
                    $generated++;

                    continue;
                }

                try {
                    if ($thumbnailer->execute($media) === null) {
                        $skipped++;
                        $this->newLine();
                        $this->line("  skipped {$media->ulid} — not an encodable image, already within the size budget, or its file is missing from the '{$media->disk}' disk");

                        continue;
                    }

                    $generated++;
                } catch (Throwable $e) {
                    $failed++;
                    $this->newLine();
                    $this->error("  failed {$media->ulid} — {$e->getMessage()}");
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        if ($dryRun) {
            $this->info("[dry run] {$generated} media file(s) would be examined. Nothing was written.");

            return self::SUCCESS;
        }

        $this->info("Generated {$generated}, skipped {$skipped}, failed {$failed}.");

        // A failure here is a broken stored file or a GD problem, not a
        // transient one — exit non-zero so a deploy step running this
        // doesn't report success over it.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
