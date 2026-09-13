<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The Attendees directory page, block by block, on the same contract as
 * {@see HomePageSeeder}. Three sections: the hero (whose six counts are the
 * live directory totals — only their labels live here), the directory
 * itself (a block with no copy: it places the live, filterable list), and
 * the shared CTA banner.
 *
 * Attendee PII is never public (docs/00 Gap G3); the directory shows only
 * what the public endpoint already exposes, and this seeder adds no data
 * about anybody.
 */
class AttendeesPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'attendees'],
            [
                'template' => 'landing',
                'title' => 'Attendees Directory',
                'title_bn' => 'অংশগ্রহণকারীদের তালিকা',
                'excerpt' => 'Everyone joining the centennial celebration.',
                'excerpt_bn' => 'শতবর্ষের মিলনমেলায় যারা যোগ দিচ্ছেন।',
                'seo_title' => 'Attendees Directory',
                'seo_title_bn' => 'অংশগ্রহণকারীদের তালিকা',
                'seo_description' => 'Celebrating 100 years: browse all registered alumni, current students, teachers, staff, and honorable guests attending the grand reunion.',
                'seo_description_bn' => 'শতবর্ষ উদযাপন: মহামিলনে যোগ দিতে নিবন্ধিত প্রাক্তন ও বর্তমান শিক্ষার্থী, শিক্ষক, কর্মচারী ও সম্মানিত অতিথিদের তালিকা দেখুন।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 6,
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
                'type' => 'attendees_hero',
                'fields' => [
                    'breadcrumb' => self::t('Attendees', 'অংশগ্রহণকারী'),
                    'eyebrow' => self::t('Centennial Gathering', 'শতবর্ষের মহামিলন'),
                    'heading_lead' => self::t('All Registered', 'নিবন্ধিত সকল'),
                    'heading_accent' => self::t('Attendees', 'অংশগ্রহণকারী'),
                    'body' => self::t(
                        'Alumni, current students, respected teachers, staff, and distinguished guests — the directory of everyone joining the centennial celebration.',
                        'প্রাক্তন ও বর্তমান শিক্ষার্থী, শ্রদ্ধেয় শিক্ষকবৃন্দ, কর্মচারীবৃন্দ ও সুধী অতিথিবৃন্দ — শতবর্ষের মিলনমেলায় যারা যোগ দিচ্ছেন তাদের তালিকা।',
                    ),
                    'label_total' => self::t('Total Registered', 'মোট নিবন্ধিত'),
                    'label_alumni' => self::t('Alumni', 'প্রাক্তন শিক্ষার্থী'),
                    'label_students' => self::t('Current Students', 'বর্তমান শিক্ষার্থী'),
                    'label_teachers_staff' => self::t('Teachers & Staff', 'শিক্ষক ও কর্মকর্তা'),
                    'label_guests' => self::t('Total Guests', 'অতিথিবৃন্দ'),
                    'label_batches' => self::t('Batches Represented', 'ব্যাচ সংখ্যা'),
                ],
            ],
            [
                'type' => 'attendee_directory',
                'fields' => [],
            ],
            [
                'type' => 'cta_banner',
                'fields' => [
                    'eyebrow' => self::t('Join the Celebration', 'শতবর্ষে আপনিও থাকুন'),
                    'heading_line1' => self::t('Your Seat at the', 'আপনার প্রিয় ক্যাম্পাসে'),
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
            ],
        ];
    }
}
