<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentPage;
use Database\Seeders\Concerns\SeedsContentBlocks;
use Illuminate\Database\Seeder;

/**
 * The FAQ page, block by block, on the same contract as
 * {@see HomePageSeeder}. Three sections: the shared `page_hero`, a
 * `faq_list` — which on this page renders every published FAQ from the
 * FAQs tab rather than its own rows, so the questions are edited there —
 * and the closing "didn't find your answer?" card.
 *
 * This seeder owns the `faq` slug; {@see ContentSeeder} no longer seeds a
 * generic page there. The FAQ rows themselves are still ContentSeeder's.
 */
class FaqPageSeeder extends Seeder
{
    use SeedsContentBlocks;

    public function run(): void
    {
        $page = ContentPage::updateOrCreate(
            ['slug' => 'faq'],
            [
                'template' => 'standard',
                'title' => 'Frequently Asked Questions',
                'title_bn' => 'সাধারণ জিজ্ঞাসা',
                'excerpt' => 'Registration, payment and venue questions.',
                'excerpt_bn' => 'নিবন্ধন, পরিশোধ ও ভেন্যু সংক্রান্ত প্রশ্ন।',
                'seo_title' => 'Frequently Asked Questions',
                'seo_title_bn' => 'সাধারণ জিজ্ঞাসা',
                'seo_description' => 'The questions we hear most about the centennial celebration, tickets and registration, answered in one place.',
                'seo_description_bn' => 'শতবর্ষ উদযাপন, টিকিট ও নিবন্ধন নিয়ে সাধারণ প্রশ্নের উত্তর একসাথে।',
                'status' => 'published',
                'published_at' => now(),
                'is_indexable' => true,
                'position' => 7,
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
                    'breadcrumb' => self::t('FAQ', 'সাধারণ জিজ্ঞাসা'),
                    'eyebrow' => self::t('Tickets, registration and the day', 'টিকিট, নিবন্ধন ও অনুষ্ঠান'),
                    'heading_lead' => self::t('Your questions,', 'আপনার প্রশ্ন,'),
                    'heading_accent' => self::t('our answers', 'আমাদের উত্তর'),
                    'body' => self::t(
                        'The questions we hear most about the centennial, answered in one place.',
                        'শতবর্ষ উদযাপন নিয়ে যে প্রশ্নগুলো সবচেয়ে বেশি আসে, সেগুলোর উত্তর এখানে একসাথে।',
                    ),
                ],
            ],
            [
                'type' => 'faq_list',
                'fields' => [
                    'heading' => self::t('Questions', 'প্রশ্নসমূহ'),
                    'category' => '',
                ],
            ],
            [
                'type' => 'faq_contact_cta',
                'fields' => [
                    'heading' => self::t("Didn't find your answer?", 'উত্তর পাননি?'),
                    'body' => self::t(
                        'There is a separate desk for registration, the souvenir book and the programme.',
                        'নিবন্ধন, স্মরণিকা ও অনুষ্ঠানের জন্য আলাদা ডেস্ক আছে।',
                    ),
                    'cta_label' => self::t('Contact us', 'যোগাযোগ করুন'),
                    'cta_url' => '/contact',
                ],
            ],
        ];
    }
}
