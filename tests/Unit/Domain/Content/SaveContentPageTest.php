<?php

namespace Tests\Unit\Domain\Content;

use App\Domain\Content\Actions\SaveContentPage;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `FormRequest::validated()` rebuilds nested array data rule by rule, so its
 * array *keys* are trustworthy but its iteration order is not — a block
 * carrying an optional key (e.g. `media_ulid`) can come back before block 0.
 * `SaveContentPage` guards against this by `ksort`-ing the submitted array
 * before reindexing (see the comment on that line). This test builds the
 * array with exactly that shape — insertion order scrambled, numeric keys
 * intact — to prove the guard actually works rather than relying on PHP's
 * happens-to-already-be-sorted json_decode output.
 */
class SaveContentPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_out_of_order_block_keys_are_sorted_before_reindexing(): void
    {
        $page = ContentPage::factory()->create();
        $editor = User::factory()->create();

        $blocks = [];
        $blocks[2] = ['type' => 'cta', 'data' => ['heading' => 'Third']];
        $blocks[0] = ['type' => 'hero', 'data' => ['heading' => 'First']];
        $blocks[1] = ['type' => 'rich_text', 'data' => ['heading' => 'Second']];

        (new SaveContentPage)->execute($page, ['blocks' => $blocks], $editor);

        $types = $page->blocks()->orderBy('position')->pluck('type')->all();

        $this->assertSame(['hero', 'rich_text', 'cta'], $types);
    }
}
