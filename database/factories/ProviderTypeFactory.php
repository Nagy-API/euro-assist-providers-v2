<?php

namespace Database\Factories;

use App\Models\ProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProviderType> */
class ProviderTypeFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'key' => 'type_'.fake()->unique()->bothify('????##'),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ProviderType $type) {
            if ($type->translations()->doesntExist()) {
                $type->translations()->create(['locale' => 'en', 'name' => 'Synthetic type '.$type->key]);
                $type->translations()->create(['locale' => 'ar', 'name' => 'نوع تجريبي '.$type->key]);
            }
        });
    }
}
