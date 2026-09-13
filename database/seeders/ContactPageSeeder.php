<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Contact page, block by block, on the same contract as
 * {@see HomePageSeeder}: every value is the copy the public site ships with.
 *
 * Five sections: Page Hero (with the four channel cards), Committee Desks,
 * Venue & Directions, FAQ, CTA Banner. The last three are shared types —
 * `venue_directions` from the Events page, `faq_list` and `cta_banner` from
 * the homepage — bound here to this page's own copy.
 *
 * The channel cards' phone, email and address are deliberately left blank:
 * the public site falls back to the `contact.*` event settings for those, so
 * the number an admin changes on the Settings screen is the number the page
 * shows. Filling a value here overrides the setting for that card only.
 *
 * This seeder owns the `contact` slug; {@see ContentSeeder} no longer seeds
 * a generic page there.
 */
class ContactPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'contact'],
            [
                'template' => 'contact',
                'title' => 'Contact & Secretariat',
                'title_bn' => 'যোগাযোগ ও সচিবালয়',
                'excerpt' => 'Reach the organising committee.',
                'excerpt_bn' => 'আয়োজক কমিটির সঙ্গে যোগাযোগ করুন।',
                'seo_title' => 'Contact & Secretariat',
                'seo_title_bn' => 'যোগাযোগ ও সচিবালয়',
                'seo_description' => 'Official contact channels, committee helpdesks, and secretariat for centenary registrations, souvenir submissions, and queries.',
                'seo_description_bn' => 'শতবর্ষের নিবন্ধন, স্মরণিকা লেখা জমা ও যেকোনো প্রশ্নের জন্য আনুষ্ঠানিক যোগাযোগ মাধ্যম, কমিটির হেল্পডেস্ক ও সচিবালয়।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 8,
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
            $this->desks(),
            $this->venue(),
            $this->faqs(),
            $this->ctaBanner(),
        ];
    }

    /**
     * The hero and its four channel cards.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function hero(): array
    {
        return [
            'type' => 'contact_hero',
            'fields' => [
                'breadcrumb' => self::t('Contact', 'যোগাযোগ'),
                'eyebrow' => self::t('Centennial Secretariat & Helpdesk', 'শতবর্ষের হেল্পডেস্ক ও সচিবালয়'),
                'heading_lead' => self::t('Get in Touch with the', 'শতবর্ষ উদযাপন পর্ষদের সাথে'),
                'heading_accent' => self::t('Centennial Committee', 'যোগাযোগ করুন'),
                'body' => self::t(
                    'Get in touch with the organizing committee for centenary queries, registrations, souvenir submissions, or assistance.',
                    'শতবর্ষ পূর্তি উৎসব, টিকিট ও নিবন্ধন, স্মরণিকা লেখা জমা, স্মৃতিচারণ বা যেকোনো তথ্যের জন্য সরাসরি আমাদের সাথে যোগাযোগ করুন।',
                ),
                'channels' => [
                    [
                        'icon' => 'Phone',
                        'tone' => 'gold',
                        'title' => self::t('Helpline & Calls', 'জরুরি হেল্পলাইন'),
                        'description' => self::t('Direct phone line for quick support', 'সরাসরি ফোন ও যেকোনো তাৎক্ষণিক সহায়তার জন্য'),
                        'value' => '',
                        'action_label' => self::t('Call Now', 'কল করুন'),
                        'action_url' => '',
                    ],
                    [
                        'icon' => 'MessageCircle',
                        'tone' => 'purple',
                        'title' => self::t('WhatsApp Support', 'হোয়াটসঅ্যাপ সাপোর্ট'),
                        'description' => self::t('Chat with our support desk on WhatsApp', 'বার্তা পাঠিয়ে দ্রুত সমাধান ও তথ্য পান'),
                        'value' => '+880 1700-000000',
                        'action_label' => self::t('WhatsApp', 'চ্যাট করুন'),
                        'action_url' => 'https://wa.me/8801700000000',
                    ],
                    [
                        'icon' => 'Mail',
                        'tone' => 'purple',
                        'title' => self::t('Email Inquiries', 'ইমেইল যোগাযোগ'),
                        'description' => self::t('For official queries and correspondence', 'দাপ্তরিক তথ্য ও যেকোনো আনুষ্ঠানিক অনুসন্ধানে'),
                        'value' => '',
                        'action_label' => self::t('Send Email', 'ইমেইল পাঠান'),
                        'action_url' => '',
                    ],
                    [
                        'icon' => 'MapPin',
                        'tone' => 'gold',
                        'title' => self::t('Centennial Office', 'শতবর্ষ সচিবালয়'),
                        'description' => self::t('School Campus, Chapainawabganj Sadar', 'বিদ্যালয় ক্যাম্পাস প্রাঙ্গণ, চাঁপাইনবাবগঞ্জ সদর'),
                        'value' => '',
                        'action_label' => self::t('View Map', 'ম্যাপে দেখুন'),
                        'action_url' => 'https://maps.google.com/?q=Namoshankarbati+High+School+Chapainawabganj',
                    ],
                ],
            ],
        ];
    }

    /**
     * The four department desks and the secretariat hours banner.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function desks(): array
    {
        return [
            'type' => 'committee_desks',
            'fields' => [
                'badge' => self::t('Support Desks', 'বিশেষ সহায়তা ডেস্ক'),
                'heading' => self::t('Dedicated Department Desks', 'নির্দিষ্ট বিষয়ে সহযোগিতার জন্য ডেস্কসমূহ'),
                'subheading' => self::t(
                    'Reach out to the specific subcommittee for faster assistance',
                    'নির্দিষ্ট প্রয়োজনে সংশ্লিষ্ট উপকমিটির সাথে সরাসরি যোগাযোগ করতে পারেন',
                ),
                'desks' => [
                    [
                        'icon' => 'Ticket',
                        'tone' => 'purple',
                        'title' => self::t('Registration & Ticket Desk', 'নিবন্ধন ও টিকেট সহায়তা ডেস্ক'),
                        'description' => self::t('Online payments, QR codes, and family passes', 'অনলাইন পেমেন্ট, কিউআর কোড টিকিট ও পারিবারিক পাস'),
                        'email' => 'tickets@nsbatihighschool.edu.bd',
                    ],
                    [
                        'icon' => 'BookOpen',
                        'tone' => 'gold',
                        'title' => self::t('Souvenir & Publication', 'স্মরণিকা ও প্রকাশনা কমিটি'),
                        'description' => self::t('Articles, photo memoirs, and commemorative ads', 'স্মৃতিচারণ প্রবন্ধ, আলোকচিত্র ও বিজ্ঞাপন প্রকাশ'),
                        'email' => 'souvenir@nsbatihighschool.edu.bd',
                    ],
                    [
                        'icon' => 'Users2',
                        'tone' => 'purple',
                        'title' => self::t('Batch Coordination Cell', 'ব্যাচ সমন্বয় ও প্রতিনিধি সেল'),
                        'description' => self::t('SSC batch representatives and reunion leads', 'সকল এসএসসি ব্যাচের প্রতিনিধি ও রিইউনিয়ন টিম'),
                        'email' => 'alumni@nsbatihighschool.edu.bd',
                    ],
                    [
                        'icon' => 'Coins',
                        'tone' => 'gold',
                        'title' => self::t('Finance & Hospitality', 'অর্থ ও আপ্যায়ন উপ-কমিটি'),
                        'description' => self::t('Donations, funding, accommodation, and hospitality', 'অনুদান, অর্থায়ন, আবাসন ও বিশেষ অতিথি অভ্যর্থনা'),
                        'email' => 'finance@nsbatihighschool.edu.bd',
                    ],
                ],
                'hours_title' => self::t('Centennial Secretariat & Helpdesk Hours', 'শতবর্ষ সচিবালয় ও হেল্পডেস্ক সময়সূচি'),
                'hours_subtitle' => self::t('School Campus, Chapainawabganj Sadar', 'বিদ্যালয় ক্যাম্পাস প্রাঙ্গণ, চাঁপাইনবাবগঞ্জ সদর'),
                'hours_value' => self::t('Sat – Thu: 9:00 AM – 6:00 PM', 'শনিবার – বৃহস্পতিবার: সকাল ৯:০০ – সন্ধ্যা ৬:০০'),
                'hours_badge' => self::t('24/7 WhatsApp Support', '২৪/৭ হোয়াটসঅ্যাপ সাপোর্ট'),
            ],
        ];
    }

    /**
     * The shared venue symbol — byte-identical to the Events page's copy,
     * because the design binds the same card to both.
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
                    ['label' => self::t('Entrance', 'প্রবেশপথ'), 'body' => self::t('Enter through the main gate, ticket QR code must be shown', 'প্রধান ফটক দিয়ে প্রবেশ, টিকিট QR দেখাতে হবে')],
                    ['label' => self::t('Parking', 'পার্কিং'), 'body' => self::t('Parking available on the west side of school grounds', 'বিদ্যালয় মাঠের পশ্চিম পাশে গাড়ি রাখার ব্যবস্থা')],
                    ['label' => self::t('Help Desk', 'সহায়তা ডেস্ক'), 'body' => self::t('Beside the main gate, open from 8:00 AM', 'প্রধান ফটকের পাশে, সকাল ৮টা থেকে')],
                ],
            ],
        ];
    }

    /**
     * The homepage's FAQ grid, without its heading — the Contact design drops
     * the grid straight under the venue card.
     *
     * @return array{type: string, fields: array<string, mixed>}
     */
    private function faqs(): array
    {
        return [
            'type' => 'faq_list',
            'fields' => [
                'eyebrow' => '',
                'heading' => '',
                'heading_accent' => '',
                'category' => '',
                'items' => [
                    ['question' => self::t('When is the registration deadline?', 'নিবন্ধনের শেষ তারিখ কখন?'), 'answer' => self::t('Seats are limited and allotted first-come, first-served. We recommend registering as early as possible.', 'আসন সংখ্যা সীমিত এবং প্রথম আসা প্রথম পাওয়া ভিত্তিতে বরাদ্দ হবে। যত দ্রুত সম্ভব নিবন্ধন করার পরামর্শ দেওয়া হচ্ছে।'), 'icon' => 'CalendarClock', 'tone' => 'gold'],
                    ['question' => self::t('What does registration include?', 'কি কি সুবিধা থাকবে নিবন্ধনে?'), 'answer' => self::t('Every ticket includes a welcome kit, lunch and a commemorative t-shirt.', 'প্রতিটি টিকিটে থাকছে একটি ওয়েলকাম কিট, দুপুরের খাবার ও একটি স্মারক টি-শার্ট।'), 'icon' => 'Gift', 'tone' => 'maroon'],
                    ['question' => self::t('How do I add family members?', 'পরিবারের সদস্য সংখ্যা কিভাবে যোগ করবো?'), 'answer' => self::t('On step three of the registration form, you can add the name and age of every family member joining you.', 'নিবন্ধন ফর্মের তৃতীয় ধাপে আপনি সঙ্গে আসা প্রতিটি সদস্যের নাম ও বয়স যোগ করতে পারবেন।'), 'icon' => 'Users', 'tone' => 'blue'],
                    ['question' => self::t('How do I make the payment?', 'পেমেন্ট কিভাবে করতে হবে?'), 'answer' => self::t('Payment is accepted online via bKash, Nagad, Rocket and major credit/debit cards.', 'বিকাশ, নগদ, রকেট ও প্রধান ক্রেডিট/ডেবিট কার্ডের মাধ্যমে অনলাইনে পেমেন্ট করা যাবে।'), 'icon' => 'CreditCard', 'tone' => 'green'],
                    ['question' => self::t('What is the refund policy?', 'রিফান্ড নীতি কি?'), 'answer' => self::t('Full refunds are available up to 14 days before the event. Partial refunds apply after that.', 'অনুষ্ঠানের ১৪ দিন আগ পর্যন্ত সম্পূর্ণ রিফান্ড পাওয়া যাবে। এরপর আংশিক রিফান্ড প্রযোজ্য।'), 'icon' => 'RotateCcw', 'tone' => 'teal'],
                    ['question' => self::t('What should I bring on the day?', 'অনুষ্ঠানের দিন কি আনতে হবে?'), 'answer' => self::t("Bring your ticket's QR code (on your phone or printed) along with a photo ID.", 'আপনার টিকিটের QR কোড (মোবাইলে বা প্রিন্ট করা) ও একটি ছবিসহ পরিচয়পত্র সাথে আনুন।'), 'icon' => 'SquareCheck', 'tone' => 'indigo'],
                ],
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
                'eyebrow' => self::t('Join the Celebration', 'শতবর্ষে আপনিও থাকুন'),
                'heading_line1' => self::t('Be Part of the', 'এক শতাব্দীর গৌরবময়'),
                'heading_accent' => self::t('Centennial', 'শতবর্ষের'),
                'heading_line2' => self::t('Reunion', 'মহামিলন'),
                'body' => self::t(
                    'Secure tickets for you and your family today. Reconnect with batchmates, teachers, and loved ones on this historic milestone.',
                    'এখনই নিজের এবং পরিবারের টিকিট নিশ্চিত করুন। শতবর্ষের স্মরণীয় এই দিনে আপনার প্রিয় ব্যাচমেট ও শিক্ষকদের সাথে দেখা হোক।',
                ),
                'primary_label' => self::t('Get Your Ticket', 'টিকিট সংগ্রহ করুন'),
                'primary_url' => '/tickets',
                'secondary_label' => self::t('Learn more', 'বিস্তারিত জানতে'),
                'secondary_url' => '/event',
            ],
        ];
    }
}
