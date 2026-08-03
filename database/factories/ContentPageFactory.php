<?php

namespace Database\Factories;

use App\Domain\Content\Models\ContentPage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentPage>
 */
class ContentPageFactory extends Factory
{
    protected $model = ContentPage::class;

    public function definition(): array
    {
        $title = fake()->sentence(4);

        return [
            'slug' => Str::slug($title).'-'.fake()->unique()->numberBetween(1, 999999),
            'template' => 'standard',
            'title' => $title,
            'title_bn' => 'শতবর্ষ '.fake()->numberBetween(1, 100),
            'excerpt' => fake()->paragraph(),
            'excerpt_bn' => 'শতবর্ষ উদযাপনের সংক্ষিপ্ত বিবরণ।',
            'status' => 'draft',
            'published_at' => null,
            'is_indexable' => true,
            'position' => 0,
        ];
    }

    /**
     * Live to the public: published *and* the scheduled time has passed.
     */
    public function published(): static
    {
        return $this->state(fn (): array => [
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * Published but dated in the future — must stay invisible to the public
     * API until the timestamp passes.
     */
    public function scheduled(): static
    {
        return $this->state(fn (): array => [
            'status' => 'published',
            'published_at' => now()->addWeek(),
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn (): array => ['status' => 'in_review']);
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['status' => 'archived']);
    }

    public function withPreviewToken(string $token): static
    {
        return $this->state(fn (): array => ['preview_token' => $token]);
    }

    /**
     * A page with no Bangla values at all, to exercise the en fallback.
     */
    public function englishOnly(): static
    {
        return $this->state(fn (): array => [
            'title_bn' => null,
            'excerpt_bn' => null,
        ]);
    }
}
