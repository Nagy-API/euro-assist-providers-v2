<?php

namespace Tests\Feature\Api;

use App\Models\LegacyIdMap;
use App\Models\LegacyMigrationBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Contract of the public V1-compatible read API (ADR-021). Synthetic data only. Consumer contract taken from
 * the public website: GET /api/arabic-providers?fields=city|type for the dropdowns and
 * GET /api/arabic-providers?type=&city=&search= for the results.
 */
class LegacyApiCompatTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['providers.legacy_api.server_cache_seconds' => 0, 'providers.legacy_api.enabled' => true, 'providers.legacy_api.rate_limit_per_minute' => 1000]);
        $this->seedContractDataset();
    }

    private function pub(string $uri)
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson($uri); // NO credentials: the endpoints are public like V1
    }

    public function test_dropdown_projections_need_no_credentials_and_return_city_and_type_rows(): void
    {
        $cities = $this->pub('/api/arabic-providers?fields=city')->assertOk()->json();
        $this->assertSame([['city' => 'الجيزة'], ['city' => 'القاهرة']], $cities);

        $types = $this->pub('/api/arabic-providers?fields=type')->assertOk()->json();
        $this->assertSame([['type' => 'عيادة'], ['type' => 'معمل تحاليل']], $types);

        $this->assertSame([['city' => 'Cairo'], ['city' => 'Giza']], $this->pub('/api/providers?fields=city')->json());
        $this->assertSame([['type' => 'Clinic'], ['type' => 'Laboratory']], $this->pub('/api/providers?fields=type')->json());
    }

    public function test_arabic_list_has_the_v1_row_shape_and_only_active_rows_with_an_arabic_translation(): void
    {
        $rows = $this->pub('/api/arabic-providers')->assertOk()->json();

        $this->assertCount(2, $rows, 'inactive, deleted and English-only providers are excluded');
        $this->assertSame(
            ['id', 'name', 'type', 'address', 'city', 'district', 'discount_pct', 'hours', 'phone', 'email', 'website', 'map_url',
                'logo_path', 'logo_url', 'specialization', 'package', 'created_at', 'updated_at', 'latitude', 'longitude'],
            array_keys($rows[0]),
        );
        $this->assertSame('عيادة تجريبية واحد', $rows[0]['name']);
        $this->assertSame('عيادة', $rows[0]['type']);
        $this->assertSame('القاهرة', $rows[0]['city']);
        $this->assertSame(30.0444196, $rows[0]['latitude']);
        $this->assertArrayNotHasKey('is_translated', $rows[0]);
    }

    public function test_english_list_adds_is_translated_and_filters_like_v1(): void
    {
        $rows = $this->pub('/api/providers')->assertOk()->json();
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]['is_translated'], 'has an Arabic counterpart');
        $this->assertSame(0, $rows[1]['is_translated']);

        $this->assertCount(1, $this->pub('/api/providers?type=Lab')->json());
        $this->assertCount(1, $this->pub('/api/providers?city=cai')->json());
        $this->assertCount(1, $this->pub('/api/providers?district=Dokki')->json());
        $this->assertCount(1, $this->pub('/api/providers?search=Clinic%20One')->json());
        $this->assertCount(1, $this->pub('/api/providers?search=01000000002')->json(), 'phone is searched');
        $this->assertCount(0, $this->pub('/api/providers?search=zzz')->json());
        $this->assertCount(1, $this->pub('/api/providers?searchName=Synthetic%20%20Lab%20Two')->json());
        // The Arabic type spelling also finds English rows (type matches any language label or the key).
        $this->assertCount(1, $this->pub('/api/providers?type='.rawurlencode('معمل'))->json());
        // Wildcards are literal.
        $this->assertCount(0, $this->pub('/api/providers?search=%25')->json());
    }

    public function test_dropdown_selection_round_trips_through_the_filters_in_arabic(): void
    {
        $rows = $this->pub('/api/arabic-providers?type='.rawurlencode('معمل تحاليل').'&city='.rawurlencode('الجيزة'))->assertOk()->json();
        $this->assertCount(1, $rows);
        $this->assertSame('معمل تجريبي ثلاثة', $rows[0]['name']);
    }

    public function test_governorate_parameter_is_ignored_not_mapped_onto_another_field(): void
    {
        $this->assertCount(2, $this->pub('/api/arabic-providers?governorate=anything')->assertOk()->json());
    }

    public function test_pagination_envelope_matches_v1_and_is_opt_in(): void
    {
        $page = $this->pub('/api/arabic-providers?per_page=1')->assertOk()->json();
        $this->assertSame(['data', 'meta', 'links'], array_keys($page));
        $this->assertSame(['current_page', 'last_page', 'per_page', 'total', 'from', 'to'], array_keys($page['meta']));
        $this->assertSame(['self', 'next', 'prev'], array_keys($page['links']));
        $this->assertSame(2, $page['meta']['total']);
        $this->assertCount(1, $page['data']);

        $this->assertArrayHasKey('data', $this->pub('/api/arabic-providers?paginate=true')->json());
        $this->assertCount(2, $this->pub('/api/arabic-providers?per_page=99999999')->assertOk()->json('data'), 'oversized per_page is capped, not an error');
    }

    public function test_search_endpoint_requires_a_query_with_the_v1_validation_error_and_is_paginated(): void
    {
        $this->pub('/api/providers/search')->assertStatus(422)->assertExactJson([
            'message' => 'Validation error.', 'errors' => ['query' => ['The query parameter is required.']],
        ]);

        $res = $this->pub('/api/providers/search?query=Cardiology')->assertOk()->json();
        $this->assertSame(1, $res['meta']['total']);
        $this->assertSame('Synthetic Clinic One', $res['data'][0]['name']);
        $this->assertSame(1, $this->pub('/api/providers/search?q=Dokki')->json()['meta']['total']);
        $this->assertSame(1, $this->pub('/api/arabic-providers/search?query='.rawurlencode('الدقي'))->json()['meta']['total']);
    }

    public function test_show_names_coordinates_and_distribution_keep_the_v1_formats(): void
    {
        $this->assertSame('Synthetic Clinic One', $this->pub('/api/providers/1000001')->assertOk()->json('name'));
        $this->pub('/api/providers/99999')->assertNotFound();
        $this->pub('/api/providers/1000004')->assertNotFound(); // inactive
        $this->pub('/api/providers/1000005')->assertNotFound(); // deleted
        $this->pub('/api/arabic-providers/1000002')->assertNotFound(); // English-only provider has no Arabic row

        $names = $this->pub('/api/providers/distinct/names')->assertOk()->json();
        $this->assertSame(['names' => ['Synthetic Clinic One', 'Synthetic Lab Two']], $names);

        $ll = $this->pub('/api/arabic-providers/all-latlng')->assertOk()->json();
        $this->assertSame(['data'], array_keys($ll));
        $this->assertSame(['name', 'type', 'latitude', 'longitude'], array_keys($ll['data'][0]));
        $this->assertCount(2, $ll['data']);

        $dist = $this->pub('/api/providers/distribution/stats')->assertOk()->json();
        $this->assertSame(['distribution', 'total_types', 'total_providers'], array_keys($dist));
        $this->assertSame(['type', 'display_name', 'english_count', 'arabic_count', 'max_count', 'color', 'icon'], array_keys($dist['distribution'][0]));
        $this->assertSame(2, $dist['total_types']);
        $this->assertSame(2, $dist['total_providers']);
        $clinic = collect($dist['distribution'])->firstWhere('type', 'clinic');
        $this->assertSame([1, 1, 1, '#0d6efd'], [$clinic['english_count'], $clinic['arabic_count'], $clinic['max_count'], $clinic['color']]);
        $this->pub('/api/arabic-providers/distribution/stats')->assertNotFound(); // V1 declared it for English only
    }

    public function test_top_providers_keeps_the_v1_format_and_hides_storage_paths(): void
    {
        $res = $this->pub('/api/top-providers')->assertOk()->json();
        $this->assertTrue($res['success']);
        $this->assertCount(2, $res['data'], 'inactive featured entry hidden');
        $this->assertSame(['id', 'name_english', 'name_arabic', 'type_en', 'type_ar', 'logo_url', 'created_at', 'updated_at'], array_keys($res['data'][0]));
        $this->assertSame('Synthetic Brand A', $res['data'][0]['name_english']);
        $this->assertSame('laboratory', $res['data'][1]['type_en']);
        $this->assertSame('معمل تحاليل', $res['data'][1]['type_ar']);
        $this->assertStringNotContainsString('secret-internal-path', json_encode($res));
        $this->assertNull($res['data'][1]['logo_url'], 'a missing file never produces a broken URL');
    }

    public function test_legacy_ids_are_preserved_through_legacy_id_maps(): void
    {
        $batch = LegacyMigrationBatch::query()->create(['uuid' => (string) Str::uuid(), 'snapshot_sha256' => str_repeat('a', 64), 'mapping_version' => 'v1']);
        foreach ([['providers', 777, 'en', 1], ['arabic_providers', 888, 'ar', 1]] as [$table, $sid, $loc, $pid]) {
            LegacyIdMap::query()->create(['source_table' => $table, 'source_id' => $sid, 'source_locale' => $loc, 'provider_id' => $pid,
                'snapshot_sha256' => str_repeat('a', 64), 'migration_batch_id' => $batch->id]);
        }

        $this->assertSame(777, $this->pub('/api/providers?search=Clinic%20One')->json('0.id'));
        $this->assertSame(888, $this->pub('/api/arabic-providers?search='.rawurlencode('واحد'))->json('0.id'));
        $this->assertSame('Synthetic Clinic One', $this->pub('/api/providers/777')->assertOk()->json('name'));
        $this->pub('/api/providers/888')->assertNotFound(); // an Arabic id is not an English id
        $this->assertSame(1000000 + 2, $this->pub('/api/providers?search=Lab')->json('0.id'), 'unmapped = offset + V2 id');
        $this->assertSame('Synthetic Lab Two', $this->pub('/api/providers/1000002')->assertOk()->json('name'));
        $this->pub('/api/providers/1000001')->assertNotFound(); // mapped providers are not reachable through the offset
    }

    public function test_only_read_verbs_exist_and_nothing_administrative_is_exposed(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            foreach (['/api/providers', '/api/providers/1', '/api/arabic-providers', '/api/arabic-providers/1', '/api/top-providers', '/api/login', '/api/logout'] as $uri) {
                $this->app['auth']->forgetGuards();
                $status = $this->json(strtoupper($verb), $uri)->getStatusCode();
                $this->assertContains($status, [404, 405], "$verb $uri must not be served (got $status)");
            }
        }
        $legacy = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'legacy.'));
        $this->assertGreaterThan(10, $legacy->count());
        foreach ($legacy as $route) {
            $this->assertSame(['GET', 'HEAD'], $route->methods());
        }

        $body = json_encode($this->pub('/api/providers')->json());
        foreach (['created_by', 'updated_by', 'revision', 'migration_batch', 'password', 'remember_token', 'photo_path', 'merged_into'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }

    public function test_unsafe_urls_are_never_returned_and_responses_are_cross_origin_readable(): void
    {
        DB::table('providers')->where('id', 1)->update(['website' => 'javascript:alert(1)', 'map_url' => 'data:text/html,x']);

        $row = $this->pub('/api/providers?search=Clinic%20One')->json('0');
        $this->assertNull($row['website']);
        $this->assertNull($row['map_url']);

        $this->app['auth']->forgetGuards();
        $res = $this->getJson('/api/arabic-providers?fields=city', ['Origin' => 'https://medicalcard.example.test']);
        $this->assertSame('*', $res->headers->get('Access-Control-Allow-Origin'), 'the public website reads it cross-origin');
    }

    public function test_kill_switch_turns_every_route_off(): void
    {
        config(['providers.legacy_api.enabled' => false]);
        $this->pub('/api/arabic-providers?fields=city')->assertNotFound();
    }

    public function test_rate_limit_applies_per_client(): void
    {
        config(['providers.legacy_api.rate_limit_per_minute' => 2]);
        $codes = [];
        for ($i = 0; $i < 4; $i++) {
            $codes[] = $this->pub('/api/top-providers')->getStatusCode();
        }
        $this->assertSame([200, 200, 429, 429], $codes);
    }

    public function test_v2_api_still_requires_authentication(): void
    {
        $this->pub('/api/v2/providers')->assertStatus(401);
        $this->pub('/api/v2/featured-providers')->assertStatus(401);
    }

    public function test_whole_table_reads_are_cached_server_side_and_long_filters_are_capped(): void
    {
        config(['providers.legacy_api.server_cache_seconds' => 60]);
        $this->assertCount(2, $this->pub('/api/arabic-providers')->json());
        \DB::table('providers')->update(['is_active' => false]);
        $this->assertCount(2, $this->pub('/api/arabic-providers')->json(), 'served from the 60 s cache');
        config(['providers.legacy_api.server_cache_seconds' => 0]);
        $this->assertCount(0, $this->pub('/api/arabic-providers')->json());

        $this->pub('/api/arabic-providers?search='.str_repeat('a', 5000))->assertOk();
    }

    public function test_provider_email_is_not_public_by_default_but_the_key_stays(): void
    {
        \DB::table('providers')->where('id', 1)->update(['email' => 'private.contact@example.test']);

        foreach (['/api/providers', '/api/providers/1000001', '/api/providers/search?query=Cardiology', '/api/arabic-providers'] as $uri) {
            $body = $this->pub($uri)->assertOk()->getContent();
            $this->assertStringNotContainsString('private.contact', $body);
            $this->assertStringContainsString('"email"', $body, 'schema unchanged: key present');
        }
        $this->assertNull($this->pub('/api/providers/1000001')->json('email'));
        $this->assertSame(0, $this->pub('/api/providers/search?query=private.contact')->json('meta.total'), 'not discoverable by search');
        $this->assertCount(0, $this->pub('/api/providers?search=private.contact')->json());

        config(['providers.legacy_api.expose_email' => true]);
        $this->assertSame('private.contact@example.test', $this->pub('/api/providers/1000001')->json('email'));
    }
}
