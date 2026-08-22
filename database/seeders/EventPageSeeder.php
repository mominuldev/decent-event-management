<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Events page, block by block — the third of these after
 * {@see HomePageSeeder} and {@see HistoryPageSeeder}, and on the same terms:
 * every value is the copy the public site ships with, verbatim and bilingual,
 * so an editor opening the page finds the designed page rather than an empty
 * form.
 *
 * Seven sections, in the order of the Figma "Events — Desktop 1440" frame
 * (node `57:710`): Page Hero, At a Glance, Full Schedule, Attractions,
 * Guests, Venue & Directions, CTA Banner. Three of those use the homepage's
 * own block types — `attraction_grid`, `guest_carousel` and `cta_banner` —
 * because the design uses one symbol for each across both frames, and their
 * copy is duplicated here deliberately: two pages showing the same guests
 * should still be independently editable.
 *
 * The programme lives in two places on this page by design — the "At a
 * Glance" rail is a summary of the "Full Schedule" below it, not a second
 * source. Keep the two in step when editing.
 *
 * Note this page's schedule is page content, distinct from the admin's
 * separate schedule resource (`/admin/schedule`), which feeds the generic
 * `schedule` block on editor-authored pages.
 */
class EventPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    /**
     * The event day, as Figma's own hero and schedule header set it — a
     * different placeholder from the homepage's Jan 1 stand-in, because this
     * page's frame explicitly draws 12 February. Times on the sessions below
     * are wall-clock Bangladesh time against this date.
     */
    private const EVENT_DATE = '2027-02-12';

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'event'],
            [
                'template' => 'landing',
                'title' => 'Programme',
                'title_bn' => 'অনুষ্ঠানাবলী',
                'excerpt' => "The full day's schedule, venue and directions for the centenary celebration.",
                'excerpt_bn' => 'শতবর্ষ উদযাপনের পুরো দিনের সূচি, স্থান ও দিকনির্দেশনা।',
                // Bare, no school name: the public site's root layout appends
                // "— <school> শতবর্ষ" to every page title, so repeating it here
                // renders the school twice in one <title>.
                'seo_title' => 'Programme',
                'seo_title_bn' => 'অনুষ্ঠানাবলী',
                'seo_description' => 'From the registration desk to the gala dinner — date, venue, and the full schedule for the centennial celebration.',
                'seo_description_bn' => 'নিবন্ধন ডেস্ক থেকে গালা ডিনার — শতবর্ষ উদযাপনের তারিখ, স্থান ও সম্পূর্ণ অনুষ্ঠানসূচি।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 2,
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
            $this->glance(),
            $this->fullSchedule(),
            $this->attractions(),
            $this->guests(),
            $this->venue(),
            $this->ctaBanner(),
        ];
    }

    /**
     * Figma "01 · Page Hero" (node 57:733).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function hero(): array
    {
        return [
            'type' => 'event_hero',
            'fields' => [
                'breadcrumb' => self::t('Programme', 'অনুষ্ঠানাবলী'),
                // En-dash, never a hyphen — the renderer localises the digits.
                'year_pill' => '1927–2027',
                'eyebrow' => self::t(
                    'One-day Festival · 8:00 AM – 10:00 PM',
                    'একদিনের উৎসব · সকাল ৮টা – রাত ১০টা',
                ),
                'heading_lead' => self::t('A Full Day of', 'দিনব্যাপী'),
                'heading_accent' => self::t('Programme', 'অনুষ্ঠানাবলী'),
                'body' => self::t(
                    "From the registration desk to the gala dinner — the full day's schedule for the centennial celebration. Time, venue and details of every event all in one place.",
                    'নিবন্ধন ডেস্ক থেকে গালা ডিনার — শতবর্ষ উদযাপনের পুরো দিনের সূচি। প্রতিটি পর্বের সময়, স্থান ও বিস্তারিত এখানে একসাথে।',
                ),
                'facts' => [
                    [
                        'label' => self::t('Date', 'তারিখ'),
                        'value' => self::t('12 February 2027, Friday', '১২ ফেব্রুয়ারি ২০২৭, শুক্রবার'),
                        'icon' => 'Calendar',
                        'tone' => 'violet',
                    ],
                    [
                        'label' => self::t('Venue', 'স্থান'),
                        'value' => self::t('Chapainawabganj Sadar, Chapainawabganj', 'চাঁপাইনবাবগঞ্জ সদর, চাঁপাইনবাবগঞ্জ'),
                        'icon' => 'MapPin',
                        'tone' => 'orange',
                    ],
                    [
                        'label' => self::t('Time', 'সময়'),
                        'value' => self::t('8:00 AM – 10:00 PM', 'সকাল ৮:০০ – রাত ১০:০০'),
                        'icon' => 'Clock',
                        'tone' => 'blue',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "02 · At a Glance" (node 58:721) — the summary rail. This
     * section's heading carries no eyebrow in the design.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function glance(): array
    {
        return [
            'type' => 'programme_glance',
            'fields' => [
                'heading_dark' => self::t('In Brief', 'সংক্ষেপে'),
                'heading_accent' => self::t("Day's Schedule", 'দিনের সূচি'),
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
     * Figma "03 · Full Schedule" (node 59:712).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function fullSchedule(): array
    {
        return [
            'type' => 'full_schedule',
            'fields' => [
                'eyebrow' => self::t('Detailed Schedule', 'বিস্তারিত সূচি'),
                'heading_dark' => self::t('The Full', 'সম্পূর্ণ'),
                'heading_accent' => self::t('Programme', 'অনুষ্ঠানসূচি'),
                'event_date' => self::EVENT_DATE,
                'items' => [
                    [
                        'start_time' => '08:00',
                        'end_time' => '09:30',
                        'title' => self::t('Registration', 'রেজিস্ট্রেশন'),
                        'description' => self::t(
                            'Register at the desk and collect your welcome kit and name badge.',
                            'রেজিস্ট্রেশন ডেস্ক থেকে নাম নিবন্ধন, ওয়েলকাম কিট ও পরিচয়পত্র সংগ্রহ করুন।',
                        ),
                        'track' => self::t('Registration', 'নিবন্ধন'),
                        'venue' => self::t('Main Gate', 'প্রধান ফটক'),
                        'tone' => 'purple',
                    ],
                    [
                        'start_time' => '09:30',
                        'end_time' => '10:30',
                        'title' => self::t('Opening & Address', 'উদ্বোধনী ও ভাষণ'),
                        'description' => self::t(
                            'Opening remarks, words of welcome and the lighting of the centenary lamp.',
                            'উদ্বোধনী ঘোষণা, শুভেচ্ছা বক্তব্য ও শতবর্ষের প্রদীপ প্রজ্জ্বলন।',
                        ),
                        'track' => self::t('Main Programme', 'মূল পর্ব'),
                        'venue' => self::t('Central Stage', 'কেন্দ্রীয় মঞ্চ'),
                        'tone' => 'orange',
                        'speaker_name' => self::t('Md. Aslam Kabir', 'মোঃ আসলাম কবীর'),
                        'speaker_title' => self::t('Headmaster', 'প্রধান শিক্ষক'),
                        'speaker_photo' => '/images/history/headmaster-portrait.png',
                    ],
                    [
                        'start_time' => '10:30',
                        'end_time' => '12:30',
                        'title' => self::t('Campus Tour', 'ক্যাম্পাস ট্যুর'),
                        'description' => self::t(
                            'Walk the classrooms, the playing field and the old buildings again.',
                            'স্মৃতিবিজড়িত শ্রেণিকক্ষ, খেলার মাঠ ও পুরনো ভবন ঘুরে দেখা।',
                        ),
                        'track' => self::t('Memories', 'স্মৃতি'),
                        'venue' => self::t('School Grounds', 'বিদ্যালয় প্রাঙ্গণ'),
                        'tone' => 'blue',
                    ],
                    [
                        'start_time' => '12:30',
                        'end_time' => '14:00',
                        'title' => self::t('Lunch', 'দুপুরের খাবার'),
                        'description' => self::t(
                            'Lunch together, for everyone.',
                            'সবার জন্য একসাথে দুপুরের ভোজ।',
                        ),
                        'track' => self::t('Hospitality', 'আপ্যায়ন'),
                        'venue' => self::t('Dining Hall', 'ভোজনশালা'),
                        'tone' => 'green',
                    ],
                    [
                        'start_time' => '14:30',
                        'end_time' => '16:30',
                        'title' => self::t('Cultural Programme', 'সাংস্কৃতিক অনুষ্ঠান'),
                        'description' => self::t(
                            'Dance, song and performances by our current students.',
                            'নাচ, গান ও বর্তমান শিক্ষার্থীদের সাংস্কৃতিক পরিবেশনা।',
                        ),
                        'track' => self::t('Culture', 'সংস্কৃতি'),
                        'venue' => self::t('Central Stage', 'কেন্দ্রীয় মঞ্চ'),
                        'tone' => 'pink',
                    ],
                    [
                        'start_time' => '16:30',
                        'end_time' => '18:00',
                        'title' => self::t('Honours & Awards', 'সম্মাননা ও পুরস্কার'),
                        'description' => self::t(
                            'Honouring distinguished guests and outstanding students.',
                            'গুণীজন ও কৃতী শিক্ষার্থীদের সম্মাননা প্রদান।',
                        ),
                        'track' => self::t('Honours', 'সম্মাননা'),
                        'venue' => self::t('Central Stage', 'কেন্দ্রীয় মঞ্চ'),
                        'tone' => 'amber',
                    ],
                    [
                        'start_time' => '19:00',
                        'end_time' => '22:00',
                        'title' => self::t('Gala Dinner', 'গালা ডিনার'),
                        'description' => self::t(
                            'A festive dinner, conversation and batch-by-batch gatherings.',
                            'উৎসবমুখর নৈশভোজ, আড্ডা ও ব্যাচভিত্তিক আয়োজন।',
                        ),
                        'track' => self::t('Hospitality', 'আপ্যায়ন'),
                        'venue' => self::t('Open Grounds', 'খোলা প্রাঙ্গণ'),
                        'tone' => 'green',
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "04 · Attractions" (node 60:711) — the homepage's own symbol and
     * block type, bound to the same six cards.
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
     * Figma "05 · Guests" (node 61:710) — again the homepage's symbol and
     * block type, bound to the same six guests.
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
     * Figma "06 · Venue & Directions" (node 61:743).
     *
     * The design's map layer is itself named "Map — PLACEHOLDER", so the
     * panel carries a label rather than an embed; `map_label` is that label.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function venue(): array
    {
        return [
            'type' => 'venue_directions',
            'fields' => [
                'eyebrow' => self::t('How to Get There', 'কীভাবে পৌঁছাবেন'),
                'heading_dark' => self::t('Venue &', 'স্থান ও'),
                'heading_accent' => self::t('Directions', 'দিকনির্দেশনা'),
                'map_label' => self::t('Map placeholder', 'মানচিত্র এখানে বসবে'),
                'venue_label' => self::t('Venue', 'অনুষ্ঠানস্থল'),
                'venue_name' => self::t('Namoshankarbati High School', 'নামোশংকরবাটী উচ্চ বিদ্যালয়'),
                'venue_address' => self::t('Chapainawabganj Sadar, Chapainawabganj', 'চাঁপাইনবাবগঞ্জ সদর, চাঁপাইনবাবগঞ্জ'),
                'maps_label' => self::t('View on Google Maps', 'গুগল ম্যাপে দেখুন'),
                'maps_url' => 'https://maps.google.com/?q=Namoshankarbati+High+School+Chapainawabganj',
                'notes' => [
                    [
                        'label' => self::t('Entrance', 'প্রবেশপথ'),
                        'body' => self::t(
                            'Enter through the main gate, ticket QR code must be shown',
                            'প্রধান ফটক দিয়ে প্রবেশ, টিকিট QR দেখাতে হবে',
                        ),
                    ],
                    [
                        'label' => self::t('Parking', 'পার্কিং'),
                        'body' => self::t(
                            'Parking available on the west side of school grounds',
                            'বিদ্যালয় মাঠের পশ্চিম পাশে গাড়ি রাখার ব্যবস্থা',
                        ),
                    ],
                    [
                        'label' => self::t('Help Desk', 'সহায়তা ডেস্ক'),
                        'body' => self::t(
                            'Beside the main gate, open from 8:00 AM',
                            'প্রধান ফটকের পাশে, সকাল ৮টা থেকে',
                        ),
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "07 · CTA Banner" — the shared symbol, bound to the same copy the
     * homepage and History pages carry.
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
