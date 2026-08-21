<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use Illuminate\Database\Seeder;

/**
 * The centenary homepage, block by block.
 *
 * The public site used to hardcode all thirteen of these sections from the
 * Figma "Home C — Desktop 1440" frame. Moving them into the CMS only helps if
 * an editor opening the page finds the *designed* copy already in the fields —
 * an empty form would just mean re-typing a page that already exists. So every
 * value below is the shipped copy, verbatim and bilingual, and re-running the
 * seeder is safe: rows are keyed on (page, position) and the page on its slug.
 *
 * The renderer treats every field as optional and falls back to the same copy
 * it ships with, so clearing a field degrades to the design rather than to a
 * blank section — which is also what makes this seeder optional in production.
 *
 * Images are stored as paths into the public site's own `/images/home/…`
 * assets rather than as media-library references: those files ship with the
 * frontend, and requiring an upload per guest portrait would make this seeder
 * unrunnable on a fresh database. The admin's media picker writes a public URL
 * into the very same field, so an editor replacing one never sees a seam.
 */
class HomePageSeeder extends Seeder
{
    /**
     * Marks a value as differing per language. Anything not wrapped in this —
     * an image path, a link, an icon name, a tone key, an ASCII numeral the
     * renderer localises itself — is written identically to `data` and
     * `data_bn`, which is what keeps the two block payloads key-for-key and
     * row-for-row aligned.
     *
     * @return array{en: string, bn: string}
     */
    private static function t(string $en, string $bn): array
    {
        return ['en' => $en, 'bn' => $bn];
    }

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'home'],
            [
                'template' => 'landing',
                'title' => 'Centenary Celebration',
                'title_bn' => 'শতবর্ষ উদযাপন',
                'excerpt' => 'One hundred years of the institution, celebrated by the people who made it.',
                'excerpt_bn' => 'প্রতিষ্ঠানের একশ বছর, উদযাপন করছেন যাঁরা একে গড়ে তুলেছেন।',
                'seo_title' => 'Centenary Celebration — Namosanker Bati High School',
                'seo_title_bn' => 'শতবর্ষ উদযাপন — নামোশংকরবাটী উচ্চ বিদ্যালয়',
                'seo_description' => 'The one-room school that opened in 1927 turns one hundred in 2027. Every batch, every teacher, every family — one day, together.',
                'seo_description_bn' => '১৯২৭ সালে শুরু হওয়া পাঠশালা ২০২৭ সালে পূর্ণ করছে একশ বছর। প্রতিটি ব্যাচ, প্রতিটি শিক্ষক, প্রতিটি পরিবার — একদিনের এই উৎসব।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 0,
            ]
        );

        $blocks = $this->blocks();

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

        // A shorter homepage on a re-run must not leave orphaned sections
        // rendering below the last one this seeder wrote.
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

    /**
     * @return list<array{type: string, fields: array<string, mixed>}>
     */
    private function blocks(): array
    {
        return [
            $this->hero(),
            $this->statBar(),
            $this->historyTeaser(),
            $this->milestoneTimeline(),
            $this->guests(),
            $this->attractions(),
            $this->programme(),
            $this->gallery(),
            $this->testimonials(),
            $this->pricing(),
            $this->sponsors(),
            $this->faqs(),
            $this->ctaBanner(),
        ];
    }

    /**
     * Figma "01 · Hero" (node 32:201).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function hero(): array
    {
        return [
            'type' => 'home_hero',
            'fields' => [
                // En-dash, never a hyphen — the renderer localises the digits.
                'year_pill' => '1927–2027',
                'eyebrow' => self::t('Centennial Celebration', 'শতবর্ষ উদযাপন'),
                'headline_lead' => self::t('A Hundred Years of', 'একশ বছরের'),
                'headline_accent' => self::t('This Journey', 'পথচলা'),
                'headline_tail_1' => self::t('Together', 'একসাথে'),
                'headline_tail_2' => self::t('We Celebrate', 'উদযাপন'),
                'body' => self::t(
                    'The one-room school that opened on a patch of Baghanpara land in 1927 turns one hundred in 2027. Every batch, every teacher, every family — one day, together.',
                    '১৯২৭ সালে বাগানপাড়ার এক টুকরো জমিতে যে পাঠশালা শুরু হয়েছিল, ২০২৭ সালে সেটি পূর্ণ করছে একশ বছর। প্রতিটি ব্যাচ, প্রতিটি শিক্ষক, প্রতিটি পরিবার — সবাইকে নিয়ে একদিনের এই উৎসব।',
                ),
                'primary_label' => self::t('Get Your Ticket', 'টিকিট সংগ্রহ করুন'),
                'primary_url' => '/tickets',
                'secondary_label' => self::t('View the Programme', 'অনুষ্ঠানাবলী দেখুন'),
                'secondary_url' => '/event',
                // A placeholder until a real date is set: the centenary year is
                // fixed but carries no month or day, so this is deliberately
                // New Year's Day of it rather than an invented date.
                'countdown_target' => '2027-01-01T09:00:00+06:00',
                'image' => '/images/home/hero/hero-composition.png',
            ],
        ];
    }

    /**
     * Figma "02 · Stat Bar" (node 32:258).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function statBar(): array
    {
        return [
            'type' => 'stat_bar',
            'fields' => [
                'stats' => [
                    [
                        'value' => '100',
                        'label' => self::t('Years of learning', 'বছরের পথচলা'),
                        'icon' => 'Users',
                        'tone' => 'gold',
                    ],
                    [
                        'value' => '1762+',
                        'label' => self::t('Successful students', 'সফল শিক্ষার্থী'),
                        'icon' => 'GraduationCap',
                        'tone' => 'orange',
                    ],
                    [
                        'value' => '21',
                        'label' => self::t('Teachers', 'জন শিক্ষক'),
                        'icon' => 'UserPlus',
                        'tone' => 'green',
                    ],
                    [
                        'value' => '56',
                        'label' => self::t('Celebrated alumni batches', 'ব্যাচের গৌরবময় শিক্ষার্থী'),
                        'icon' => 'Landmark',
                        'tone' => 'pink',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "03 · History" (node 32:296).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function historyTeaser(): array
    {
        return [
            'type' => 'history_teaser',
            'fields' => [
                'eyebrow' => self::t('Our History', 'আমাদের ইতিহাস'),
                'line1' => self::t('From One Schoolroom', 'এক পাঠশালা থেকে'),
                'line2_pre' => self::t('to a Hundred Years of', 'একশ বছরের'),
                'accent' => self::t('Glorious', 'গৌরবময়'),
                'line2_post' => self::t('Journey', 'যাত্রা'),
                'body' => self::t(
                    'Founded in 1927, our institution has spent a century carrying the light of education into this community. Through the contributions of thousands of students, our teachers and countless well-wishers, we stand today at a height that is truly our own.',
                    '১৯২৭ সালে স্থাপিত আমাদের এই শিক্ষাপীঠ সমাজ ও সুশীল সমাজের আলো ছড়িয়ে শতবর্ষের ইতিহাস রচনা করেছে। হাজারো ছাত্র-ছাত্রী, শিক্ষকমণ্ডলী ও শুভানুধ্যায়ীদের অবদানে আজ আমরা এক অনন্য উচ্চতায়।',
                ),
                'badge' => self::t('Heritage to Progress', 'ঐতিহ্য থেকে অগ্রযাত্রা'),
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
                'link_label' => self::t('Learn more', 'আরও জানুন'),
                'link_url' => '/event',
                'chips' => [
                    [
                        'label' => self::t('Quality Education', 'মানসম্মত শিক্ষা'),
                        'icon' => 'GraduationCap',
                        'tone' => 'gold',
                    ],
                    [
                        'label' => self::t('Moral Values', 'নৈতিক মূল্যবোধ'),
                        'icon' => 'HeartHandshake',
                        'tone' => 'danger',
                    ],
                    [
                        'label' => self::t('Service to Society', 'সমাজ ও দেশের সেবা'),
                        'icon' => 'Users',
                        'tone' => 'sky',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "04 · Journey Timeline" (node 32:378).
     *
     * These four dates are the spine of the centenary identity. They are
     * editable here because the whole page is, but they should be changed only
     * against the institutional record, not to fit a layout.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function milestoneTimeline(): array
    {
        return [
            'type' => 'milestone_timeline',
            'fields' => [
                'eyebrow' => self::t('Our journey', 'আমাদের পথচলা'),
                'heading' => self::t(
                    "A century's journey through time",
                    'সময়ের সাথে একশ বছরের যাত্রা',
                ),
                'milestones' => [
                    [
                        'year' => '1927',
                        'title' => self::t('Founded', 'প্রতিষ্ঠা'),
                        'body' => self::t(
                            'Began as a village primary school.',
                            'গ্রামের এক প্রাথমিক বিদ্যালয় হিসেবে যাত্রা শুরু।',
                        ),
                    ],
                    [
                        'year' => '1961',
                        'title' => self::t('Became a High School', 'উচ্চ বিদ্যালয়ে রূপান্তর'),
                        'body' => self::t(
                            'A new chapter in the expansion of quality education.',
                            'মানসম্মত শিক্ষার বিস্তারে নতুন অধ্যায়।',
                        ),
                    ],
                    [
                        'year' => '1973',
                        'title' => self::t('Moved to Its Own Campus', 'নিজস্ব ভবনে স্থানান্তর'),
                        'body' => self::t(
                            'Transitioned to a modern, well-equipped campus.',
                            'আধুনিক সুবিধাসম্পন্ন ক্যাম্পাসে উত্তরণ।',
                        ),
                    ],
                    [
                        'year' => '2027',
                        'title' => self::t('Centenary Celebration', 'শতবর্ষ উদযাপন'),
                        'body' => self::t(
                            'A pledge to celebrate a century of pride.',
                            'একশ বছরের গৌরব উদযাপনের প্রত্যয়।',
                        ),
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "05 · Guests" (node 32:408).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function guests(): array
    {
        return [
            'type' => 'guest_carousel',
            'fields' => [
                'eyebrow' => self::t('Our Pride, Our Inspiration', 'আমাদের গর্ব, আমাদের অনুপ্রেরণা'),
                'heading_dark' => self::t('Our Distinguished', 'আমাদের গৌরবময়'),
                'heading_accent' => self::t('Guests', 'অতিথিরা'),
                'view_all_label' => self::t('See all guests', 'সব অতিথি দেখুন'),
                'view_all_url' => '/alumni',
                'guests' => [
                    [
                        'name' => self::t('Eng. Mohammad Selim', 'ইঞ্জি. মোহাম্মদ সেলিম'),
                        'role' => self::t('Chairman', 'চেয়ারম্যান'),
                        'org' => self::t('ABC Group', 'ABC Group'),
                        'batch_year' => '1978',
                        'image' => '/images/home/guests/selim.jpg',
                        'tone' => 'danger',
                    ],
                    [
                        'name' => self::t('Dr. Nasima Sultana', 'ড. নাসিমা সুলতানা'),
                        'role' => self::t('Director', 'পরিচালক'),
                        'org' => self::t('Directorate General of Health', 'স্বাস্থ্য অধিদপ্তর'),
                        'batch_year' => '1989',
                        'image' => '/images/home/guests/nasima.jpg',
                        'tone' => 'danger',
                    ],
                    [
                        'name' => self::t('Sayemur Rahman', 'সায়েমুর রহমান'),
                        'role' => self::t('Managing Director', 'Managing Director'),
                        'org' => self::t('BRAC Bank', 'BRAC Bank'),
                        'batch_year' => '1996',
                        'image' => '/images/home/guests/sayemur.jpg',
                        'tone' => 'gold',
                    ],
                    [
                        'name' => self::t('Goutik Ahmed', 'গৌতিক আহমেদ'),
                        'role' => self::t('CEO', 'CEO'),
                        'org' => self::t('Grameenphone', 'Grameenphone'),
                        'batch_year' => '1994',
                        'image' => '/images/home/guests/goutik.jpg',
                        'tone' => 'blue',
                    ],
                    [
                        'name' => self::t('Shamima Afrin', 'শামিমা আফরিন'),
                        'role' => self::t('Director', 'Director'),
                        'org' => self::t('bKash Limited', 'bKash Limited'),
                        'batch_year' => '2000',
                        'image' => '/images/home/guests/shamima.jpg',
                        'tone' => 'pink',
                    ],
                    [
                        'name' => self::t('Rakibul Hasan', 'রাকিবুল হাসান'),
                        'role' => self::t('Co-founder', 'Co-founder'),
                        'org' => self::t('Pathao', 'Pathao'),
                        'batch_year' => '2006',
                        'image' => '/images/home/guests/rakibul.jpg',
                        'tone' => 'gold',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "06 · Attractions" (node 32:428).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function attractions(): array
    {
        return [
            'type' => 'attraction_grid',
            'fields' => [
                'eyebrow' => self::t("What's Planned", 'অনুষ্ঠানের নানা আয়োজন'),
                'heading_dark' => self::t("What's Waiting", 'যা যা অপেক্ষা করছে'),
                'heading_accent' => self::t('For You', 'আপনার জন্য'),
                'attractions' => [
                    [
                        'title' => self::t('Grand Reunion', 'গ্র্যান্ড রিইউনিয়ন'),
                        'body' => self::t(
                            'A reunion with old friends and classmates.',
                            'পুরোনো বন্ধু ও সহপাঠীদের সাথে পুনর্মিলনী।',
                        ),
                        'image' => '/images/home/attractions/reunion.jpg',
                    ],
                    [
                        'title' => self::t('Cultural Programme', 'সাংস্কৃতিক অনুষ্ঠান'),
                        'body' => self::t(
                            'Captivating cultural performances.',
                            'মনোমুগ্ধকর সাংস্কৃতিক পরিবেশনা।',
                        ),
                        'image' => '/images/home/attractions/cultural.jpg',
                    ],
                    [
                        'title' => self::t('Campus Tour', 'ক্যাম্পাস ট্যুর'),
                        'body' => self::t(
                            'A walk through our beloved campus and old memories.',
                            'আমাদের প্রিয় ক্যাম্পাস ঘুরে দেখা পুরোনো স্মৃতি।',
                        ),
                        'image' => '/images/home/attractions/campus-tour.jpg',
                    ],
                    [
                        'title' => self::t('Teacher Felicitation', 'শিক্ষক সংবর্ধনা'),
                        'body' => self::t(
                            'Honouring our beloved teachers.',
                            'প্রিয় শিক্ষকদের সংবর্ধনা ও শ্রদ্ধার্ঘ্য।',
                        ),
                        'image' => '/images/home/attractions/teacher-felicitation.jpg',
                    ],
                    [
                        'title' => self::t('Alumni Networking', 'অ্যালামনাই নেটওয়ার্কিং'),
                        'body' => self::t(
                            'A chance to build professional and personal connections.',
                            'পেশাগত ও ব্যক্তিগত নেটওয়ার্ক গড়ে তোলার সুযোগ।',
                        ),
                        'image' => '/images/home/attractions/alumni-networking.jpg',
                    ],
                    [
                        'title' => self::t('Gala Dinner', 'গালা ডিনার'),
                        'body' => self::t(
                            'An evening of good food, conversation and joy.',
                            'সুস্বাদু খাবার, আড্ডা ও আনন্দের সন্ধ্যা।',
                        ),
                        'image' => '/images/home/attractions/gala-dinner.jpg',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "07 · Programme Timeline" (node 32:437) — the homepage's own icon
     * rail, seeded on the shared `schedule` block's homepage-only fields. The
     * /event page keeps rendering the Schedule tab's records from these same
     * blocks; only the `stops` rows below are homepage-specific.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function programme(): array
    {
        return [
            'type' => 'schedule',
            'fields' => [
                'heading' => self::t('Our Day-Long', 'দিনব্যাপী আমাদের'),
                'heading_accent' => self::t('Programme', 'আয়োজন'),
                'view_all_label' => self::t('View full schedule', 'সম্পূর্ণ সূচি দেখুন'),
                'view_all_url' => '/event',
                'stops' => [
                    [
                        'time' => '08:00 AM',
                        'title' => self::t('Registration', 'রেজিস্ট্রেশন'),
                        'description' => self::t(
                            'Registration desk and welcome kits',
                            'রেজিস্ট্রেশন ডেস্ক ও কিট সংগ্রহ',
                        ),
                        'icon' => 'ClipboardList',
                        'tone' => 'gold',
                    ],
                    [
                        'time' => '09:30 AM',
                        'title' => self::t('Opening & Address', 'উদ্বোধনী ও ভাষণ'),
                        'description' => self::t(
                            'Opening remarks and words of welcome',
                            'উদ্বোধনী ঘোষণা ও শুভেচ্ছা আমন্ত্রণ',
                        ),
                        'icon' => 'Mic',
                        'tone' => 'orange',
                    ],
                    [
                        'time' => '10:30 AM',
                        'title' => self::t('Campus Tour', 'ক্যাম্পাস ট্যুর'),
                        'description' => self::t(
                            'A walk through the places we remember',
                            'স্মৃতিবিজড়িত স্থান ঘুরে দেখা',
                        ),
                        'icon' => 'Landmark',
                        'tone' => 'blue',
                    ],
                    [
                        'time' => '12:30 PM',
                        'title' => self::t('Lunch', 'দুপুরের খাবার'),
                        'description' => self::t('A shared, hearty midday meal', 'সুস্বাদু সমাগত ভোজ'),
                        'icon' => 'UtensilsCrossed',
                        'tone' => 'rose',
                    ],
                    [
                        'time' => '02:30 PM',
                        'title' => self::t('Cultural Programme', 'সাংস্কৃতিক অনুষ্ঠান'),
                        'description' => self::t(
                            'Dance, music and cultural performances',
                            'নাচ, গান ও সাংস্কৃতিক পরিবেশনা',
                        ),
                        'icon' => 'Music',
                        'tone' => 'gold',
                    ],
                    [
                        'time' => '04:30 PM',
                        'title' => self::t('Honours & Awards', 'সম্মাননা ও পুরস্কার'),
                        'description' => self::t(
                            'Recognising the achievements of our own',
                            'গুণীজনদের কৃতিত্বের পুরস্কার প্রদান',
                        ),
                        'icon' => 'Award',
                        'tone' => 'orange',
                    ],
                    [
                        'time' => '07:00 PM',
                        'title' => self::t('Gala Dinner', 'গালা ডিনার'),
                        'description' => self::t(
                            'An evening of festivity and reunion',
                            'উৎসবমুখর ডিনার ও আড্ডা',
                        ),
                        'icon' => 'Bell',
                        'tone' => 'blue',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "08 · Gallery" (node 32:491). The strip's rotation and overlap are
     * fixed by the design, so a row only carries the picture and its year pill.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function gallery(): array
    {
        return [
            'type' => 'gallery',
            'fields' => [
                'eyebrow' => self::t('Album', 'স্মৃতির অ্যালবাম'),
                'heading' => self::t('Memories through the years', 'বছরের পর বছরের স্মৃতি'),
                'album_slug' => '',
                'view_all_label' => self::t('View full gallery', 'সম্পূর্ণ গ্যালারি'),
                'view_all_url' => '/gallery',
                'photos' => [
                    ['year' => self::t('1927', '১৯২৭'), 'image' => '/images/home/gallery/1927.jpg'],
                    ['year' => self::t('1946', '১৯৪৬'), 'image' => '/images/home/gallery/1946.jpg'],
                    ['year' => self::t('1968', '১৯৬৮'), 'image' => '/images/home/gallery/1968.jpg'],
                    ['year' => self::t('1985', '১৯৮৫'), 'image' => '/images/home/gallery/1985.jpg'],
                    ['year' => self::t('Present Day', 'বর্তমান'), 'image' => '/images/home/gallery/present.jpg'],
                ],
            ],
        ];
    }

    /**
     * Figma "09 · Testimonials" (node 32:520).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function testimonials(): array
    {
        return [
            'type' => 'testimonial_carousel',
            'fields' => [
                'eyebrow' => self::t('In Their Words', 'অ্যালামনাইদের কথা'),
                'heading_dark' => self::t('Our Centennial,', 'তাদের চোখে আমাদের'),
                'heading_accent' => self::t('Through Their Eyes', 'শতবর্ষ'),
                'testimonials' => [
                    [
                        'quote' => self::t(
                            "This school didn't just educate us — it taught us how to be human. The centennial isn't just an event; it's a homecoming of our pride and our love.",
                            'এই স্কুল আমাদের শুধু শিক্ষা দেয়নি, মানুষ হতে শিখিয়েছে। শতবর্ষ উদযাপন শুধু একটি অনুষ্ঠান নয়, এটি আমাদের গৌরব ও ভালোবাসার মিলনমেলা।',
                        ),
                        'name' => self::t('Sayemur Rahman', 'সায়েমুর রহমান'),
                        'title' => self::t('Managing Director, BRAC Bank', 'Managing Director, BRAC Bank'),
                        'batch_year' => '1996',
                        'image' => '/images/home/testimonials/sayemur.jpg',
                    ],
                    [
                        'quote' => self::t(
                            'The dream I first had on this campus fifty years ago, I see coming true today at our centennial. This school is the foundation of our lives.',
                            'পঞ্চাশ বছর আগে এই ক্যাম্পাসে যে স্বপ্ন দেখেছিলাম, আজ শতবর্ষে এসে তা বাস্তব হতে দেখছি। এই বিদ্যালয় আমাদের জীবনের ভিত্তি।',
                        ),
                        'name' => self::t('Eng. Mohammad Selim', 'ইঞ্জি. মোহাম্মদ সেলিম'),
                        'title' => self::t('Chairman, ABC Group', 'চেয়ারম্যান, ABC Group'),
                        'batch_year' => '1978',
                        'image' => '/images/home/guests/selim.jpg',
                    ],
                    [
                        'quote' => self::t(
                            "It was our teachers' tireless work and our parents' trust that brought us to where we stand today. The centennial is a day to honour that debt.",
                            'শিক্ষকদের অক্লান্ত পরিশ্রম আর অভিভাবকদের আস্থাই আমাদের আজকের অবস্থানে পৌঁছে দিয়েছে। শতবর্ষ এই ঋণ স্বীকারের দিন।',
                        ),
                        'name' => self::t('Dr. Nasima Sultana', 'ড. নাসিমা সুলতানা'),
                        'title' => self::t('Director, Directorate General of Health', 'পরিচালক, স্বাস্থ্য অধিদপ্তর'),
                        'batch_year' => '1989',
                        'image' => '/images/home/guests/nasima.jpg',
                    ],
                    [
                        'quote' => self::t(
                            "Everything I've built stands on what I learned in this school. Every alumnus owes it to themselves to come back for this reunion.",
                            'এই বিদ্যালয়ে যা শিখেছি, তার ওপর দাঁড়িয়েই আজকের পথচলা। প্রতিটি প্রাক্তন শিক্ষার্থীর উচিত এই মিলনমেলায় ফিরে আসা।',
                        ),
                        'name' => self::t('Goutik Ahmed', 'গৌতিক আহমেদ'),
                        'title' => self::t('CEO, Grameenphone', 'CEO, Grameenphone'),
                        'batch_year' => '1994',
                        'image' => '/images/home/guests/goutik.jpg',
                    ],
                    [
                        'quote' => self::t(
                            'The courage and the opportunity this school created for its girls still guide me today. Standing at its centennial, that gratitude is what I carry most.',
                            'মেয়েদের জন্য এই বিদ্যালয় যে সাহস আর সুযোগ তৈরি করেছে, তা আজও আমাকে পথ দেখায়। শতবর্ষে দাঁড়িয়ে সেই কৃতজ্ঞতাই সবচেয়ে বড়।',
                        ),
                        'name' => self::t('Shamima Afrin', 'শামিমা আফরিন'),
                        'title' => self::t('Director, bKash Limited', 'Director, bKash Limited'),
                        'batch_year' => '2000',
                        'image' => '/images/home/guests/shamima.jpg',
                    ],
                    [
                        'quote' => self::t(
                            'The friendships built outside the classroom — on the field, in the corridors — are what taught me to take risks. A hundred years on, that bond holds.',
                            'ক্লাসরুমের বাইরে মাঠে-বারান্দায় যে বন্ধুত্ব গড়ে উঠেছিল, সেটাই আমাকে ঝুঁকি নিতে শিখিয়েছে। একশ বছর পরেও এই বন্ধন অটুট।',
                        ),
                        'name' => self::t('Rakibul Hasan', 'রাকিবুল হাসান'),
                        'title' => self::t('Co-founder, Pathao', 'Co-founder, Pathao'),
                        'batch_year' => '2006',
                        'image' => '/images/home/guests/rakibul.jpg',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "10 · Pricing" (node 32:543) — the homepage *teaser*. The live,
     * priced grid on /tickets comes from the ticket types, and these two cards
     * must never be mistaken for it: they are copy, not a price source.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function pricing(): array
    {
        return [
            'type' => 'pricing_teaser',
            'fields' => [
                'eyebrow' => self::t('Registration Packages', 'নিবন্ধন প্যাকেজ'),
                'heading_dark' => self::t('The Best Option', 'আপনার জন্য সেরা অপশনটি'),
                'heading_accent' => self::t('For You', 'বেছে নিন'),
                'cta_label' => self::t('Register Now', 'এখনই রেজিস্ট্রেশন করুন'),
                'cta_url' => '/tickets',
                'popular_label' => self::t('Most Popular', 'সবচেয়ে জনপ্রিয়'),
                'footnote' => self::t(
                    '* No fee applies for children under 3 years of age.',
                    '* ৩ বছরের নিচের শিশুদের জন্য কোনো ফি প্রযোজ্য নয়।',
                ),
                'plans' => [
                    [
                        'title' => self::t('Single Registration', 'সিঙ্গেল রেজিস্ট্রেশন'),
                        'subtitle' => self::t('One attendee', 'এক অংশগ্রহণ'),
                        'price' => '৳ ২,০০০',
                        'features' => self::t(
                            "Event admission\nWelcome kit\nLunch included\nCommemorative t-shirt",
                            "অনুষ্ঠানে অংশগ্রহণ\nওয়েলকাম কিট\nদুপুরের খাবার\nস্মারক টি-শার্ট",
                        ),
                        'image' => '/images/home/pricing/single.jpg',
                        'tone' => 'violet',
                        'popular' => '',
                    ],
                    [
                        'title' => self::t('Family Registration', 'ফ্যামিলি রেজিস্ট্রেশন'),
                        'subtitle' => self::t('Bring the family', 'পরিবারসহ অংশগ্রহণ'),
                        'price' => '৳ ৪,৫০০',
                        'features' => self::t(
                            "Event admission (whole family)\nCovers every family member\nT-shirts for everyone\nGala dinner",
                            "অনুষ্ঠানে অংশগ্রহণ (পরিবারসহ)\nপরিবারের সদস্যদের জন্য\nটি-শার্ট (সবার জন্য)\nগালা ডিনার",
                        ),
                        'image' => '/images/home/pricing/family.jpg',
                        'tone' => 'amber',
                        'popular' => 'yes',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "11 · Sponsors" (node 32:551) — typographic logo cards. No logo art
     * came back from the design (every mark renders as styled type there too),
     * so a row carries a wordmark and its colours rather than an image.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function sponsors(): array
    {
        return [
            'type' => 'sponsor_grid',
            'fields' => [
                'heading' => self::t('Our Partners &', 'সহযোগী ও'),
                'heading_accent' => self::t('Sponsors', 'পৃষ্ঠপোষকেরা'),
                'tier' => '',
                'logos' => [
                    [
                        'mark' => self::t('BRAC BANK', 'BRAC BANK'),
                        'tagline' => self::t('আপনার বিশ্বাসে', 'আপনার বিশ্বাসে'),
                        'text_color' => '#1b3a93',
                        'dot_color' => '',
                    ],
                    [
                        'mark' => self::t('Grameenphone', 'Grameenphone'),
                        'tagline' => self::t('', ''),
                        'text_color' => '#0f172a',
                        'dot_color' => '#00a651',
                    ],
                    [
                        'mark' => self::t('bKash', 'bKash'),
                        'tagline' => self::t('', ''),
                        'text_color' => '#e2136e',
                        'dot_color' => '',
                    ],
                    [
                        'mark' => self::t('Robi', 'রবি'),
                        'tagline' => self::t('', ''),
                        'text_color' => '#e11d48',
                        'dot_color' => '',
                    ],
                    [
                        'mark' => self::t('HSBC', 'HSBC'),
                        'tagline' => self::t('একসাথে এগিয়ে', 'একসাথে এগিয়ে'),
                        'text_color' => '#111827',
                        'dot_color' => '',
                    ],
                    [
                        'mark' => self::t('Prothom Alo', 'প্রথম আলো'),
                        'tagline' => self::t('', ''),
                        'text_color' => '#111827',
                        'dot_color' => '',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "12 · FAQ" (node 32:582) — the six-question teaser grid.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function faqs(): array
    {
        return [
            'type' => 'faq_list',
            'fields' => [
                'eyebrow' => self::t('Frequently Asked', 'সাধারণ জিজ্ঞাসা'),
                'heading' => self::t('Your Questions,', 'আপনার প্রশ্ন আমাদের'),
                'heading_accent' => self::t('Our Answers', 'উত্তর'),
                'category' => '',
                'items' => [
                    [
                        'question' => self::t(
                            'When is the registration deadline?',
                            'নিবন্ধনের শেষ তারিখ কখন?',
                        ),
                        'answer' => self::t(
                            'Seats are limited and allotted first-come, first-served. We recommend registering as early as possible.',
                            'আসন সংখ্যা সীমিত এবং প্রথম আসা প্রথম পাওয়া ভিত্তিতে বরাদ্দ হবে। যত দ্রুত সম্ভব নিবন্ধন করার পরামর্শ দেওয়া হচ্ছে।',
                        ),
                        'icon' => 'CalendarClock',
                        'tone' => 'gold',
                    ],
                    [
                        'question' => self::t(
                            'What does registration include?',
                            'কি কি সুবিধা থাকবে নিবন্ধনে?',
                        ),
                        'answer' => self::t(
                            'Every ticket includes a welcome kit, lunch and a commemorative t-shirt.',
                            'প্রতিটি টিকিটে থাকছে একটি ওয়েলকাম কিট, দুপুরের খাবার ও একটি স্মারক টি-শার্ট।',
                        ),
                        'icon' => 'Gift',
                        'tone' => 'maroon',
                    ],
                    [
                        'question' => self::t(
                            'How do I add family members?',
                            'পরিবারের সদস্য সংখ্যা কিভাবে যোগ করবো?',
                        ),
                        'answer' => self::t(
                            'On step three of the registration form, you can add the name and age of every family member joining you.',
                            'নিবন্ধন ফর্মের তৃতীয় ধাপে আপনি সঙ্গে আসা প্রতিটি সদস্যের নাম ও বয়স যোগ করতে পারবেন।',
                        ),
                        'icon' => 'Users',
                        'tone' => 'blue',
                    ],
                    [
                        'question' => self::t('How do I make the payment?', 'পেমেন্ট কিভাবে করতে হবে?'),
                        'answer' => self::t(
                            'Payment is accepted online via bKash, Nagad, Rocket and major credit/debit cards.',
                            'বিকাশ, নগদ, রকেট ও প্রধান ক্রেডিট/ডেবিট কার্ডের মাধ্যমে অনলাইনে পেমেন্ট করা যাবে।',
                        ),
                        'icon' => 'CreditCard',
                        'tone' => 'green',
                    ],
                    [
                        'question' => self::t('What is the refund policy?', 'রিফান্ড নীতি কি?'),
                        'answer' => self::t(
                            'Full refunds are available up to 14 days before the event. Partial refunds apply after that.',
                            'অনুষ্ঠানের ১৪ দিন আগ পর্যন্ত সম্পূর্ণ রিফান্ড পাওয়া যাবে। এরপর আংশিক রিফান্ড প্রযোজ্য।',
                        ),
                        'icon' => 'RotateCcw',
                        'tone' => 'teal',
                    ],
                    [
                        'question' => self::t(
                            'What should I bring on the day?',
                            'অনুষ্ঠানের দিন কি আনতে হবে?',
                        ),
                        'answer' => self::t(
                            "Bring your ticket's QR code (on your phone or printed) along with a photo ID.",
                            'আপনার টিকিটের QR কোড (মোবাইলে বা প্রিন্ট করা) ও একটি ছবিসহ পরিচয়পত্র সাথে আনুন।',
                        ),
                        'icon' => 'SquareCheck',
                        'tone' => 'indigo',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "13 · CTA Banner" (node 32:805).
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
