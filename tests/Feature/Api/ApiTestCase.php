<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\Support\ApiPrincipal;
use App\Models\FeaturedProvider;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared helpers for the /api/v2 tests: bearer-token requests (guards are reset between requests
 * because every request in a test shares one application instance) and a fixed, fully synthetic
 * dataset used by the contract snapshots in tests/fixtures/api.
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['providers.api.public_read' => false, 'providers.api.rate_limit_per_minute' => 1000]);
    }

    /** @param list<string> $abilities */
    protected function tokenFor(User $user, array $abilities = ['providers:read'], ?Carbon $expiresAt = null): string
    {
        $principal = ApiPrincipal::query()->findOrFail($user->getKey());

        return $principal->createToken('test', $abilities, $expiresAt)->plainTextToken;
    }

    /** @param array<string, string> $headers */
    protected function api(string $uri, ?string $token = null, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->getJson($uri, $headers);
    }

    protected function viewerToken(): string
    {
        return $this->tokenFor(User::factory()->viewer()->create());
    }

    /**
     * Fixed synthetic dataset (explicit ids, fixed timestamps) for contract snapshots.
     */
    protected function seedContractDataset(): void
    {
        $at = '2026-01-01 00:00:00';

        $clinic = ProviderType::query()->forceCreate(['id' => 1, 'key' => 'clinic', 'is_active' => true, 'sort_order' => 1]);
        $clinic->translations()->create(['locale' => 'en', 'name' => 'Clinic']);
        $clinic->translations()->create(['locale' => 'ar', 'name' => 'عيادة']);
        $lab = ProviderType::query()->forceCreate(['id' => 2, 'key' => 'laboratory', 'is_active' => true, 'sort_order' => 2]);
        $lab->translations()->create(['locale' => 'en', 'name' => 'Laboratory']);
        $lab->translations()->create(['locale' => 'ar', 'name' => 'معمل تحاليل']);
        ProviderType::query()->forceCreate(['id' => 3, 'key' => 'retired', 'is_active' => false, 'sort_order' => 3]);

        $make = function (int $id, int $type, array $attrs, array $translations, bool $trashed = false) use ($at) {
            $provider = new Provider($attrs + ['provider_type_id' => $type, 'is_active' => true]);
            $provider->forceFill(['id' => $id, 'revision' => 3, 'created_by' => null, 'created_at' => $at, 'updated_at' => $at])->save();
            foreach ($translations as $locale => $fields) {
                $provider->translations()->create(['locale' => $locale] + $fields);
            }
            if ($trashed) {
                $provider->delete();
            }
        };

        $make(1, 1, [
            'phone' => '0100 000 0001', 'email' => 'info@example.test', 'website' => 'https://example.test',
            'map_url' => 'https://maps.example.test/?q=30.0444196,31.2357116', 'latitude' => 30.0444196, 'longitude' => 31.2357116,
        ], [
            'en' => ['name' => 'Synthetic Clinic One', 'address' => '1 Test Street', 'city' => 'Cairo', 'district' => 'Nasr City',
                'specialization' => 'Cardiology', 'hours' => 'Sat-Thu 09:00-17:00', 'discount_text' => '10% to 20% discount', 'package_text' => 'Basic, Plus'],
            'ar' => ['name' => 'عيادة تجريبية واحد', 'address' => '١ شارع الاختبار', 'city' => 'القاهرة', 'district' => 'مدينة نصر'],
        ]);
        $make(2, 2, ['phone' => '01000000002'], [
            'en' => ['name' => 'Synthetic Lab Two', 'address' => '2 Test Street', 'city' => 'Giza', 'district' => 'Dokki'],
        ]);
        $make(3, 2, ['latitude' => -33.8688197, 'longitude' => 151.2092955], [
            'ar' => ['name' => 'معمل تجريبي ثلاثة', 'address' => '٣ شارع الاختبار', 'city' => 'الجيزة', 'district' => 'الدقي'],
        ]);
        $make(4, 1, ['is_active' => false], [
            'en' => ['name' => 'Synthetic Inactive Clinic', 'address' => '4 Test Street', 'city' => 'Cairo'],
        ]);
        $make(5, 1, [], [
            'en' => ['name' => 'Synthetic Deleted Clinic', 'address' => '5 Test Street', 'city' => 'Cairo'],
        ], trashed: true);

        FeaturedProvider::query()->forceCreate(['id' => 1, 'name_en' => 'Synthetic Brand B', 'name_ar' => 'علامة ب', 'provider_type_id' => 2,
            'logo_path' => 'featured-logos/secret-internal-path.png', 'sort_order' => 2, 'is_active' => true, 'created_at' => $at, 'updated_at' => $at]);
        FeaturedProvider::query()->forceCreate(['id' => 2, 'name_en' => 'Synthetic Brand A', 'name_ar' => null, 'provider_type_id' => null,
            'sort_order' => 1, 'is_active' => true, 'created_at' => $at, 'updated_at' => $at]);
        FeaturedProvider::query()->forceCreate(['id' => 3, 'name_en' => 'Synthetic Hidden Brand', 'name_ar' => null,
            'sort_order' => 0, 'is_active' => false, 'created_at' => $at, 'updated_at' => $at]);
    }

    /** Compares a JSON body with tests/fixtures/api/<name>.json (UPDATE_API_SNAPSHOTS=1 rewrites it). */
    protected function assertMatchesSnapshot(string $name, TestResponse $response): void
    {
        $path = base_path('tests/fixtures/api/'.$name.'.json');
        // Absolute links depend on APP_URL; snapshots store them against a placeholder base.
        $base = rtrim((string) config('app.url'), '/');
        $actual = json_decode(str_replace(addcslashes($base, '/'), '{base}', (string) $response->getContent()), true, 512, JSON_THROW_ON_ERROR);

        if (getenv('UPDATE_API_SNAPSHOTS') === '1') {
            @mkdir(dirname($path), 0777, true);
            file_put_contents($path, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->assertFileExists($path, "Missing contract snapshot {$name}.json");
        $expected = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(
            $this->canonical($expected),
            $this->canonical($actual),
            "Response no longer matches the {$name} contract snapshot (keys and values must match exactly).",
        );
    }

    /** Key-sorted (maps only) JSON so list order is significant but map key order is not. */
    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $value = array_map(fn ($v) => $this->canonical($v), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    protected function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}
