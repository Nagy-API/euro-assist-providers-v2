<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic providers only. Names, phones and addresses are generated and never derived
 * from the legacy snapshot.
 *
 * @extends Factory<Provider>
 */
class ProviderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider_type_id' => ProviderType::factory(),
            'phone' => '0'.fake()->numerify('1#########'), // leading zero preserved as text
            'email' => null,
            'website' => null,
            'map_url' => null,
            'latitude' => null,
            'longitude' => null,
            'logo_path' => null,
            'is_active' => true,
        ];
    }

    /** Both languages, with synthetic text. */
    public function bilingual(): static
    {
        return $this->afterCreating(function (Provider $provider) {
            $n = fake()->unique()->numerify('####');
            $provider->translations()->create([
                'locale' => 'en', 'name' => 'Synthetic Clinic '.$n, 'address' => '12 Test Street, Block '.$n,
                'city' => 'Cairo', 'district' => 'Nasr City', 'discount_text' => '10% to 20% discount',
            ]);
            $provider->translations()->create([
                'locale' => 'ar', 'name' => 'عيادة تجريبية '.$n, 'address' => '١٢ شارع الاختبار، مبنى '.$n,
                'city' => 'القاهرة', 'district' => 'مدينة نصر', 'discount_text' => 'خصم من ١٠٪ إلى ٢٠٪',
            ]);
        });
    }

    /** English only. */
    public function englishOnly(): static
    {
        return $this->afterCreating(function (Provider $provider) {
            $n = fake()->unique()->numerify('####');
            $provider->translations()->create([
                'locale' => 'en', 'name' => 'Synthetic English Lab '.$n, 'address' => '7 Sample Road '.$n,
                'city' => 'Giza', 'district' => 'Dokki',
            ]);
        });
    }

    /** Arabic only. */
    public function arabicOnly(): static
    {
        return $this->afterCreating(function (Provider $provider) {
            $n = fake()->unique()->numerify('####');
            $provider->translations()->create([
                'locale' => 'ar', 'name' => 'معمل عربي تجريبي '.$n, 'address' => '٧ طريق العينة '.$n,
                'city' => 'الجيزة', 'district' => 'الدقي',
            ]);
        });
    }
}
