<?php

namespace Database\Seeders\Concerns;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;

/**
 * The authoring shape the bespoke page seeders share.
 *
 * A designed page (the homepage, the History page) is seeded from a list of
 * blocks written in one bilingual array rather than as two parallel English
 * and Bangla payloads — keeping the two halves key-for-key and row-for-row
 * aligned by hand is exactly the mistake this indirection exists to prevent,
 * and the public renderer's per-key fallback depends on that alignment.
 */
trait SeedsContentBlocks
{
    /**
     * Marks a value as differing per language. Anything not wrapped in this —
     * an image path, a link, an icon name, a tone key, an ASCII numeral the
     * renderer localises itself — is written identically to `data` and
     * `data_bn`, which is what keeps the two block payloads aligned.
     *
     * @return array{en: string, bn: string}
     */
    private static function t(string $en, string $bn): array
    {
        return ['en' => $en, 'bn' => $bn];
    }

    /**
     * Writes the page's blocks, in order, and drops any left over from a
     * longer previous run — a shorter page on a re-run must not leave orphaned
     * sections rendering below the last one this seeder wrote.
     *
     * Rows are keyed on (page, position), so re-running is safe.
     *
     * @param  list<array{type: string, fields: array<string, mixed>}>  $blocks
     */
    private function syncBlocks(ContentPage $page, array $blocks): void
    {
        foreach ($blocks as $position => $block) {
            [$data, $dataBn] = $this->split($block['fields']);

            ContentBlock::updateOrCreate(
                ['content_page_id' => $page->id, 'position' => $position],
                [
                    'type' => $block['type'],
                    'data' => $data,
                    'data_bn' => $dataBn,
                    'is_visible' => true,
                ]
            );
        }

        ContentBlock::where('content_page_id', $page->id)
            ->where('position', '>=', count($blocks))
            ->delete();
    }

    /**
     * Splits an authoring payload into the English and Bangla halves the
     * `content_blocks.data` / `data_bn` pair stores. Repeater rows recurse, so
     * a row's untranslatable keys land in both arrays at the same index.
     *
     * @param  array<string, mixed>  $fields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function split(array $fields): array
    {
        $en = [];
        $bn = [];

        foreach ($fields as $key => $value) {
            if (is_string($value)) {
                $en[$key] = $value;
                $bn[$key] = $value;

                continue;
            }

            if (is_array($value) && array_keys($value) === ['en', 'bn']) {
                $en[$key] = $value['en'];
                $bn[$key] = $value['bn'];

                continue;
            }

            // A repeater: a list of rows, each split the same way.
            if (is_array($value)) {
                $enRows = [];
                $bnRows = [];

                foreach ($value as $row) {
                    /** @var array<string, mixed> $row */
                    [$rowEn, $rowBn] = $this->split($row);
                    $enRows[] = $rowEn;
                    $bnRows[] = $rowBn;
                }

                $en[$key] = $enRows;
                $bn[$key] = $bnRows;
            }
        }

        return [$en, $bn];
    }
}
