<?php

namespace Database\Factories;

use App\Domain\Content\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Menu>
 */
class MenuFactory extends Factory
{
    protected $model = Menu::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->lexify('menu_????'),
            'name' => fake()->words(2, true),
            'name_bn' => 'মেনু',
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
