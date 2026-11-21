<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data only. No user accounts are ever seeded: provision them with
     * `php artisan users:provision`. Synthetic demo providers: `php artisan db:seed --class=SyntheticProvidersSeeder`.
     */
    public function run(): void
    {
        $this->call(ProviderTypeSeeder::class);
    }
}
