<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The History page, block by block — the same exercise {@see HomePageSeeder}
 * performs for the homepage, and for the same reason: moving a designed page
 * into the CMS only helps if an editor opening it finds the designed copy
 * already in the fields. Every value below is the copy the public site ships
 * with, verbatim and bilingual.
 *
 * Seven sections, in the order of the Figma "History — Desktop 1440" frame
 * (node `51:706`): Page Hero, Founding Story, Milestone Timeline, Archive
 * Gallery, By the Numbers, Headmaster's Message, CTA Banner. The last is the
 * shared `cta_banner` type, not a History-specific one — the design binds
 * byte-identical copy to that symbol on both pages.
 *
 * Re-running is safe: the page is keyed on its slug and blocks on
 * (page, position). As with the homepage, the public renderer treats every
 * field as optional and falls back to its own copy, so clearing a field
 * degrades to the design and this seeder stays optional in production.
 *
 * Image values are paths into the frontend's own `/images/…` assets rather
 * than media-library references — see HomePageSeeder's note on why.
 */
class HistoryPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'history'],
            [
                'template' => 'landing',
                'title' => 'Our History',
                'title_bn' => 'আমাদের ইতিহাস',
                'excerpt' => 'From one schoolroom in 1927 to a hundred years of this community.',
                'excerpt_bn' => '১৯২৭ সালের এক পাঠশালা থেকে এই জনপদের একশ বছর।',
                // Bare, no school name: the public site's root layout appends
                // "— <school> শতবর্ষ" to every page title, so repeating it here
                // renders the school twice in one <title>.
                'seo_title' => 'Our History',
                'seo_title_bn' => 'আমাদের ইতিহাস',
                'seo_description' => 'Founded in 1927 as a junior madrasa in Baghanpara village, a junior high school by 1961 and a full high school by 1973 — the story of the school from its first plot of land to its centenary.',
                'seo_description_bn' => '১৯২৭ সালে বাগানপাড়া গ্রামে জুনিয়র মাদ্রাসা হিসেবে প্রতিষ্ঠা, ১৯৬১ সালে জুনিয়র উচ্চ বিদ্যালয় ও ১৯৭৩ সালে পূর্ণাঙ্গ উচ্চ বিদ্যালয় — এক টুকরো জমি থেকে শতবর্ষ পর্যন্ত বিদ্যালয়ের ইতিহাস।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 1,
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
            $this->hero(),
            $this->foundingStory(),
            $this->timeline(),
            $this->archiveGallery(),
            $this->numbers(),
            $this->headmasterMessage(),
            $this->ctaBanner(),
        ];
    }

    /**
     * Figma "01 · Page Hero" (node 51:729).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function hero(): array
    {
        return [
            'type' => 'history_hero',
            'fields' => [
                'breadcrumb' => self::t('Our history', 'আমাদের ইতিহাস'),
                // En-dash, never a hyphen — the renderer localises the digits.
                'year_pill' => '1927–2027',
                'eyebrow' => self::t('The Centenary Journey', 'শতবর্ষের পথচলা'),
                'heading_lead' => self::t('Our', 'আমাদের'),
                'heading_accent' => self::t('History', 'ইতিহাস'),
                'subheading' => self::t(
                    'From one schoolroom to one century',
                    'এক পাঠশালা থেকে একশ বছর',
                ),
                'body' => self::t(
                    'Founded in 1927 as a junior madrasa in Baghanpara village with the help of local families and well-wishers. A junior high school by 1961, a full high school by 1973 — at every step, carried by the people of this region.',
                    '১৯২৭ সালে স্থানীয় জনগণ ও সুধীবৃন্দের সহযোগিতায় বাগানপাড়া গ্রামে জুনিয়র মাদ্রাসা হিসেবে প্রতিষ্ঠানটির যাত্রা শুরু। ১৯৬১ সালে জুনিয়র উচ্চ বিদ্যালয় এবং ১৯৭৩ সালে পূর্ণাঙ্গ উচ্চ বিদ্যালয় হিসেবে স্বীকৃতি — প্রতিটি ধাপেই পাশে ছিল এই অঞ্চলের মানুষ।',
                ),
            ],
        ];
    }

    /**
     * Figma "02 · Founding Story" (node 52:706).
     *
     * The heading's accent word sits in the *middle* of the second line, so
     * that line is three fields — the accent cannot be split out of a single
     * string without breaking the Bangla.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function foundingStory(): array
    {
        return [
            'type' => 'founding_story',
            'fields' => [
                'eyebrow' => self::t('How It Began', 'যেভাবে শুরু'),
                'line1' => self::t('A Plot of Land in Baghanpara —', 'বাগানপাড়ার এক টুকরো'),
                'line2_pre' => self::t('Where the', 'জমিতে'),
                'accent' => self::t('Journey', 'যাত্রা'),
                'line2_post' => self::t('Began', 'শুরু'),
                'body' => self::t(
                    "The school began as a junior madrasa in Baghanpara village through the support of local people and well-wishers. The village gave land, gave labour, and sent their children — that collective effort is the foundation of today's Namoshankarbati High School.",
                    'বাগানপাড়া গ্রামে স্থানীয় জনগণ ও সুধীবৃন্দের সহযোগিতায় জুনিয়র মাদ্রাসা হিসেবে যাত্রা শুরু। গ্রামের মানুষ জমি দিয়েছেন, শ্রম দিয়েছেন, আর সন্তানদের পাঠিয়েছেন — সেই সম্মিলিত উদ্যোগই আজকের নামোশংকরবাটী উচ্চ বিদ্যালয়ের ভিত্তি।',
                ),
                'badge' => self::t('Founded', 'প্রতিষ্ঠা'),
                'badge_year' => self::t('1927', '১৯২৭'),
                'image_primary' => '/images/home/history/archive.jpg',
                'image_primary_alt' => self::t(
                    "Archive photo of the school's students",
                    'বিদ্যালয়ের শিক্ষার্থীদের পুরনো আর্কাইভ ছবি',
                ),
                'image_secondary' => '/images/home/history/campus.jpg',
                'image_secondary_alt' => self::t(
                    'The school campus building',
                    'বিদ্যালয় ক্যাম্পাসের ভবন',
                ),
                'chips' => [
                    [
                        'label' => self::t('Founded 1927', 'প্রতিষ্ঠা ১৯২৭'),
                        'icon' => 'CalendarDays',
                        'tone' => 'gold',
                    ],
                    [
                        'label' => self::t('Junior Madrasa', 'জুনিয়র মাদ্রাসা'),
                        'icon' => 'BookOpen',
                        'tone' => 'danger',
                    ],
                    [
                        'label' => self::t('Baghanpara Village', 'বাগানপাড়া গ্রাম'),
                        'icon' => 'MapPin',
                        'tone' => 'sky',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "03 · Milestone Timeline" (node 53:712).
     *
     * These four dates are the spine of the centenary identity. They are
     * editable here because the whole page is, but they should be changed only
     * against the institutional record, not to fit a layout.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function timeline(): array
    {
        return [
            'type' => 'history_timeline',
            'fields' => [
                'eyebrow' => self::t('The Centenary Journey', 'শতবর্ষের পথচলা'),
                'heading_dark' => self::t('Our Journey', 'সময়ের সাথে'),
                'heading_accent' => self::t('Through Time', 'আমাদের যাত্রা'),
                'milestones' => [
                    [
                        'year' => '1927',
                        'tone' => 'gold',
                        'title' => self::t('Founded', 'প্রতিষ্ঠা'),
                        'description' => self::t(
                            'Founded as a junior madrasa in Baghanpara village through local community support.',
                            'বাগানপাড়া গ্রামে স্থানীয় জনগণ ও সুধীবৃন্দের সহযোগিতায় জুনিয়র মাদ্রাসা হিসেবে যাত্রা শুরু।',
                        ),
                    ],
                    [
                        'year' => '1961',
                        'tone' => 'orange',
                        'title' => self::t('Junior High School', 'জুনিয়র উচ্চ বিদ্যালয়'),
                        'description' => self::t(
                            'The institution was converted into a junior high school.',
                            'প্রতিষ্ঠানটি জুনিয়র উচ্চ বিদ্যালয়ে রূপান্তরিত হয়।',
                        ),
                    ],
                    [
                        'year' => '1973',
                        'tone' => 'blue',
                        'title' => self::t('High School', 'উচ্চ বিদ্যালয়'),
                        'description' => self::t(
                            'Recognised as a full high school in independent Bangladesh.',
                            'স্বাধীন বাংলাদেশে পূর্ণাঙ্গ উচ্চ বিদ্যালয় হিসেবে স্বীকৃতি লাভ।',
                        ),
                    ],
                    [
                        'year' => '2027',
                        'tone' => 'green',
                        'title' => self::t('Centenary', 'শতবর্ষ'),
                        'description' => self::t(
                            'A hundred years complete — every batch, every family, together.',
                            'একশ বছর পূর্ণ — প্রতিটি ব্যাচ, প্রতিটি পরিবার একসাথে।',
                        ),
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "04 · Archive Gallery" (node 54:715).
     *
     * Five slots, because the strip's sizes, tilts and overlaps are the design
     * rather than data — a sixth photo has nowhere to sit.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function archiveGallery(): array
    {
        return [
            'type' => 'archive_gallery',
            'fields' => [
                'eyebrow' => self::t('The Memory Album', 'স্মৃতির অ্যালবাম'),
                'heading_dark' => self::t('Then and', 'তখন ও'),
                'heading_accent' => self::t('Now', 'এখন'),
                'photos' => [
                    ['image' => '/images/home/gallery/1927.jpg', 'year' => self::t('1927', '১৯২৭')],
                    ['image' => '/images/home/gallery/1946.jpg', 'year' => self::t('1946', '১৯৪৬')],
                    ['image' => '/images/home/gallery/1968.jpg', 'year' => self::t('1968', '১৯৬৮')],
                    ['image' => '/images/home/gallery/1985.jpg', 'year' => self::t('1985', '১৯৮৫')],
                    ['image' => '/images/home/gallery/present.jpg', 'year' => self::t('Present Day', 'বর্তমান')],
                ],
            ],
        ];
    }

    /**
     * Figma "05 · By the Numbers" (node 54:739).
     *
     * The same divided card as the homepage's stat bar, under a heading, and
     * with this page's own four counts — student and teacher numbers are the
     * live institutional figures and should be updated here when they change.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function numbers(): array
    {
        return [
            'type' => 'numbers_bar',
            'fields' => [
                'eyebrow' => self::t('At a Glance', 'এক নজরে'),
                'heading_dark' => self::t('Namoshankarbati in', 'সংখ্যায়'),
                'heading_accent' => self::t('Numbers', 'নামোশংকরবাটী'),
                'stats' => [
                    [
                        'value' => '100',
                        'label' => self::t('Years of journey', 'বছরের পথচলা'),
                        'icon' => 'CalendarDays',
                        'tone' => 'gold',
                    ],
                    [
                        'value' => '1762+',
                        'label' => self::t('Students today', 'বর্তমান শিক্ষার্থী'),
                        'icon' => 'GraduationCap',
                        'tone' => 'orange',
                    ],
                    [
                        'value' => '21',
                        'label' => self::t('Teachers', 'জন শিক্ষক'),
                        'icon' => 'Users',
                        'tone' => 'green',
                    ],
                    [
                        'value' => '56',
                        'label' => self::t('Alumni batches', 'ব্যাচের প্রাক্তন শিক্ষার্থী'),
                        'icon' => 'Landmark',
                        'tone' => 'pink',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "06 · Headmaster's Message" (node 55:710).
     *
     * The design file's own copy layer reads "Message body — PLACEHOLDER
     * COPY", so the quote below is placeholder prose written to fit the
     * section, not sourced text. It is the first thing on this page an editor
     * should replace.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function headmasterMessage(): array
    {
        return [
            'type' => 'headmaster_message',
            'fields' => [
                'eyebrow' => self::t("From the Headmaster's Desk", 'বিদ্যালয় প্রধানের কলমে'),
                'heading_dark' => self::t('A Message from the', 'প্রধান শিক্ষকের'),
                'heading_accent' => self::t('Headmaster', 'বাণী'),
                'name' => self::t('Md. Aslam Kabir', 'মোঃ আসলাম কবীর'),
                'title' => self::t('Headmaster', 'প্রধান শিক্ষক'),
                'quote' => self::t(
                    'The dream this village dreamed a hundred years ago now lives in every one of our classrooms. For this centennial celebration, I warmly welcome every alumnus, guardian and well-wisher back to our campus.',
                    'একশ বছর আগে এই গ্রামের মানুষ যে স্বপ্ন দেখেছিলেন, আজ তার ফসল আমাদের প্রতিটি শ্রেণিকক্ষে। শতবর্ষের এই আয়োজনে প্রতিটি প্রাক্তন শিক্ষার্থী, অভিভাবক ও শুভানুধ্যায়ীকে বিদ্যালয় প্রাঙ্গণে স্বাগত জানাই।',
                ),
                'portrait' => '/images/history/headmaster-portrait.png',
            ],
        ];
    }

    /**
     * Figma "07 · CTA Banner" (node 55:725) — the shared symbol, bound to the
     * same copy the homepage's node 32:805 carries.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function ctaBanner(): array
    {
        return [
            'type' => 'cta_banner',
            'fields' => [
                'eyebrow' => self::t("Let's celebrate together", 'আসুন একসাথে উদযাপন করি'),
                'heading_line1' => self::t('Be part of this', 'একশ বছরের এই'),
                'heading_accent' => self::t('historic', 'ঐতিহাসিক মুহূর্তের'),
                'heading_line2' => self::t('century milestone', 'অংশ হোন'),
                'body' => self::t(
                    'Your presence will make this celebration even more memorable and joyful.',
                    'আপনার উপস্থিতি আমাদের উদযাপনকে করবে আরও স্মরণীয় ও আনন্দময়।',
                ),
                'primary_label' => self::t('Get your ticket now', 'এখনই টিকিট নিন'),
                'primary_url' => '/tickets',
                'secondary_label' => self::t('Learn more', 'বিস্তারিত জানতে'),
                'secondary_url' => '/event',
            ],
        ];
    }
}
