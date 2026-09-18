<?php

namespace Database\Seeders;

use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Sponsors page, block by block, on the same contract as
 * {@see HomePageSeeder}. Two sections: the shared `page_hero`, and a
 * `sponsor_grid` — which on this page renders every published sponsor from
 * the Sponsors tab, tier by tier, rather than its own rows.
 */
class SponsorsPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = $this->seedPage(
            ['slug' => 'sponsors'],
            [
                'template' => 'standard',
                'title' => 'Sponsors',
                'title_bn' => 'পৃষ্ঠপোষক',
                'excerpt' => 'The organizations proud to support the centennial celebration.',
                'excerpt_bn' => 'যাঁদের সহযোগিতায় এই শতবর্ষ উদযাপন।',
                'seo_title' => 'Sponsors',
                'seo_title_bn' => 'পৃষ্ঠপোষক',
                'seo_description' => 'The organizations proud to support the centennial celebration.',
                'seo_description_bn' => 'যাঁদের সহযোগিতায় এই শতবর্ষ উদযাপন।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 9,
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
                    'breadcrumb' => self::t('Sponsors', 'পৃষ্ঠপোষক'),
                    'eyebrow' => self::t('The organisations behind the day', 'যাঁদের সহযোগিতায় এই উৎসব'),
                    'heading_lead' => self::t('Our Partners and', 'সহযোগী ও'),
                    'heading_accent' => self::t('Sponsors', 'পৃষ্ঠপোষকেরা'),
                ],
            ],
            [
                'type' => 'sponsor_grid',
                'fields' => [
                    'heading' => '',
                    'tier' => '',
                ],
            ],
        ];
    }
}
