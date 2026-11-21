<?php

namespace Database\Factories;

use App\Models\FeaturedProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FeaturedProvider> */
class FeaturedProviderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = fake()->unique()->numerify('###');

        return [
            'name_en' => 'Synthetic Brand '.$n,
            'name_ar' => 'علامة تجريبية '.$n,
            'provider_type_id' => null,
            'logo_path' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
