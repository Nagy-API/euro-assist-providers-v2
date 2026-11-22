<?php

namespace Database\Seeders;

use App\Models\FeaturedProvider;
use App\Models\Provider;
use App\Models\ProviderType;
use Illuminate\Database\Seeder;

/**
 * Local development demo data: entirely synthetic, refuses to run in production.
 */
class SyntheticProvidersSeeder extends Seeder
{
    public function run(int $count = 120): void
    {
        if (app()->environment('production')) {
            $this->command?->error('Refusing to seed synthetic data in production.');

            return;
        }

        $this->call(ProviderTypeSeeder::class);
        $typeIds = ProviderType::query()->pluck('id')->all();

        for ($i = 0; $i < $count; $i++) {
            $factory = Provider::factory()->state(['provider_type_id' => $typeIds[$i % count($typeIds)]]);
            match ($i % 5) {
                0, 1, 2 => $factory->bilingual()->create(),
                3 => $factory->englishOnly()->create(),
                default => $factory->arabicOnly()->create(),
            };
        }

        FeaturedProvider::factory()->count(3)->create();
    }
}
