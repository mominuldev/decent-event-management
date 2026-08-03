<?php

namespace Database\Factories;

use App\Domain\Content\Models\Faq;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Faq>
 */
class FaqFactory extends Factory
{
    protected $model = Faq::class;

    public function definition(): array
    {
        return [
            'question' => fake()->sentence(6).'?',
            'question_bn' => 'নিবন্ধন কীভাবে করব?',
            'answer' => fake()->paragraph(),
            'answer_bn' => 'অনলাইনে নিবন্ধন ফরম পূরণ করুন।',
            'category' => fake()->randomElement(['registration', 'payment', 'venue']),
            'position' => 0,
            'is_published' => true,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }
}
