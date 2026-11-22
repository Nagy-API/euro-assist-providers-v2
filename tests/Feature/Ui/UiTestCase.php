<?php

namespace Tests\Feature\Ui;

use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Shared helpers for the UI test suite (synthetic data only). */
abstract class UiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function editor(): User
    {
        return User::factory()->editor()->create();
    }

    protected function viewer(): User
    {
        return User::factory()->viewer()->create();
    }

    protected function type(string $en = 'Clinic', string $ar = 'عيادة', array $attributes = []): ProviderType
    {
        $type = ProviderType::factory()->create($attributes);
        $type->translations()->updateOrCreate(['locale' => 'en'], ['name' => $en]);
        $type->translations()->updateOrCreate(['locale' => 'ar'], ['name' => $ar]);

        return $type->load('translations');
    }

    /** A bilingual provider with deterministic text. */
    protected function provider(array $attributes = [], array $en = [], array $ar = [], ?ProviderType $type = null): Provider
    {
        $provider = Provider::factory()->create($attributes + ['provider_type_id' => ($type ?? $this->type())->id]);

        $provider->translations()->create(['locale' => 'en'] + $en + ['name' => 'Alpha Clinic', 'address' => '1 Test Road', 'city' => 'Cairo']);
        $provider->translations()->create(['locale' => 'ar'] + $ar + ['name' => 'عيادة ألفا', 'address' => '١ شارع الاختبار', 'city' => 'القاهرة']);

        return $provider->load('translations', 'type');
    }

    protected function englishOnlyProvider(string $name = 'Only English Lab', array $attributes = []): Provider
    {
        $provider = Provider::factory()->create($attributes + ['provider_type_id' => $this->type()->id]);
        $provider->translations()->create(['locale' => 'en', 'name' => $name, 'address' => '9 Sample Street', 'city' => 'Giza']);

        return $provider->load('translations', 'type');
    }
}
