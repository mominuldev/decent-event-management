<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Gallery page, block by block — the same exercise {@see HomePageSeeder}
 * and {@see HistoryPageSeeder} perform: every value below is the copy the
 * public site ships with, verbatim and bilingual, so an editor opening the
 * page finds the designed copy already in the fields.
 *
 * Seven sections, in the order of the Figma "Gallery — Desktop 1440" frame
 * (node `106:800`): Page Hero, Album Filters, Photo Grid, Albums, Video
 * Gallery, Contribute, CTA Banner. The last is the shared `cta_banner` type.
 *
 * The photo counts here are display copy from the design, not live figures —
 * the archive is not (yet) a media-library query. Image values are paths into
 * the frontend's own `/images/gallery-page/…` assets; see HomePageSeeder's
 * note on why.
 */
class GalleryPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'gallery'],
            [
                'template' => 'landing',
                'title' => 'Gallery & Memories',
                'title_bn' => 'গ্যালারি ও স্মৃতি',
                'excerpt' => 'A century of photographs, shared by our community.',
                'excerpt_bn' => 'একশ বছরের আলোকচিত্র, আমাদের সবার সংগ্রহ থেকে।',
                'seo_title' => 'Gallery & Memories',
                'seo_title_bn' => 'গ্যালারি ও স্মৃতি',
                'seo_description' => 'A hundred years of photos, videos and documents — from the first schoolroom to today\'s campus. Search by year, batch or event.',
                'seo_description_bn' => 'শতবর্ষের পথচলায় জমে থাকা ছবি, ভিডিও আর দলিল — প্রথম পাঠশালা থেকে আজকের মাঠ পর্যন্ত। বছর, ব্যাচ বা অনুষ্ঠান ধরে খুঁজুন।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 3,
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
            $this->filters(),
            $this->photoGrid(),
            $this->albums(),
            $this->videos(),
            $this->contribute(),
            $this->ctaBanner(),
        ];
    }

    /**
     * Figma "01 · Page Hero" (node 106:801).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function hero(): array
    {
        return [
            'type' => 'gallery_hero',
            'fields' => [
                'breadcrumb' => self::t('Gallery', 'গ্যালারি'),
                'eyebrow' => self::t('Memories preserved from 1931 to today', '১৯৩১ থেকে আজ পর্যন্ত সংরক্ষিত স্মৃতি'),
                'heading_lead' => self::t('The Memory', 'স্মৃতির'),
                'heading_accent' => self::t('Album', 'অ্যালবাম'),
                'body' => self::t(
                    "A hundred years of photos, videos and documents — from that first schoolroom to today's campus. Search by year, batch or event to find your own memory.",
                    'শতবর্ষের পথচলায় জমে থাকা ছবি, ভিডিও আর দলিল — প্রতিষ্ঠার প্রথম দিনের পাঠশালা থেকে আজকের মাঠ পর্যন্ত। বছর, ব্যাচ বা অনুষ্ঠান ধরে খুঁজে নিন নিজের স্মৃতিটুকু।',
                ),
                'facts' => [
                    ['icon' => 'Camera', 'label' => self::t('Archived Photos', 'সংরক্ষিত ছবি'), 'value' => self::t('1,240+', '১,২৪০+')],
                    ['icon' => 'Video', 'label' => self::t('Video & Audio', 'ভিডিও ও অডিও'), 'value' => self::t('38', '৩৮টি')],
                    ['icon' => 'Clock', 'label' => self::t('Oldest Photo', 'প্রাচীনতম ছবি'), 'value' => self::t('Year 1931', '১৯৩১ সাল')],
                ],
            ],
        ];
    }

    /**
     * Figma "02 · Album Filters".
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function filters(): array
    {
        return [
            'type' => 'album_filters',
            'fields' => [
                'filters' => [
                    ['label' => self::t('All Photos', 'সব ছবি'), 'count' => '1240'],
                    ['label' => self::t('Founding Era 1927–60', 'প্রতিষ্ঠাকাল ১৯২৭–৬০'), 'count' => '86'],
                    ['label' => self::t('Classroom & Learning', 'শ্রেণিকক্ষ ও পাঠদান'), 'count' => '214'],
                    ['label' => self::t('Sports', 'খেলাধুলা'), 'count' => '178'],
                    ['label' => self::t('Cultural Evenings', 'সাংস্কৃতিক সন্ধ্যা'), 'count' => '236'],
                    ['label' => self::t('Alumni Reunions', 'প্রাক্তন মিলনমেলা'), 'count' => '192'],
                    ['label' => self::t('Centennial Prep', 'শতবর্ষ প্রস্তুতি'), 'count' => '134'],
                ],
            ],
        ];
    }

    /**
     * Figma "03 · Photo Grid". Twelve photos over the five shipped images —
     * the design repeats its placeholders the same way.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function photoGrid(): array
    {
        $photos = [
            [self::t('The First Schoolhouse', 'প্রথম পাঠশালা ভবন'), self::t('1931 · Founding Era', '১৯৩১ · প্রতিষ্ঠাকাল')],
            [self::t('The Founding Teachers', 'প্রতিষ্ঠাতা শিক্ষকমণ্ডলী'), self::t('1938 · Founding Era', '১৯৩৮ · প্রতিষ্ঠাকাল')],
            [self::t('Annual Sports Competition', 'বার্ষিক ক্রীড়া প্রতিযোগিতা'), self::t('1946 · Sports', '১৯৪৬ · খেলাধুলা')],
            [self::t('New Classroom Opening', 'নতুন শ্রেণিকক্ষ উদ্বোধন'), self::t('1954 · Classroom & Learning', '১৯৫৪ · শ্রেণিকক্ষ ও পাঠদান')],
            [self::t('First Day of the Science Lab', 'বিজ্ঞানাগারের প্রথম দিন'), self::t('1968 · Classroom & Learning', '১৯৬৮ · শ্রেণিকক্ষ ও পাঠদান')],
            [self::t('Independence Day Parade', 'স্বাধীনতা দিবস কুচকাওয়াজ'), self::t('1973 · Sports', '১৯৭৩ · খেলাধুলা')],
            [self::t('A Cultural Evening', 'সাংস্কৃতিক সন্ধ্যা'), self::t('1985 · Cultural Evenings', '১৯৮৫ · সাংস্কৃতিক সন্ধ্যা')],
            [self::t('SSC Batch Farewell', 'এসএসসি ব্যাচের বিদায়'), self::t('1992 · Alumni Reunions', '১৯৯২ · প্রাক্তন মিলনমেলা')],
            [self::t('Library Expansion', 'পাঠাগার সম্প্রসারণ'), self::t('2004 · Classroom & Learning', '২০০৪ · শ্রেণিকক্ষ ও পাঠদান')],
            [self::t('Alumni Reunion', 'প্রাক্তন শিক্ষার্থী মিলনমেলা'), self::t('2016 · Alumni Reunions', '২০১৬ · প্রাক্তন মিলনমেলা')],
            [self::t('Annual Prize Giving', 'বার্ষিক পুরস্কার বিতরণী'), self::t('2021 · Cultural Evenings', '২০২১ · সাংস্কৃতিক সন্ধ্যা')],
            [self::t('Centennial Planning Meeting', 'শতবর্ষ প্রস্তুতি সভা'), self::t('2025 · Centennial Prep', '২০২৫ · শতবর্ষ প্রস্তুতি')],
        ];

        return [
            'type' => 'photo_grid',
            'fields' => [
                'eyebrow' => self::t('The Archive', 'সংগ্রহশালা'),
                'heading_dark' => self::t('A Century in', 'ছবিতে'),
                'heading_accent' => self::t('Pictures', 'শতবর্ষ'),
                'more_label' => self::t('View More Photos', 'আরও ছবি দেখুন'),
                'photos' => array_map(
                    fn (array $photo, int $i): array => [
                        'image' => sprintf('/images/gallery-page/photos/photo-%d.jpg', ($i % 5) + 1),
                        'title' => $photo[0],
                        'meta' => $photo[1],
                    ],
                    $photos,
                    array_keys($photos),
                ),
            ],
        ];
    }

    /**
     * Figma "04 · Albums".
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function albums(): array
    {
        $albums = [
            [self::t('1927–1960', '১৯২৭–১৯৬০'), self::t('Founding & the First Decade', 'প্রতিষ্ঠাকাল ও প্রথম দশক'), self::t('86 photos', '৮৬টি ছবি')],
            [self::t('1940–Present', '১৯৪০–বর্তমান'), self::t('Classroom & Learning', 'শ্রেণিকক্ষ ও পাঠদান'), self::t('214 photos', '২১৪টি ছবি')],
            [self::t('1952–Present', '১৯৫২–বর্তমান'), self::t('Sports & Athletics', 'খেলাধুলা ও ক্রীড়া'), self::t('178 photos', '১৭৮টি ছবি')],
            [self::t('1966–Present', '১৯৬৬–বর্তমান'), self::t('Cultural Evenings', 'সাংস্কৃতিক সন্ধ্যা'), self::t('236 photos', '২৩৬টি ছবি')],
            [self::t('1988–Present', '১৯৮৮–বর্তমান'), self::t('Alumni Reunions', 'প্রাক্তন মিলনমেলা'), self::t('192 photos', '১৯২টি ছবি')],
            [self::t('2024–2027', '২০২৪–২০২৭'), self::t('Centennial Prep', 'শতবর্ষ প্রস্তুতি'), self::t('134 photos', '১৩৪টি ছবি')],
        ];

        return [
            'type' => 'album_collection',
            'fields' => [
                'eyebrow' => self::t('Browse by Theme', 'বিষয় ধরে খুঁজুন'),
                'heading_dark' => self::t('The Album', 'অ্যালবাম'),
                'heading_accent' => self::t('Collection', 'সংগ্রহ'),
                'albums' => array_map(
                    fn (array $album, int $i): array => [
                        'image' => sprintf('/images/gallery-page/albums/album-%d.jpg', ($i % 5) + 1),
                        'span' => $album[0],
                        'title' => $album[1],
                        'count' => $album[2],
                    ],
                    $albums,
                    array_keys($albums),
                ),
            ],
        ];
    }

    /**
     * Figma "05 · Video Gallery". No video links shipped with the design —
     * the cards are thumbnails until an editor adds a URL.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function videos(): array
    {
        return [
            'type' => 'video_gallery',
            'fields' => [
                'eyebrow' => self::t('Memories in Motion', 'চলমান স্মৃতি'),
                'heading_dark' => self::t('The Video', 'ভিডিও'),
                'heading_accent' => self::t('Archive', 'সংরক্ষণ'),
                'videos' => [
                    [
                        'image' => '/images/gallery-page/videos/video-1.jpg',
                        'title' => self::t("A Century's Journey — Documentary", 'শতবর্ষের পথচলা — তথ্যচিত্র'),
                        'meta' => self::t('Documentary · 2026', 'তথ্যচিত্র · ২০২৬'),
                        'duration' => '12:40',
                        'url' => '',
                    ],
                    [
                        'image' => '/images/gallery-page/videos/video-2.jpg',
                        'title' => self::t('Alumni Remember', 'প্রাক্তন শিক্ষার্থীদের স্মৃতিচারণ'),
                        'meta' => self::t('Interview · 2025', 'সাক্ষাৎকার · ২০২৫'),
                        'duration' => '08:15',
                        'url' => '',
                    ],
                    [
                        'image' => '/images/gallery-page/videos/video-3.jpg',
                        'title' => self::t('Annual Cultural Evening', 'বার্ষিক সাংস্কৃতিক সন্ধ্যা'),
                        'meta' => self::t('Event · 2024', 'অনুষ্ঠান · ২০২৪'),
                        'duration' => '21:03',
                        'url' => '',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "06 · Contribute".
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function contribute(): array
    {
        return [
            'type' => 'contribute',
            'fields' => [
                'eyebrow' => self::t('Join the Archive', 'আর্কাইভে যোগ দিন'),
                'heading_dark' => self::t('Add Your Photos', 'আপনার ছবিও'),
                'heading_accent' => self::t('Too', 'যোগ করুন'),
                'panel_title' => self::t('Scan and Send It In', 'স্ক্যান করে পাঠিয়ে দিন'),
                'panel_note' => self::t('JPG, PNG or PDF · Max 25MB per file', 'JPG, PNG বা PDF · প্রতিটি ফাইল সর্বোচ্চ ২৫ এমবি'),
                'kicker' => self::t('Preserve a Memory', 'স্মৃতি সংরক্ষণ'),
                'heading' => self::t(
                    'A lost moment might be sitting in your own photo album',
                    'আপনার অ্যালবামেই হয়তো আছে হারিয়ে যাওয়া মুহূর্ত',
                ),
                'body' => self::t(
                    "A 1930s class photo, an old certificate, a field-day snapshot, a handwritten letter — send us whatever you have. Our volunteer team will scan it and preserve it in the centennial's digital archive.",
                    '১৯৩০-এর ক্লাস ফটো, পুরোনো সনদ, মাঠের ছবি কিংবা হাতে লেখা চিঠি — যা কিছু আছে আমাদের পাঠিয়ে দিন। স্বেচ্ছাসেবক দল সেটি স্ক্যান করে শতবর্ষের ডিজিটাল আর্কাইভে সংরক্ষণ করবে।',
                ),
                'steps' => [
                    ['title' => self::t('Send Your Photos', 'ছবি পাঠান'), 'body' => self::t('Send a scanned copy by email or WhatsApp', 'ইমেইল বা হোয়াটসঅ্যাপে স্ক্যান কপি পাঠান')],
                    ['title' => self::t('Add the Details', 'তথ্য যোগ করুন'), 'body' => self::t('Note the year, people and a short description', 'সাল, ব্যক্তি ও ঘটনার সংক্ষিপ্ত বিবরণ লিখে দিন')],
                    ['title' => self::t('Get Credited', 'কৃতজ্ঞতা স্বীকার'), 'body' => self::t('Your name appears as the contributor alongside the photo', 'প্রকাশিত ছবির পাশে দাতা হিসেবে আপনার নাম থাকবে')],
                ],
                'cta_label' => self::t('Submit Your Photos', 'ছবি জমা দিন'),
                'cta_url' => 'mailto:alumni@nsbatihighschool.edu.bd',
            ],
        ];
    }

    /**
     * The shared CTA symbol, with this page's own "share your memories" copy.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function ctaBanner(): array
    {
        return [
            'type' => 'cta_banner',
            'fields' => [
                'eyebrow' => self::t('Share a Memory', 'স্মৃতি ভাগ করে নিন'),
                'heading_line1' => self::t('The Photo in Your Album', 'আপনার অ্যালবামের ছবিই'),
                'heading_accent' => self::t('Could Become', 'হয়ে উঠুক'),
                'heading_line2' => self::t('History', 'ইতিহাস'),
                'body' => self::t(
                    'If you have an old photo, certificate or keepsake, send it our way — it will be preserved in the centennial archive.',
                    'পুরোনো ছবি, সনদ বা স্মারক থাকলে আমাদের পাঠান — শতবর্ষের আর্কাইভে তা সংরক্ষিত থাকবে।',
                ),
                'primary_label' => self::t('Send a Photo', 'ছবি পাঠান'),
                'primary_url' => 'mailto:alumni@nsbatihighschool.edu.bd',
                'secondary_label' => self::t('About the Archive', 'আর্কাইভ সম্পর্কে'),
                'secondary_url' => '/gallery#contribute',
            ],
        ];
    }
}
