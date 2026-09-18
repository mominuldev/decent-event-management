<?php

namespace Database\Seeders\Concerns;

/**
 * The one switch {@see SeedsContentBlocks} reads to decide whether a run
 * replaces or only adds.
 *
 * A class of its own rather than a static on the trait: PHP gives every
 * class that uses a trait its *own* copy of the trait's static properties,
 * so a flag set on the trait is invisible to `TicketsPageSeeder::$flag` —
 * the first cut did exactly that and ran every seeder in full.
 */
final class ContentSeedMode
{
    /**
     * When true, `syncBlocks()` only *adds* — a field the seeder knows and
     * the stored block lacks is written, everything already stored is left
     * exactly as it is, and no block is deleted. Set by
     * `content:backfill-seeded-fields`, so a block type that gains fields
     * after a page has been edited can pick them up on a live database
     * without the seeder's normal re-run replacing every edit on the page.
     */
    public static bool $fillMissingOnly = false;
}
