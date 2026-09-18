<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Souvenir page — the centennial's স্মরণিকা, the commemorative *book*,
 * not merchandise — block by block, on the same contract as
 * {@see HomePageSeeder}: every value is the copy the public site ships with.
 *
 * Seven sections, in the order of the Figma "Souvenir — Desktop 1440" frame
 * (node `114:816`): Page Hero, Book Preview, Contents, Call For Writing,
 * Editorial Board, Get a Copy, CTA Banner.
 *
 * The prices under "Get a Copy" are display copy from the design. Unlike the
 * ticket page there is no `ticket_types` row behind them yet — when the book
 * goes on sale for real, the price should move to a server-side home and
 * these fields should quote it, not the other way round.
 */
class SouvenirPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'souvenir'],
            [
                'template' => 'landing',
                'title' => 'Souvenir Book',
                'title_bn' => 'স্মরণিকা',
                'excerpt' => 'The centennial commemorative souvenir book — contents, contributors and how to get a copy.',
                'excerpt_bn' => 'শতবর্ষের স্মারকগ্রন্থ — বিষয়সূচি, লেখক ও সংগ্রহের উপায়।',
                'seo_title' => 'Souvenir Book',
                'seo_title_bn' => 'স্মরণিকা',
                'seo_description' => 'From the founding papers to today\'s campus — a complete commemorative volume gathering a hundred years of stories, memories and writing.',
                'seo_description_bn' => 'প্রতিষ্ঠার দলিল থেকে আজকের ক্যাম্পাস পর্যন্ত — একশ বছরের গল্প, স্মৃতি ও লেখা একত্র করা একটি পূর্ণাঙ্গ স্মারকগ্রন্থ।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 4,
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
            $this->bookPreview(),
            $this->contents(),
            $this->callForWriting(),
            $this->editorialBoard(),
            $this->getACopy(),
            $this->ctaBanner(),
        ];
    }

    /**
     * Figma "01 · Page Hero" (node 114:817).
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function hero(): array
    {
        return [
            'type' => 'souvenir_hero',
            'fields' => [
                'breadcrumb' => self::t('Souvenir Book', 'স্মরণিকা'),
                'eyebrow' => self::t('The Centennial Commemorative Book', 'একশ বছরের স্মারকগ্রন্থ'),
                'heading_lead' => self::t('The Centennial', 'শতবর্ষ'),
                'heading_accent' => self::t('Souvenir Book', 'স্মরণিকা'),
                'body' => self::t(
                    "From the founding papers to today's campus — a complete commemorative volume gathering a hundred years of stories, memories and writing.",
                    'প্রতিষ্ঠার দলিল থেকে আজকের ক্যাম্পাস পর্যন্ত — একশ বছরের গল্প, স্মৃতি ও লেখা একত্র করা একটি পূর্ণাঙ্গ স্মারকগ্রন্থ।',
                ),
                'facts' => [
                    ['icon' => 'BookOpen', 'label' => self::t('Pages', 'পৃষ্ঠাসংখ্যা'), 'value' => self::t('320+', '৩২০+')],
                    ['icon' => 'PenLine', 'label' => self::t('Compiled Writings', 'সংকলিত লেখা'), 'value' => self::t('86', '৮৬টি')],
                    ['icon' => 'CalendarDays', 'label' => self::t('Publish Date', 'প্রকাশকাল'), 'value' => self::t('13 March 2027', '১৩ মার্চ ২০২৭')],
                ],
            ],
        ];
    }

    /**
     * Figma "02 · Book Preview" (node 114:818). The cover is drawn in CSS
     * from the five `cover_*` strings; the design shipped no cover image.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function bookPreview(): array
    {
        return [
            'type' => 'book_preview',
            'fields' => [
                // The printed cover is Bangla whichever language the reader
                // picked — it is a picture of the book, not page copy — so
                // these are written identically to both halves.
                'cover_school' => 'নামোশংকরবাটী উচ্চ বিদ্যালয়',
                'cover_kicker' => 'শতবর্ষ',
                'cover_title' => 'স্মরণিকা',
                'cover_years' => '১৯২৭ – ২০২৭',
                'cover_footer' => 'স্মারক সংখ্যা · ফেব্রুয়ারি ২০২৭',
                'eyebrow' => self::t('About the Book', 'স্মারকগ্রন্থ সম্পর্কে'),
                'heading_dark' => self::t("A Hundred Years' Story,", 'একশ বছরের গল্প,'),
                'heading_accent' => self::t('In One Cover', 'এক মলাটে'),
                'body' => self::t(
                    "From the founding papers to today's students' dreams — the centennial souvenir book holds the school's complete history, a list of every headmaster, alumni memoirs, selected essays and poems, and rare photographs chosen from the archive.",
                    'প্রতিষ্ঠার দলিল থেকে শুরু করে আজকের শিক্ষার্থীদের স্বপ্ন — শতবর্ষ স্মরণিকায় থাকছে বিদ্যালয়ের পূর্ণাঙ্গ ইতিহাস, প্রধান শিক্ষকদের তালিকা, প্রাক্তন শিক্ষার্থীদের স্মৃতিচারণ, নির্বাচিত প্রবন্ধ ও কবিতা, এবং আর্কাইভ থেকে বাছাই করা দুর্লভ আলোকচিত্র।',
                ),
                'specs' => [
                    ['label' => self::t('Page Count', 'পৃষ্ঠাসংখ্যা'), 'value' => self::t('320+ pages', '৩২০+ পৃষ্ঠা')],
                    ['label' => self::t('Size & Binding', 'আকার ও বাঁধাই'), 'value' => self::t('8.5″ × 11″, Hardcover', '৮.৫″ × ১১″, হার্ডকভার')],
                    ['label' => self::t('Language', 'ভাষা'), 'value' => self::t('Bangla & English', 'বাংলা ও ইংরেজি')],
                    ['label' => self::t('Print Run', 'মুদ্রণসংখ্যা'), 'value' => self::t('1,500 copies (Limited)', '১,৫০০ কপি (সীমিত)')],
                ],
                'primary_label' => self::t('View Sample Pages', 'নমুনা পাতা দেখুন'),
                'primary_url' => '#get-a-copy',
                'secondary_label' => self::t('Pre-order Now', 'প্রি-অর্ডার করুন'),
                'secondary_url' => '#get-a-copy',
            ],
        ];
    }

    /**
     * Figma "03 · Contents".
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function contents(): array
    {
        return [
            'type' => 'book_contents',
            'fields' => [
                'eyebrow' => self::t("What's Inside", 'ভেতরে কী থাকছে'),
                'heading_dark' => self::t('Table of', 'স্মরণিকার'),
                'heading_accent' => self::t('Contents', 'বিষয়সূচি'),
                'chapters' => [
                    ['number' => self::t('01', '০১'), 'icon' => 'ScrollText', 'title' => self::t('The Founding History', 'প্রতিষ্ঠার ইতিহাস'), 'body' => self::t('The 1927 papers, the land-donor family, and the story of the first schoolroom.', '১৯২৭ সালের দলিল, জমিদাতা পরিবার ও প্রথম পাঠশালার গল্প।'), 'pages' => self::t('42 pages', '৪২ পৃষ্ঠা')],
                    ['number' => self::t('02', '০২'), 'icon' => 'GraduationCap', 'title' => self::t('A Lineage of Headmasters', 'প্রধান শিক্ষকদের পরম্পরা'), 'body' => self::t('Profiles of every headmaster who has served across the century.', 'শতবর্ষজুড়ে দায়িত্ব পালনকারী সকল প্রধান শিক্ষকের পরিচিতি।'), 'pages' => self::t('28 pages', '২৮ পৃষ্ঠা')],
                    ['number' => self::t('03', '০৩'), 'icon' => 'Users', 'title' => self::t("Through a Teacher's Eyes", 'শিক্ষকের চোখে'), 'body' => self::t('Reflections from retired and current teachers.', 'অবসরপ্রাপ্ত ও বর্তমান শিক্ষকদের স্মৃতিচারণ ও মূল্যায়ন।'), 'pages' => self::t('36 pages', '৩৬ পৃষ্ঠা')],
                    ['number' => self::t('04', '০৪'), 'icon' => 'Feather', 'title' => self::t('Writing by Our Alumni', 'প্রাক্তন শিক্ষার্থীদের লেখা'), 'body' => self::t('Memoirs, essays and poems sent from home and abroad.', 'দেশ-বিদেশ থেকে পাঠানো স্মৃতিকথা, প্রবন্ধ ও কবিতা।'), 'pages' => self::t('96 pages', '৯৬ পৃষ্ঠা')],
                    ['number' => self::t('05', '০৫'), 'icon' => 'Camera', 'title' => self::t('A Century in Photographs', 'আলোকচিত্রে শতবর্ষ'), 'body' => self::t('A collection of rare photographs selected from the archive.', 'আর্কাইভ থেকে বাছাই করা দুর্লভ ছবির সংকলন।'), 'pages' => self::t('64 pages', '৬৪ পৃষ্ঠা')],
                    ['number' => self::t('06', '০৬'), 'icon' => 'BookText', 'title' => self::t('Records & Statistics', 'পরিসংখ্যান ও তালিকা'), 'body' => self::t('Batch-wise student rolls, results and notable achievements.', 'ব্যাচভিত্তিক শিক্ষার্থী তালিকা, ফলাফল ও উল্লেখযোগ্য অর্জন।'), 'pages' => self::t('54 pages', '৫৪ পৃষ্ঠা')],
                ],
            ],
        ];
    }

    /**
     * Figma "04 · Call For Writing". The "days left" badge is copy, not a
     * countdown — an editor updates it, or blanks it to hide it.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function callForWriting(): array
    {
        return [
            'type' => 'call_for_writing',
            'fields' => [
                'eyebrow' => self::t('Submit Your Writing', 'আপনার লেখা পাঠান'),
                'heading_dark' => self::t('Write for the', 'স্মরণিকায়'),
                'heading_accent' => self::t('Souvenir Book', 'লিখুন'),
                'deadline_title' => self::t('Submission deadline — 30 September 2026', 'লেখা জমা দেওয়ার শেষ তারিখ — ৩০ সেপ্টেম্বর ২০২৬'),
                'deadline_body' => self::t(
                    "Selected writing will be chosen by the editorial board and published with the author's name and batch year.",
                    'নির্বাচিত লেখা সম্পাদনা পরিষদ কর্তৃক মনোনীত হবে এবং লেখকের নাম ও ব্যাচসহ স্মরণিকায় প্রকাশিত হবে।',
                ),
                'deadline_badge' => self::t('47 days left', 'সময় বাকি ৪৭ দিন'),
                'categories' => [
                    ['icon' => 'Feather', 'title' => self::t('Memoirs', 'স্মৃতিচারণ'), 'body' => self::t('Personal memories and experiences from your school days', 'বিদ্যালয়জীবনের ব্যক্তিগত স্মৃতি ও অভিজ্ঞতা'), 'limit' => self::t('800–1200 words', '৮০০–১২০০ শব্দ')],
                    ['icon' => 'ScrollText', 'title' => self::t('Essays & Research', 'প্রবন্ধ ও গবেষণা'), 'body' => self::t('Analytical writing on education, history and society', 'শিক্ষা, ইতিহাস ও সমাজ নিয়ে বিশ্লেষণধর্মী লেখা'), 'limit' => self::t('1500–2500 words', '১৫০০–২৫০০ শব্দ')],
                    ['icon' => 'BookText', 'title' => self::t('Poems & Verse', 'কবিতা ও ছড়া'), 'body' => self::t('Verse written around the centennial and the school', 'শতবর্ষ ও বিদ্যালয়কে ঘিরে লেখা পদ্য'), 'limit' => self::t('Up to 40 lines', 'সর্বোচ্চ ৪০ পঙ্‌ক্তি')],
                    ['icon' => 'Camera', 'title' => self::t('Photos & Documents', 'ছবি ও দলিল'), 'body' => self::t('Old photographs, certificates or handwritten documents', 'পুরোনো আলোকচিত্র, সনদ বা হাতে লেখা নথি'), 'limit' => self::t('300 DPI scan', '৩০০ ডিপিআই স্ক্যান')],
                ],
            ],
        ];
    }

    /**
     * Figma "05 · Editorial Board". Portraits reuse the homepage guest
     * photos — the design shipped no separate board portraits.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function editorialBoard(): array
    {
        return [
            'type' => 'editorial_board',
            'fields' => [
                'eyebrow' => self::t('Behind the Editing', 'যাদের হাতে সম্পাদনা'),
                'heading_dark' => self::t('The Editorial', 'সম্পাদনা'),
                'heading_accent' => self::t('Board', 'পরিষদ'),
                'members' => [
                    ['image' => '/images/home/guests/selim.jpg', 'name' => self::t('Prof. Abdul Karim', 'অধ্যাপক আব্দুল করিম'), 'role' => self::t('Editor-in-Chief', 'প্রধান সম্পাদক')],
                    ['image' => '/images/home/guests/nasima.jpg', 'name' => self::t('Mosa. Rehana Parvin', 'মোছা. রেহানা পারভীন'), 'role' => self::t('Associate Editor', 'সহকারী সম্পাদক')],
                    ['image' => '/images/home/guests/rakibul.jpg', 'name' => self::t('Dr. Shafiqul Islam', 'ড. শফিকুল ইসলাম'), 'role' => self::t('Research Editor', 'গবেষণা সম্পাদক')],
                    ['image' => '/images/home/guests/goutik.jpg', 'name' => self::t('Md. Almgir Hossain', 'মো. আলমগীর হোসেন'), 'role' => self::t('Photo Editor', 'আলোকচিত্র সম্পাদক')],
                    ['image' => '/images/home/guests/shamima.jpg', 'name' => self::t('Farhana Yasmin', 'ফারহানা ইয়াসমিন'), 'role' => self::t('Board Member', 'সদস্য সম্পাদক')],
                    ['image' => '/images/home/guests/sayemur.jpg', 'name' => self::t('Tanvir Ahmed', 'তানভীর আহমেদ'), 'role' => self::t('Board Member', 'সদস্য সম্পাদক')],
                ],
            ],
        ];
    }

    /**
     * Figma "06 · Get A Copy". Features are one per line in a single
     * textarea — the same convention as the homepage's pricing plans.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function getACopy(): array
    {
        return [
            'type' => 'get_a_copy',
            'fields' => [
                'eyebrow' => self::t('Get Your Copy', 'সংগ্রহ করুন'),
                'heading_dark' => self::t('How to Get the', 'কীভাবে পাবেন'),
                'heading_accent' => self::t('Souvenir Book', 'স্মরণিকা'),
                'popular_label' => self::t('Most Popular', 'সবচেয়ে জনপ্রিয়'),
                'footnote' => self::t(
                    '* International shipping is charged separately — contact us for details.',
                    '* বিদেশে পাঠানোর ক্ষেত্রে আলাদা চার্জ প্রযোজ্য — বিস্তারিত জানতে যোগাযোগ করুন।',
                ),
                'options' => [
                    [
                        'title' => self::t('Collect on Event Day', 'উৎসবের দিন সংগ্রহ'),
                        'subtitle' => self::t('For registered guests', 'নিবন্ধিত অতিথিদের জন্য'),
                        'price' => self::t('Free', 'ফ্রি'),
                        'features' => self::t(
                            "1 copy for every registered guest\nCollect from the registration desk\nShow your ticket QR to collect",
                            "নিবন্ধিত প্রতিটি অতিথির জন্য ১ কপি\nরেজিস্ট্রেশন ডেস্ক থেকে সংগ্রহ\nটিকিটের QR দেখিয়ে নিন",
                        ),
                        'cta_label' => self::t('Get Your Ticket', 'টিকিট নিন'),
                        'cta_url' => '/tickets',
                        'highlighted' => '',
                    ],
                    [
                        'title' => self::t('Pre-order', 'প্রি-অর্ডার'),
                        'subtitle' => self::t('For extra copies', 'অতিরিক্ত কপির জন্য'),
                        'price' => self::t('৳ 800', '৳ ৮০০'),
                        'features' => self::t(
                            "Limited edition with a commemorative number\nExtra copies confirmed in advance\nCollected in person on event day",
                            "স্মারক নম্বরসহ সীমিত সংস্করণ\nঅতিরিক্ত কপি আগেই নিশ্চিত\nউৎসবের দিন হাতে পাবেন",
                        ),
                        'cta_label' => self::t('Pre-order Now', 'প্রি-অর্ডার করুন'),
                        'cta_url' => '/tickets',
                        'highlighted' => 'yes',
                    ],
                    [
                        'title' => self::t('Courier Delivery', 'কুরিয়ারে ডেলিভারি'),
                        'subtitle' => self::t('Delivery charge included', 'ডেলিভারি চার্জসহ'),
                        'price' => self::t('৳ 950', '৳ ৯৫০'),
                        'features' => self::t(
                            "Delivered anywhere in the country\nWithin two weeks of the event\nTracking number sent by SMS",
                            "দেশের যেকোনো প্রান্তে পৌঁছে যাবে\nউৎসবের দুই সপ্তাহের মধ্যে\nট্র্যাকিং নম্বর SMS-এ পাবেন",
                        ),
                        'cta_label' => self::t('Enter Address', 'ঠিকানা দিন'),
                        'cta_url' => '/contact',
                        'highlighted' => '',
                    ],
                ],
            ],
        ];
    }

    /**
     * The shared CTA symbol, with this page's own "your pen" copy.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function ctaBanner(): array
    {
        return [
            'type' => 'cta_banner',
            'fields' => [
                'eyebrow' => self::t('Your Pen in the Souvenir Book', 'স্মারকগ্রন্থে আপনার কলম'),
                'heading_line1' => self::t('Let Your Writing Live On', 'আপনার লেখাই থাকুক'),
                'heading_accent' => self::t('in the Centennial', 'শতবর্ষের'),
                'heading_line2' => self::t('Pages', 'পাতায়'),
                'body' => self::t(
                    'Send your memoir, essay or poem by 30 September 2026 — selected writing will be printed in the souvenir book.',
                    'স্মৃতিচারণ, প্রবন্ধ কিংবা কবিতা পাঠান ৩০ সেপ্টেম্বর ২০২৬-এর মধ্যে — নির্বাচিত লেখা ছাপা হবে স্মরণিকায়।',
                ),
                'primary_label' => self::t('Submit Your Writing', 'লেখা পাঠান'),
                'primary_url' => 'mailto:alumni@nsbatihighschool.edu.bd',
                'secondary_label' => self::t('View Guidelines', 'নির্দেশিকা দেখুন'),
                'secondary_url' => '#get-a-copy',
            ],
        ];
    }
}
