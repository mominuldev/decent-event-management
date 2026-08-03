<?php

namespace Database\Factories;

use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Models\ContentPageRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentPageRevision>
 */
class ContentPageRevisionFactory extends Factory
{
    protected $model = ContentPageRevision::class;

    public function definition(): array
    {
        return [
            'content_page_id' => ContentPage::factory(),
            'revision_number' => 1,
            'title' => fake()->sentence(4),
            'title_bn' => 'শতবর্ষ পাতা',
            'excerpt' => fake()->paragraph(),
            'blocks_snapshot' => [],
            'status_at_capture' => 'draft',
            'change_note' => fake()->sentence(),
        ];
    }
}
