<?php

namespace Database\Seeders;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\Faq;
use App\Domain\Content\Models\GalleryAlbum;
use App\Domain\Content\Models\Menu;
use App\Domain\Content\Models\MenuItem;
use App\Domain\Content\Models\ScheduleItem;
use App\Domain\Content\Models\Sponsor;
use Illuminate\Database\Seeder;

/**
 * Baseline CMS content for the centenary site (docs/08 Phase 3.5).
 *
 * Seeded rather than left to editors so Phase 3's public marketing pages
 * build against real bilingual content from day one instead of fixtures they
 * would later have to unpick. Every row is `updateOrCreate`d on its natural
 * key, so re-running the seeder never duplicates and never clobbers an
 * editor's `published_at`.
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedPages();
        $this->seedMenus();
        $this->seedSponsors();
        $this->seedSchedule();
        $this->seedFaqs();
        $this->seedGallery();
    }

    private function seedPages(): void
    {
        /** @var array<int, array{slug: string, title: string, title_bn: string, excerpt: string, excerpt_bn: string, blocks: array<int, array{type: string, data: array<string, mixed>, data_bn: array<string, mixed>}>}> $pages */
        $pages = [
            [
                'slug' => 'home',
                'title' => 'Centenary Celebration',
                'title_bn' => 'শতবর্ষ উদযাপন',
                'excerpt' => 'One hundred years of the institution, celebrated by the people who made it.',
                'excerpt_bn' => 'প্রতিষ্ঠানের একশ বছর, উদযাপন করছেন যাঁরা একে গড়ে তুলেছেন।',
                'blocks' => [
                    [
                        'type' => 'hero',
                        'data' => ['heading' => 'A Hundred Years', 'subheading' => 'Come home for the centenary.', 'cta_label' => 'Register now', 'cta_url' => '/register'],
                        'data_bn' => ['heading' => 'একশ বছর', 'subheading' => 'শতবর্ষে ফিরে আসুন।', 'cta_label' => 'নিবন্ধন করুন', 'cta_url' => '/register'],
                    ],
                    [
                        'type' => 'rich_text',
                        'data' => ['heading' => 'About the celebration', 'body' => 'Three days of reunion, remembrance and renewal, open to every former student, teacher and member of staff.'],
                        'data_bn' => ['heading' => 'উদযাপন সম্পর্কে', 'body' => 'তিন দিনের পুনর্মিলনী, স্মৃতিচারণ ও নবায়ন — সকল প্রাক্তন শিক্ষার্থী, শিক্ষক ও কর্মচারীর জন্য উন্মুক্ত।'],
                    ],
                    [
                        'type' => 'sponsor_grid',
                        'data' => ['heading' => 'Our sponsors'],
                        'data_bn' => ['heading' => 'আমাদের পৃষ্ঠপোষক'],
                    ],
                ],
            ],
            [
                'slug' => 'about',
                'title' => 'About',
                'title_bn' => 'পরিচিতি',
                'excerpt' => 'The story of a century.',
                'excerpt_bn' => 'একশ বছরের গল্প।',
                'blocks' => [
                    [
                        'type' => 'rich_text',
                        'data' => ['heading' => 'Our history', 'body' => 'Founded a century ago, the institution has taught generations of this community.'],
                        'data_bn' => ['heading' => 'আমাদের ইতিহাস', 'body' => 'একশ বছর আগে প্রতিষ্ঠিত এই প্রতিষ্ঠান প্রজন্মের পর প্রজন্মকে শিক্ষা দিয়েছে।'],
                    ],
                ],
            ],
            [
                'slug' => 'schedule',
                'title' => 'Schedule',
                'title_bn' => 'সময়সূচি',
                'excerpt' => 'What happens, and when.',
                'excerpt_bn' => 'কখন কী হবে।',
                'blocks' => [
                    [
                        'type' => 'schedule',
                        'data' => ['heading' => 'Programme'],
                        'data_bn' => ['heading' => 'কর্মসূচি'],
                    ],
                ],
            ],
            [
                'slug' => 'faq',
                'title' => 'Frequently Asked Questions',
                'title_bn' => 'সাধারণ জিজ্ঞাসা',
                'excerpt' => 'Registration, payment and venue questions.',
                'excerpt_bn' => 'নিবন্ধন, পরিশোধ ও ভেন্যু সংক্রান্ত প্রশ্ন।',
                'blocks' => [
                    [
                        'type' => 'faq_list',
                        'data' => ['heading' => 'Questions'],
                        'data_bn' => ['heading' => 'প্রশ্নসমূহ'],
                    ],
                ],
            ],
            [
                'slug' => 'contact',
                'title' => 'Contact',
                'title_bn' => 'যোগাযোগ',
                'excerpt' => 'Reach the organising committee.',
                'excerpt_bn' => 'আয়োজক কমিটির সঙ্গে যোগাযোগ করুন।',
                'blocks' => [
                    [
                        'type' => 'rich_text',
                        'data' => ['heading' => 'Get in touch', 'body' => 'The organising committee answers queries within two working days.'],
                        'data_bn' => ['heading' => 'যোগাযোগ করুন', 'body' => 'আয়োজক কমিটি দুই কার্যদিবসের মধ্যে উত্তর দেয়।'],
                    ],
                ],
            ],
        ];

        foreach ($pages as $position => $page) {
            $model = ContentPage::updateOrCreate(
                ['slug' => $page['slug']],
                [
                    'template' => 'standard',
                    'title' => $page['title'],
                    'title_bn' => $page['title_bn'],
                    'excerpt' => $page['excerpt'],
                    'excerpt_bn' => $page['excerpt_bn'],
                    'seo_title' => $page['title'],
                    'seo_title_bn' => $page['title_bn'],
                    'seo_description' => $page['excerpt'],
                    'seo_description_bn' => $page['excerpt_bn'],
                    'status' => 'published',
                    'published_at' => now(),
                    'is_indexable' => true,
                    'position' => $position,
                ]
            );

            foreach ($page['blocks'] as $blockPosition => $block) {
                ContentBlock::updateOrCreate(
                    ['content_page_id' => $model->id, 'position' => $blockPosition],
                    [
                        'type' => $block['type'],
                        'data' => $block['data'],
                        'data_bn' => $block['data_bn'],
                        'is_visible' => true,
                    ]
                );
            }
        }
    }

    private function seedMenus(): void
    {
        /** @var array<string, array{name: string, name_bn: string, items: array<int, array{label: string, label_bn: string, slug: string}>}> $menus */
        $menus = [
            'primary' => [
                'name' => 'Primary navigation',
                'name_bn' => 'প্রধান মেনু',
                'items' => [
                    ['label' => 'Home', 'label_bn' => 'হোম', 'slug' => 'home'],
                    ['label' => 'About', 'label_bn' => 'পরিচিতি', 'slug' => 'about'],
                    ['label' => 'Schedule', 'label_bn' => 'সময়সূচি', 'slug' => 'schedule'],
                    ['label' => 'FAQ', 'label_bn' => 'সাধারণ জিজ্ঞাসা', 'slug' => 'faq'],
                    ['label' => 'Contact', 'label_bn' => 'যোগাযোগ', 'slug' => 'contact'],
                ],
            ],
            'footer' => [
                'name' => 'Footer navigation',
                'name_bn' => 'ফুটার মেনু',
                'items' => [
                    ['label' => 'About', 'label_bn' => 'পরিচিতি', 'slug' => 'about'],
                    ['label' => 'Contact', 'label_bn' => 'যোগাযোগ', 'slug' => 'contact'],
                ],
            ],
        ];

        foreach ($menus as $code => $menu) {
            $model = Menu::updateOrCreate(
                ['code' => $code],
                ['name' => $menu['name'], 'name_bn' => $menu['name_bn'], 'is_active' => true]
            );

            foreach ($menu['items'] as $position => $item) {
                $page = ContentPage::where('slug', $item['slug'])->first();

                MenuItem::updateOrCreate(
                    ['menu_id' => $model->id, 'position' => $position, 'parent_id' => null],
                    [
                        'label' => $item['label'],
                        'label_bn' => $item['label_bn'],
                        'content_page_id' => $page?->id,
                        'target' => '_self',
                        'is_visible' => true,
                    ]
                );
            }
        }
    }

    private function seedSponsors(): void
    {
        $sponsors = [
            ['name' => 'Centenary Trust', 'name_bn' => 'শতবর্ষ ট্রাস্ট', 'tier' => 'platinum'],
            ['name' => 'Alumni Association', 'name_bn' => 'প্রাক্তন শিক্ষার্থী সমিতি', 'tier' => 'gold'],
            ['name' => 'City Bank', 'name_bn' => 'সিটি ব্যাংক', 'tier' => 'silver'],
        ];

        foreach ($sponsors as $position => $sponsor) {
            Sponsor::updateOrCreate(
                ['name' => $sponsor['name']],
                [
                    'name_bn' => $sponsor['name_bn'],
                    'tier' => $sponsor['tier'],
                    'position' => $position,
                    'is_published' => true,
                ]
            );
        }
    }

    private function seedSchedule(): void
    {
        $items = [
            ['title' => 'Registration desk opens', 'title_bn' => 'নিবন্ধন ডেস্ক খোলা', 'offset' => 0, 'venue' => 'Main Gate', 'venue_bn' => 'প্রধান ফটক'],
            ['title' => 'Inaugural ceremony', 'title_bn' => 'উদ্বোধনী অনুষ্ঠান', 'offset' => 2, 'venue' => 'Central Field', 'venue_bn' => 'কেন্দ্রীয় মাঠ'],
            ['title' => 'Cultural programme', 'title_bn' => 'সাংস্কৃতিক অনুষ্ঠান', 'offset' => 6, 'venue' => 'Auditorium', 'venue_bn' => 'মিলনায়তন'],
        ];

        $day = now()->addMonths(2)->startOfDay()->addHours(8);

        foreach ($items as $position => $item) {
            ScheduleItem::updateOrCreate(
                ['title' => $item['title']],
                [
                    'title_bn' => $item['title_bn'],
                    'venue' => $item['venue'],
                    'venue_bn' => $item['venue_bn'],
                    'starts_at' => (clone $day)->addHours($item['offset']),
                    'ends_at' => (clone $day)->addHours($item['offset'] + 1),
                    'position' => $position,
                    'is_published' => true,
                ]
            );
        }
    }

    private function seedFaqs(): void
    {
        $faqs = [
            [
                'question' => 'Who can register?',
                'question_bn' => 'কারা নিবন্ধন করতে পারবেন?',
                'answer' => 'Every former student, current student, teacher and member of staff.',
                'answer_bn' => 'সকল প্রাক্তন ও বর্তমান শিক্ষার্থী, শিক্ষক এবং কর্মচারী।',
                'category' => 'registration',
                'category_bn' => 'নিবন্ধন',
            ],
            [
                'question' => 'How do I pay?',
                'question_bn' => 'কীভাবে পরিশোধ করব?',
                'answer' => 'Online through bKash, Nagad, Rocket or card. Manual payment can be verified by the committee.',
                'answer_bn' => 'বিকাশ, নগদ, রকেট বা কার্ডের মাধ্যমে অনলাইনে। কমিটি সরাসরি পরিশোধও যাচাই করতে পারে।',
                'category' => 'payment',
                'category_bn' => 'পরিশোধ',
            ],
            [
                'question' => 'Can I bring my family?',
                'question_bn' => 'পরিবারের সদস্যদের আনতে পারব?',
                'answer' => 'Yes — the family ticket admits up to six people.',
                'answer_bn' => 'হ্যাঁ — পারিবারিক টিকিটে সর্বোচ্চ ছয়জন প্রবেশ করতে পারবেন।',
                'category' => 'registration',
                'category_bn' => 'নিবন্ধন',
            ],
        ];

        foreach ($faqs as $position => $faq) {
            Faq::updateOrCreate(
                ['question' => $faq['question']],
                array_merge($faq, ['position' => $position, 'is_published' => true])
            );
        }
    }

    private function seedGallery(): void
    {
        GalleryAlbum::updateOrCreate(
            ['slug' => 'through-the-years'],
            [
                'title' => 'Through the years',
                'title_bn' => 'বছরের পর বছর',
                'description' => 'A century of photographs from the archive.',
                'description_bn' => 'সংগ্রহশালা থেকে একশ বছরের আলোকচিত্র।',
                'position' => 0,
                'is_published' => true,
            ]
        );
    }
}
