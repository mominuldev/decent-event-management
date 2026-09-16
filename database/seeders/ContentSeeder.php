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
        // `home` is deliberately absent: it is thirteen bespoke sections
        // rather than a handful of generic blocks, so {@see HomePageSeeder}
        // owns that page — and holds position 0, which is why the pages here
        // start at 1. `faq` and `contact` moved out for the same reason: the
        // public site draws them as designed sections, so
        // {@see FaqPageSeeder} and {@see ContactPageSeeder} own those slugs.
        /** @var array<int, array{slug: string, title: string, title_bn: string, excerpt: string, excerpt_bn: string, blocks: array<int, array{type: string, data: array<string, mixed>, data_bn: array<string, mixed>}>}> $pages */
        $pages = [
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
                    'position' => $position + 1,
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
        // Entries carry a literal `url` rather than a `content_page_id`. The
        // public site renders every one of these routes as a designed page
        // (`/history`, `/attendees`, `/frame`...), not through the generic
        // `[slug]` template, so a page reference would only add a way for the
        // link to vanish — an unpublished `history` row would drop the header
        // entry while `/history` itself still rendered. The list mirrors the
        // frontend's shipped `primaryNav` and `footerColumns`, which are what
        // render when this menu is unreachable or emptied.
        /** @var array<string, array{name: string, name_bn: string, items: array<int, array{label: string, label_bn: string, url: string}>}> $menus */
        $menus = [
            'primary' => [
                'name' => 'Primary navigation',
                'name_bn' => 'প্রধান মেনু',
                'items' => [
                    ['label' => 'Home', 'label_bn' => 'হোম', 'url' => '/'],
                    ['label' => 'Our History', 'label_bn' => 'আমাদের ইতিহাস', 'url' => '/history'],
                    ['label' => 'Events', 'label_bn' => 'অনুষ্ঠানাবলী', 'url' => '/event'],
                    ['label' => 'Attendees', 'label_bn' => 'অংশগ্রহণকারী', 'url' => '/attendees'],
                    ['label' => 'Gallery', 'label_bn' => 'গ্যালারি', 'url' => '/gallery'],
                    ['label' => 'Souvenir', 'label_bn' => 'স্মরণিকা', 'url' => '/souvenir'],
                    ['label' => 'Frame', 'label_bn' => 'ফ্রেম', 'url' => '/frame'],
                    ['label' => 'Contact', 'label_bn' => 'যোগাযোগ', 'url' => '/contact'],
                ],
            ],
            'footer' => [
                'name' => 'Footer navigation',
                'name_bn' => 'ফুটার মেনু',
                'items' => [
                    ['label' => 'Our History', 'label_bn' => 'আমাদের ইতিহাস', 'url' => '/history'],
                    ['label' => 'Programme Schedule', 'label_bn' => 'অনুষ্ঠানের সূচি', 'url' => '/event'],
                    ['label' => 'Tickets & Registration', 'label_bn' => 'টিকিট ও নিবন্ধন', 'url' => '/tickets'],
                    ['label' => 'FAQ', 'label_bn' => 'সাধারণ জিজ্ঞাসা (FAQ)', 'url' => '/faq'],
                    ['label' => 'Contact Info', 'label_bn' => 'যোগাযোগের ঠিকানা', 'url' => '/contact'],
                ],
            ],
        ];

        foreach ($menus as $code => $menu) {
            $model = Menu::updateOrCreate(
                ['code' => $code],
                ['name' => $menu['name'], 'name_bn' => $menu['name_bn'], 'is_active' => true]
            );

            foreach ($menu['items'] as $position => $item) {
                MenuItem::updateOrCreate(
                    ['menu_id' => $model->id, 'position' => $position, 'parent_id' => null],
                    [
                        'label' => $item['label'],
                        'label_bn' => $item['label_bn'],
                        // Cleared explicitly: a row seeded by the earlier
                        // page-linked version of this list would otherwise
                        // keep its reference, which wins over `url`.
                        'content_page_id' => null,
                        'url' => $item['url'],
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
