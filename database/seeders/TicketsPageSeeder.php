<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Tickets page, block by block, on the same contract as
 * {@see HomePageSeeder}: every value is the copy the public site ships with.
 *
 * Eight sections, in the order of the Figma "Tickets — Desktop 1440" frame:
 * Page Hero, Ticket Pricing, Pricing Rules, Registration (checklist + form),
 * How It Works, FAQ, CTA Banner.
 *
 * Deliberately holds **no money**. Prices, admit limits and the free-infant
 * age live on the `ticket_types` row {@see TicketTypeSeeder} writes (code
 * `CEN`), and reach the page through /public/ticket-types — so the card and
 * the worked-out rules can never disagree with the figure the server charges.
 * What is here is the material with no server-side home: headings, the
 * checklist, the walkthrough and the FAQ answers.
 */
class TicketsPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'tickets'],
            [
                'template' => 'landing',
                'title' => 'Tickets & Registration',
                'title_bn' => 'টিকিট ও নিবন্ধন',
                'excerpt' => 'One ticket for alumni, students, teachers and staff — bring your family if you like.',
                'excerpt_bn' => 'প্রাক্তন ও বর্তমান শিক্ষার্থী, শিক্ষক ও কর্মচারীদের জন্য একটিই টিকিট — চাইলে পরিবারসহ।',
                'seo_title' => 'Tickets & Registration',
                'seo_title_bn' => 'টিকিট ও নিবন্ধন',
                'seo_description' => 'Reserve your place at the 1927–2027 centennial celebration. One ticket for alumni, students, teachers and staff — bring your family if you like.',
                'seo_description_bn' => '১৯২৭–২০২৭ শতবর্ষ উদযাপনে আপনার আসন নিশ্চিত করুন। প্রাক্তন ও বর্তমান শিক্ষার্থী, শিক্ষক ও কর্মচারীদের জন্য একটিই টিকিট — চাইলে পরিবারসহ।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 5,
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
            $this->pricing(),
            $this->rules(),
            $this->registration(),
            $this->howItWorks(),
            $this->faqs(),
            $this->ctaBanner(),
        ];
    }

    /**
     * Figma "01 · Page Hero" (node 121:705). The countdown target is the
     * placeholder celebration date the frontend ships with — swap it here
     * for the confirmed one.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function hero(): array
    {
        return [
            'type' => 'tickets_hero',
            'fields' => [
                'breadcrumb' => self::t('Tickets', 'টিকিট'),
                'year_pill' => '1927–2027',
                'eyebrow' => self::t('Registration & tickets · seats limited', 'নিবন্ধন ও টিকিট · আসন সীমিত'),
                'heading_lead' => self::t('A hundred years,', 'একশ বছর,'),
                'heading_accent' => self::t('one homecoming', 'একটি ঘরে ফেরা'),
                'body' => self::t(
                    'Alumni of every batch from 1971 to 2026, along with current students, teachers and staff, are invited back to the grounds. Come on your own, or bring the whole family.',
                    '১৯৭১ থেকে ২০২৬ — সব ব্যাচের প্রাক্তন শিক্ষার্থী, বর্তমান শিক্ষার্থী, শিক্ষক ও কর্মচারী সবাই আমন্ত্রিত। একা আসুন, কিংবা পরিবারসহ।',
                ),
                'facts' => [
                    ['icon' => 'CalendarDays', 'label' => self::t('Date', 'তারিখ'), 'value' => self::t('12 February 2027, Friday', '১২ ফেব্রুয়ারি ২০২৭, শুক্রবার')],
                    ['icon' => 'MapPin', 'label' => self::t('Venue', 'স্থান'), 'value' => self::t('School Grounds, Main Campus', 'বিদ্যালয় প্রাঙ্গণ, মূল ক্যাম্পাস')],
                    ['icon' => 'Clock', 'label' => self::t('Time', 'সময়'), 'value' => self::t('8:00 AM – 10:00 PM', 'সকাল ৮:০০ – রাত ১০:০০')],
                ],
                'countdown_target' => '2027-02-12T09:00:00+06:00',
                'primary_label' => self::t('Start registration', 'নিবন্ধন শুরু করুন'),
                'primary_url' => '#register',
                'secondary_label' => self::t('See ticket prices', 'টিকিটের মূল্য দেখুন'),
                'secondary_url' => '#pricing',
            ],
        ];
    }

    /**
     * Figma "03 · Ticket Pricing" — the heading only; the card is live.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function pricing(): array
    {
        return [
            'type' => 'ticket_pricing',
            'fields' => [
                'eyebrow' => self::t('Tickets', 'টিকিট'),
                'heading_dark' => self::t('One ticket,', 'একটিই টিকিট,'),
                'heading_accent' => self::t('however many are coming', 'যতজনই আসুন'),
                'body' => self::t(
                    'Come on your own or bring the family — it is the same ticket either way, and the price you see here is the price you pay.',
                    'একা আসুন বা পরিবার নিয়ে — টিকিট একটিই। এখানে যে মূল্য দেখছেন, ঠিক সেটিই দিতে হবে; কোনো বাড়তি চার্জ নেই।',
                ),
            ],
        ];
    }

    /**
     * Figma "04 · Pricing Rules" — the heading only; the three rules quote
     * the live prices.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function rules(): array
    {
        return [
            'type' => 'pricing_rules',
            'fields' => [
                'eyebrow' => self::t('Transparent math', 'স্বচ্ছ হিসাব'),
                'heading_dark' => self::t('How the', 'মূল্য যেভাবে'),
                'heading_accent' => self::t('price is worked out', 'হিসাব হয়'),
            ],
        ];
    }

    /**
     * Figma "05 · Registration": the heading, the what-to-have-ready
     * checklist, and the live form beneath. The tutorial video is blank
     * until it is published; the button still shows and says so.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function registration(): array
    {
        return [
            'type' => 'registration_form',
            'fields' => [
                'eyebrow' => self::t('Registration', 'নিবন্ধন'),
                'heading_dark' => self::t('Reserve', 'আপনার আসন'),
                'heading_accent' => self::t('your place', 'নিশ্চিত করুন'),
                'body' => self::t(
                    'Four short steps. Your total updates as you go, and nothing is charged until you confirm.',
                    'চারটি সংক্ষিপ্ত ধাপ। প্রতিটি ধাপে মোট মূল্য হালনাগাদ হবে, এবং নিশ্চিত করার আগে কোনো টাকা কাটা হবে না।',
                ),
                'checklist_heading' => self::t('What you need to register:', 'নিবন্ধনের জন্য প্রয়োজনীয় কাগজপত্র ও তথ্যাদি:'),
                'checklist_items' => [
                    ['text' => self::t('A recent, clear passport-size colour photo', 'পাসপোর্ট সাইজের সাম্প্রতিক স্পষ্ট রঙিন ছবি')],
                    ['text' => self::t('National ID / Birth Registration Number (optional)', 'জাতীয় পরিচয়পত্র / জন্ম নিবন্ধন নম্বর (ঐচ্ছিক)')],
                    ['text' => self::t('An active mobile number or email address (one is required)', 'সচল মোবাইল নম্বর এবং ইমেইল এড্রেস (মোবাইল নম্বর আবশ্যক)')],
                    ['text' => self::t('Your correct T-shirt size', 'আপনার টি-শার্টের সঠিক মাপ')],
                    ['text' => self::t('bKash, Nagad or a card ready to pay the registration fee', 'নিবন্ধন ফি পরিশোধের জন্য বিকাশ, নগদ বা কার্ড প্রস্তুত রাখুন')],
                    ['text' => self::t('Form needs to be filled out in Bengali', 'নিবন্ধন ফর্মটি বাংলায় পূরণ করতে হবে')],
                ],
                'video_label' => self::t('How to register? Watch the video tutorial', 'কীভাবে নিবন্ধন করবেন? ভিডিও টিউটোরিয়াল দেখুন'),
                'video_url' => '',
            ],
        ];
    }

    /**
     * Figma "06 · How It Works". Three steps since 2026-09-13, when family
     * moved onto the details step; the Figma frame still shows four.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function howItWorks(): array
    {
        return [
            'type' => 'how_it_works',
            'fields' => [
                'eyebrow' => self::t('Three steps', 'তিনটি ধাপ'),
                'heading_dark' => self::t('How registration', 'নিবন্ধন যেভাবে'),
                'heading_accent' => self::t('works', 'কাজ করে'),
                'steps' => [
                    [
                        'title' => self::t('Enter your details', 'আপনার তথ্য দিন'),
                        'body' => self::t(
                            'Who you are, how to reach you, T-shirt size — and anyone joining you. The total updates as you go.',
                            'আপনি কে, কীভাবে যোগাযোগ করা যাবে, টি-শার্টের সাইজ — আর সঙ্গে কেউ এলে তাঁদের নামও। মোট মূল্য সঙ্গে সঙ্গে হালনাগাদ হবে।',
                        ),
                    ],
                    [
                        'title' => self::t('Review the summary', 'সারসংক্ষেপ দেখুন'),
                        'body' => self::t(
                            'Every attendee and every taka, itemised before you commit.',
                            'প্রতিজন অতিথি ও প্রতিটি টাকার হিসাব — নিশ্চিত করার আগেই সব খতিয়ে দেখুন।',
                        ),
                    ],
                    [
                        'title' => self::t('Confirm & pay', 'নিশ্চিত করুন ও পরিশোধ'),
                        'body' => self::t(
                            'We hold your seats and take you straight through to secure payment.',
                            'আমরা আপনার আসন সংরক্ষণ করে সরাসরি নিরাপদ পেমেন্ট গেটওয়েতে নিয়ে যাব।',
                        ),
                    ],
                ],
            ],
        ];
    }

    /**
     * Figma "07 · FAQ" — the ticket page's own six questions.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function faqs(): array
    {
        return [
            'type' => 'ticket_faq',
            'fields' => [
                'eyebrow' => self::t('Frequently asked', 'সাধারণ জিজ্ঞাসা'),
                'heading_dark' => self::t('Ticket', 'টিকিট সংক্রান্ত'),
                'heading_accent' => self::t('Questions', 'প্রশ্ন'),
                'items' => [
                    [
                        'question' => self::t('Who can register for the centennial celebration?', 'শতবর্ষ উৎসবে কারা নিবন্ধন করতে পারবেন?'),
                        'answer' => self::t(
                            'Alumni of batches 1971 through 2026, current students, teachers, staff and guardians can all register, along with their families. There is one ticket for everyone — you choose who you are when you register, and adding family is optional.',
                            '১৯৭১ থেকে ২০২৬ পর্যন্ত সব ব্যাচের প্রাক্তন শিক্ষার্থী, বর্তমান শিক্ষার্থী, শিক্ষক, কর্মচারী ও অভিভাবক — সবাই পরিবারসহ নিবন্ধন করতে পারবেন। টিকিট সবার জন্য একটিই; নিবন্ধনের সময় আপনি কোন পরিচয়ে আসছেন সেটি বেছে নেবেন, আর পরিবার যোগ করা সম্পূর্ণ ঐচ্ছিক।',
                        ),
                    ],
                    [
                        'question' => self::t('How much does it cost to bring my family?', 'পরিবার নিয়ে এলে খরচ কত পড়বে?'),
                        'answer' => self::t(
                            'There is one ticket, and family is optional on it. You pay the standard seat price for yourself, and a lower flat rate for each family member you add — spouse, children, parents or siblings. Children under 1 are free and never counted in the paid total. The price updates live as you add or remove members.',
                            'টিকিট একটিই, আর তাতে পরিবার নেওয়া সম্পূর্ণ ঐচ্ছিক। নিজের আসনের জন্য আপনি স্বাভাবিক মূল্য দেবেন, আর সঙ্গে আনা প্রতিজন সদস্যের জন্য একটি কম নির্ধারিত হারে — স্বামী/স্ত্রী, সন্তান, বাবা-মা বা ভাইবোন যে কেউ। ১ বছরের কম বয়সী শিশুরা বিনামূল্যে এবং কখনও পরিশোধযোগ্য সংখ্যায় গণনা হয় না। সদস্য যোগ বা বাদ দিলে মূল্য সঙ্গে সঙ্গে হালনাগাদ হয়।',
                        ),
                    ],
                    [
                        'question' => self::t('My child is exactly 1 year old. Is the ticket free?', 'আমার সন্তানের বয়স ঠিক ১ বছর। টিকিট কি ফ্রি?'),
                        'answer' => self::t(
                            "No. The free admission applies to children under 1 year. From the first birthday onwards a child is charged the standard family-member rate, which includes meals and the children's activity corner. A free child is still admitted at the gate like everyone else — the discount is on the price, not the seat.",
                            'না। ১ বছরের কম বয়সী শিশুদের জন্যই বিনামূল্যে প্রবেশ প্রযোজ্য। প্রথম জন্মদিনের পর থেকে একটি শিশুর জন্য স্বাভাবিক পারিবারিক সদস্য হার প্রযোজ্য হয়, যাতে খাবার ও শিশুদের কার্যক্রম কর্নারে প্রবেশ অন্তর্ভুক্ত থাকে। বিনামূল্যের শিশুও সবার মতোই গেটে ভর্তি হয় — ছাড়টি শুধু মূল্যে, আসনে নয়।',
                        ),
                    ],
                    [
                        'question' => self::t('How do T-shirt sizes work?', 'টি-শার্টের সাইজ কীভাবে ঠিক হয়?'),
                        'answer' => self::t(
                            'A commemorative centennial T-shirt is included with your own seat — choose your size during registration. Family members you bring do not receive a T-shirt; they get the meals and the keepsake.',
                            'আপনার নিজের আসনের সঙ্গে একটি শতবর্ষ স্মারক টি-শার্ট অন্তর্ভুক্ত — নিবন্ধনের সময় নিজের সাইজ বেছে নিন। সঙ্গে আনা পরিবারের সদস্যদের জন্য টি-শার্ট নেই; তাঁরা খাবার ও স্মারক পাবেন।',
                        ),
                    ],
                    [
                        'question' => self::t("I don't remember my exact batch year.", 'আমার ব্যাচের সাল ঠিক মনে নেই।'),
                        'answer' => self::t(
                            'Use the year you sat for your final examination at the school. If you left before that, pick the year your class would have graduated — our alumni desk will confirm it with the school register before the event.',
                            'যে বছর আপনি বিদ্যালয়ে আপনার শেষ পরীক্ষা দিয়েছিলেন, সেটিই ব্যবহার করুন। তার আগেই বিদ্যালয় ছেড়ে থাকলে, যে বছর আপনার ব্যাচ পাস করার কথা ছিল সেটি বেছে নিন — অনুষ্ঠানের আগে আমাদের প্রাক্তন শিক্ষার্থী ডেস্ক বিদ্যালয়ের রেজিস্টার দেখে তা নিশ্চিত করে দেবে।',
                        ),
                    ],
                    [
                        'question' => self::t('When do I pay?', 'টাকা কখন পরিশোধ করতে হবে?'),
                        'answer' => self::t(
                            'Nothing is charged while you fill in the form. You review the full price breakdown before confirming, and confirming takes you through to the payment gateway. Your seats are held while that payment window is open.',
                            'ফর্ম পূরণের সময় কোনো টাকা কাটা হয় না। নিশ্চিত করার আগে সম্পূর্ণ মূল্যের হিসাব দেখতে পাবেন, আর নিশ্চিত করলেই আপনি সরাসরি পেমেন্ট গেটওয়েতে চলে যাবেন। সেই পেমেন্ট উইন্ডো খোলা থাকা অবস্থায় আপনার আসন সংরক্ষিত থাকে।',
                        ),
                    ],
                ],
                'tail' => self::t('Still unsure?', 'এখনও প্রশ্ন আছে?'),
                'tail_label' => self::t('Ask the reunion desk', 'পুনর্মিলনী ডেস্কে জিজ্ঞাসা করুন'),
                'tail_url' => '/contact',
            ],
        ];
    }

    /**
     * The shared CTA symbol, with this page's own copy.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function ctaBanner(): array
    {
        return [
            'type' => 'cta_banner',
            'fields' => [
                'eyebrow' => self::t('Seats are limited — register today', 'আসন সীমিত · আজই নিবন্ধন করুন'),
                'heading_line1' => self::t('Reserve Your Seat', 'শতবর্ষের উৎসবে'),
                'heading_accent' => self::t('for the', 'আপনার আসনটি'),
                'heading_line2' => self::t('Centenary Celebration', 'নিশ্চিত করুন'),
                'body' => self::t(
                    'One ticket for everyone — alumni, current students, teachers, staff and family.',
                    'একটিই টিকিট — প্রাক্তন ও বর্তমান শিক্ষার্থী, শিক্ষক, কর্মচারী ও পরিবারের সবার জন্য।',
                ),
                'primary_label' => self::t('Start Registration', 'নিবন্ধন শুরু করুন'),
                'primary_url' => '/tickets#register',
                'secondary_label' => self::t('View Pricing', 'মূল্য তালিকা দেখুন'),
                'secondary_url' => '/tickets#pricing',
            ],
        ];
    }
}
