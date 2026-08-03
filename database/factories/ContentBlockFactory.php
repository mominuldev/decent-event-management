<?php

namespace Database\Factories;

use App\Domain\Content\Models\ContentBlock;
use App\Domain\Content\Models\ContentPage;
use App\Domain\Content\Support\ContentLocale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentBlock>
 */
class ContentBlockFactory extends Factory
{
    protected $model = ContentBlock::class;

    public function definition(): array
    {
        return [
            'content_page_id' => ContentPage::factory(),
            'type' => 'rich_text',
            'position' => 0,
            'data' => ['heading' => fake()->sentence(3), 'body' => fake()->paragraph()],
            'data_bn' => ['heading' => 'শিরোনাম', 'body' => 'বাংলা বিবরণ।'],
            'is_visible' => true,
        ];
    }

    public function hero(): static
    {
        return $this->state(fn (): array => [
            'type' => 'hero',
            'data' => ['heading' => 'Centenary Celebration', 'subheading' => 'One hundred years'],
            'data_bn' => ['heading' => 'শতবর্ষ উদযাপন', 'subheading' => 'একশ বছর'],
        ]);
    }

    /**
     * Only the English side filled in — exercises per-key fallback in
     * {@see ContentLocale::pickArray()}.
     */
    public function englishOnly(): static
    {
        return $this->state(fn (): array => ['data_bn' => null]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['is_visible' => false]);
    }
}
