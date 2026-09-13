<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Alumni page: the shared `page_hero`, and whatever generic blocks the
 * editors add beneath it. No public roster of registered attendees exists,
 * by design — attendee PII is never public (docs/00 Gap G3) — so this is a
 * curated showcase authored through the CMS, not a directory. It ships with
 * the hero and one placeholder paragraph for the editors to replace.
 */
class AlumniPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'alumni'],
            [
                'template' => 'standard',
                'title' => 'Alumni',
                'title_bn' => 'প্রাক্তন শিক্ষার্থী',
                'excerpt' => 'Celebrating the alumni of a century-old institution.',
                'excerpt_bn' => 'একশ বছরের প্রতিষ্ঠানের প্রাক্তন শিক্ষার্থীদের কথা।',
                'seo_title' => 'Alumni',
                'seo_title_bn' => 'প্রাক্তন শিক্ষার্থী',
                'seo_description' => 'Celebrating the alumni of a century-old institution.',
                'seo_description_bn' => 'একশ বছরের প্রতিষ্ঠানের প্রাক্তন শিক্ষার্থীদের কথা।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 10,
            ]
        );

        $this->syncBlocks($page, $this->blocks());
    }

    /**
     * @return list<array{type: string, fields: array<string, mixed>}>
     */
    private function blocks(): array
    {
        return [
            [
                'type' => 'page_hero',
                'fields' => [
                    'breadcrumb' => self::t('Alumni', 'প্রাক্তন শিক্ষার্থী'),
                    'eyebrow' => self::t('The people this school made', 'গৌরবময় প্রাক্তনদের কথা'),
                    'heading_lead' => self::t('A Century of', 'একশ বছরের'),
                    'heading_accent' => self::t('Alumni', 'প্রাক্তন শিক্ষার্থী'),
                ],
            ],
            [
                'type' => 'rich_text',
                'fields' => [
                    'heading' => self::t('Stories coming soon', 'গল্প আসছে শিগগিরই'),
                    'body' => self::t(
                        'Profiles of the alumni this school made — across a hundred years and every walk of life — are being gathered for the centennial.',
                        'একশ বছর ধরে এই বিদ্যালয় যাঁদের গড়েছে — জীবনের প্রতিটি ক্ষেত্রে — শতবর্ষ উপলক্ষে তাঁদের কথা সংগ্রহ করা হচ্ছে।',
                    ),
                ],
            ],
        ];
    }
}
