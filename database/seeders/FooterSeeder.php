<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The site footer, block by block, on the same contract as
 * {@see HomePageSeeder}: every value is the copy the public site ships with.
 *
 * The `footer` slug is not a routed page. The public site reads its blocks
 * into the footer under every marketing route, so an editor changes the
 * motto, the link columns or the credit here and it lands site-wide. The
 * helpline, email, address and social links are *not* blocks — they are the
 * `contact.*` event settings, so the Settings screen stays the one place
 * those are changed.
 *
 * Columns are one `footer_links` block each so a column can be added or
 * removed like any other block. The page is unindexable by construction.
 */
class FooterSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'footer'],
            [
                'template' => 'standard',
                'title' => 'Site footer',
                'title_bn' => 'সাইট ফুটার',
                'excerpt' => 'The motto, link columns and credit shown under every page.',
                'excerpt_bn' => 'প্রতিটি পাতার নিচে দেখানো মূলমন্ত্র, লিংক কলাম ও ক্রেডিট।',
                'seo_title' => null,
                'seo_title_bn' => null,
                'seo_description' => null,
                'seo_description_bn' => null,
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => false,
                'position' => 11,
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
            $this->identity(),
            $this->column('Our Heritage', 'আমাদের ঐতিহ্য', [
                ['/history', 'Our History', 'আমাদের ইতিহাস'],
                ['/history', 'The Centennial Story', 'শতবর্ষের গল্প'],
                ['/history', "Headmaster's Message", 'প্রধান শিক্ষকের বাণী'],
                ['/souvenir', 'Souvenir Book', 'শতবর্ষ স্মরণিকা'],
            ]),
            $this->column('Event & Festival', 'অনুষ্ঠান ও উৎসব', [
                ['/event', 'Programme Schedule', 'অনুষ্ঠানের সূচি'],
                ['/attendees', 'Attendees Directory', 'অংশগ্রহণকারী তালিকা'],
                ['/tickets', 'Tickets & Registration', 'টিকিট ও নিবন্ধন'],
                ['/gallery', 'Photo Gallery', 'স্মৃতির গ্যালারি'],
            ]),
            $this->column('Resources', 'সহায়িকা ও তথ্য', [
                ['/faq', 'FAQ', 'সাধারণ জিজ্ঞাসা (FAQ)'],
                ['/contact', 'Contact Info', 'যোগাযোগের ঠিকানা'],
                ['/history', 'School Milestones', 'বিদ্যালয়ের অর্জন'],
                ['/tickets', 'Registration Guide', 'নিবন্ধন নির্দেশিকা'],
            ]),
            $this->credit(),
        ];
    }

    /**
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function identity(): array
    {
        return [
            'type' => 'footer_identity',
            'fields' => [
                'tagline' => self::t('A centennial celebration, our heritage.', 'শতবর্ষ উৎসব, আমাদের ঐতিহ্য'),
                'description' => self::t(
                    '1927 to 2027. A century of learning in Chapainawabganj, and a warm welcome to everyone joining us for the hundredth year.',
                    '১৯২৭ সাল থেকে ২০২৭। এক শতাব্দী ধরে আলো ছড়ানো এক ঐতিহ্যবাহী বিদ্যাপীঠ। শতবর্ষ পূর্তির এই মাহেন্দ্রক্ষণে সকলকে জানাই উষ্ণ অভ্যর্থনা।',
                ),
            ],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $links  href, English label, Bangla label
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function column(string $titleEn, string $titleBn, array $links): array
    {
        return [
            'type' => 'footer_links',
            'fields' => [
                'title' => self::t($titleEn, $titleBn),
                'links' => array_map(
                    static fn (array $link): array => [
                        'label' => self::t($link[1], $link[2]),
                        'href' => $link[0],
                    ],
                    $links,
                ),
            ],
        ];
    }

    /**
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function credit(): array
    {
        return [
            'type' => 'footer_credit',
            'fields' => [
                'label' => self::t('Developed by', 'ডেভেলপ করেছেন'),
                'name' => self::t('Mominul Islam', 'মমিনুল ইসলাম'),
                'url' => '',
            ],
        ];
    }
}
