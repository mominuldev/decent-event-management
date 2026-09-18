<?php

namespace Tests\Feature\Content;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use Database\Seeders\TicketsPageSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `content:backfill-seeded-fields` exists because a page seeder's ordinary
 * re-run replaces every block's data — right on a fresh database, and a way
 * to lose a week of an editor's work on a live one. These pin the additive
 * guarantee from both sides: what it adds, and what it must not touch.
 */
class BackfillSeededContentFieldsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_adds_fields_the_seeder_now_knows_and_leaves_every_edited_value_alone(): void
    {
        $this->seed(TicketsPageSeeder::class);

        /** @var ContentPage $page */
        $page = ContentPage::where('slug', 'tickets')->firstOrFail();
        /** @var ContentBlock $pricing */
        $pricing = $page->blocks()->where('type', 'ticket_pricing')->firstOrFail();
        /** @var ContentBlock $hero */
        $hero = $page->blocks()->where('position', 0)->firstOrFail();

        // A page as it stood before the card's copy joined the block: the
        // heading edited, the new keys absent, and one key cleared on purpose.
        $data = $pricing->data;
        $dataBn = $pricing->data_bn ?? [];
        $data['heading_dark'] = 'Edited heading';
        $dataBn['heading_dark'] = 'সম্পাদিত শিরোনাম';
        $data['body'] = '';
        foreach (['card_badge', 'card_tagline', 'includes', 'family_includes'] as $key) {
            unset($data[$key], $dataBn[$key]);
        }
        $pricing->update(['data' => $data, 'data_bn' => $dataBn]);

        $hero->update(['data' => ['heading_lead' => 'Edited hero'] + $hero->data, 'is_visible' => false]);
        $page->update(['title' => 'Edited title', 'status' => 'draft', 'published_at' => null]);

        // An extra block the seeder knows nothing about.
        $page->blocks()->create(['type' => 'rich_text', 'position' => 99, 'data' => ['heading' => 'Keep me'], 'data_bn' => []]);

        $this->artisan('content:backfill-seeded-fields', ['seeder' => 'TicketsPageSeeder'])
            ->assertSuccessful();

        $pricing->refresh();
        $this->assertSame('Edited heading', $pricing->data['heading_dark']);
        $this->assertSame('সম্পাদিত শিরোনাম', $pricing->data_bn['heading_dark']);
        $this->assertSame('', $pricing->data['body'], 'a deliberately cleared field must not spring back');
        $this->assertSame('Centennial Ticket', $pricing->data['card_badge']);
        $this->assertSame('শতবর্ষ টিকিট', $pricing->data_bn['card_badge']);
        $this->assertCount(5, $pricing->data['includes']);
        $this->assertCount(4, $pricing->data_bn['family_includes']);

        $hero->refresh();
        $this->assertSame('Edited hero', $hero->data['heading_lead']);
        $this->assertFalse($hero->is_visible);

        $page->refresh();
        $this->assertSame('Edited title', $page->title);
        $this->assertSame('draft', $page->status);
        $this->assertNull($page->published_at);
        $this->assertSame('Keep me', $page->blocks()->where('position', 99)->firstOrFail()->data['heading']);
    }

    public function test_a_block_of_another_type_at_a_seeded_position_is_left_alone(): void
    {
        $this->seed(TicketsPageSeeder::class);

        /** @var ContentPage $page */
        $page = ContentPage::where('slug', 'tickets')->firstOrFail();
        /** @var ContentBlock $pricing */
        $pricing = $page->blocks()->where('type', 'ticket_pricing')->firstOrFail();
        $pricing->update(['type' => 'rich_text', 'data' => ['heading' => 'Restructured'], 'data_bn' => []]);

        $this->artisan('content:backfill-seeded-fields', ['seeder' => 'TicketsPageSeeder'])->assertSuccessful();

        $pricing->refresh();
        $this->assertSame('rich_text', $pricing->type);
        $this->assertSame(['heading' => 'Restructured'], $pricing->data);
    }

    public function test_a_normal_re_seed_still_replaces_the_page_so_the_additive_mode_is_the_only_safe_one(): void
    {
        $this->seed(TicketsPageSeeder::class);
        /** @var ContentBlock $pricing */
        $pricing = ContentBlock::where('type', 'ticket_pricing')->firstOrFail();
        $pricing->update(['data' => ['heading_dark' => 'Edited'] + $pricing->data]);

        $this->seed(TicketsPageSeeder::class);

        $this->assertSame('One ticket,', $pricing->refresh()->data['heading_dark']);
    }

    public function test_it_refuses_a_seeder_that_does_not_seed_blocks(): void
    {
        $this->artisan('content:backfill-seeded-fields', ['seeder' => 'RbacSeeder'])->assertFailed();
        $this->artisan('content:backfill-seeded-fields', ['seeder' => 'NoSuchSeeder'])->assertFailed();
    }
}
