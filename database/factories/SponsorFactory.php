<?php

namespace Database\Factories;

use App\Domain\Content\Models\Sponsor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsor>
 */
class SponsorFactory extends Factory
{
    protected $model = Sponsor::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'name_bn' => 'পৃষ্ঠপোষক প্রতিষ্ঠান',
            'tier' => fake()->randomElement(Sponsor::TIERS),
            'website_url' => fake()->url(),
            'description' => fake()->sentence(),
            'position' => 0,
            'is_published' => true,
        ];
    }

    public function tier(string $tier): static
    {
        return $this->state(fn (): array => ['tier' => $tier]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
