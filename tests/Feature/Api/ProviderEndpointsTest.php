<?php

namespace Tests\Feature\Api;

use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;

/**
 * Contract tests: every endpoint's JSON is compared with a synthetic snapshot in
 * tests/fixtures/api (structure AND values; any extra or missing key fails), plus behaviour
 * tests for filters, pagination bounds, validation and leakage.
 */
class ProviderEndpointsTest extends ApiTestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedContractDataset();
        $this->token = $this->viewerToken();
    }

    // ------------------------------------------------------------------ contract snapshots

    public function test_list_matches_the_contract_snapshot_in_english_and_arabic(): void
    {
        $this->assertMatchesSnapshot('providers_index_en', $this->api('/api/v2/providers', $this->token)->assertOk());
        $this->assertMatchesSnapshot('providers_index_ar', $this->api('/api/v2/providers?lang=ar', $this->token)->assertOk());
    }

    public function test_detail_matches_the_contract_snapshot(): void
    {
        $this->assertMatchesSnapshot('provider_show', $this->api('/api/v2/providers/1', $this->token)->assertOk());
        $this->assertMatchesSnapshot('provider_show_fallback', $this->api('/api/v2/providers/3?lang=en', $this->token)->assertOk());
    }

    public function test_names_coordinates_distribution_types_and_featured_match_their_snapshots(): void
    {
        $this->assertMatchesSnapshot('providers_names', $this->api('/api/v2/providers/names', $this->token)->assertOk());
        $this->assertMatchesSnapshot('providers_coordinates', $this->api('/api/v2/providers/coordinates?lang=ar', $this->token)->assertOk());
        $this->assertMatchesSnapshot('providers_distribution', $this->api('/api/v2/providers/distribution', $this->token)->assertOk());
        $this->assertMatchesSnapshot('provider_types', $this->api('/api/v2/provider-types', $this->token)->assertOk());
        $this->assertMatchesSnapshot('featured_providers', $this->api('/api/v2/featured-providers', $this->token)->assertOk());
    }

    public function test_error_envelopes_match_their_snapshots(): void
    {
        $this->assertMatchesSnapshot('error_validation', $this->api('/api/v2/providers?per_page=0&sort=password&lang=fr', $this->token)->assertStatus(422));
        $this->assertMatchesSnapshot('error_not_found', $this->api('/api/v2/providers/999', $this->token)->assertStatus(404));
    }

    // ------------------------------------------------------------------ behaviour

    public function test_list_returns_only_active_non_deleted_providers_with_the_envelope(): void
    {
        $response = $this->api('/api/v2/providers', $this->token)->assertOk();

        $response->assertJsonPath('meta.total', 3)->assertJsonPath('meta.api_version', 'v2');
        $this->assertSame([1, 2, 3], array_column($response->json('data'), 'id'));
        $this->assertSame(['data', 'meta', 'links'], array_keys($response->json()));
        $this->assertSame(['api_version', 'current_page', 'last_page', 'per_page', 'total', 'from', 'to', 'lang'], array_keys($response->json('meta')));
        $this->assertSame(['self', 'first', 'last', 'prev', 'next'], array_keys($response->json('links')));
    }

    public function test_provider_values_are_exposed_exactly_as_stored_with_float_coordinates(): void
    {
        $p = $this->api('/api/v2/providers/1', $this->token)->json('data');

        $this->assertSame('0100 000 0001', $p['phone']); // string, spacing and leading zero untouched
        $this->assertSame('Sat-Thu 09:00-17:00', $p['hours']);
        $this->assertSame('10% to 20% discount', $p['discount_text']);
        $this->assertSame(30.0444196, $p['latitude']);
        $this->assertIsFloat($p['longitude']);
        $this->assertSame('01000000002', $this->api('/api/v2/providers/2', $this->token)->json('data.phone'));
        $this->assertNull($this->api('/api/v2/providers/2', $this->token)->json('data.latitude'));
    }

    public function test_locale_selection_and_explicit_fallback_flag(): void
    {
        $en = $this->api('/api/v2/providers/1?lang=en', $this->token)->json('data');
        $ar = $this->api('/api/v2/providers/1?lang=ar', $this->token)->json('data');
        $this->assertSame(['Synthetic Clinic One', 'en', false], [$en['name'], $en['locale'], $en['locale_fallback']]);
        $this->assertSame(['عيادة تجريبية واحد', 'ar', false], [$ar['name'], $ar['locale'], $ar['locale_fallback']]);
        $this->assertSame('عيادة', $ar['type']['label']);

        // Provider 2 has no Arabic text: English is used and flagged, never invented.
        $fallback = $this->api('/api/v2/providers/2?lang=ar', $this->token)->json('data');
        $this->assertSame(['Synthetic Lab Two', 'en', true], [$fallback['name'], $fallback['locale'], $fallback['locale_fallback']]);
        $this->assertSame(['en'], $fallback['available_locales']);

        // Provider 3 only has Arabic: the default locale is missing, so any translation is used and flagged.
        $arOnly = $this->api('/api/v2/providers/3?lang=en', $this->token)->json('data');
        $this->assertSame(['ar', true], [$arOnly['locale'], $arOnly['locale_fallback']]);
    }

    public function test_filters(): void
    {
        $ids = fn (string $qs) => array_column($this->api('/api/v2/providers'.$qs, $this->token)->assertOk()->json('data'), 'id');

        $this->assertSame([2, 3], $ids('?type=laboratory'));
        $this->assertSame([1], $ids('?type=clinic'));
        $this->assertSame([1], $ids('?city=Cairo'));
        $this->assertSame([], $ids('?city=Cair'), 'city is an exact match');
        $this->assertSame([2], $ids('?district=Dokki'));
        $this->assertSame([3], $ids('?lang=ar&district='.rawurlencode('الدقي')));
        $this->assertSame([], $ids('?lang=en&district='.rawurlencode('الدقي')), 'filters are scoped to the requested locale');
        $this->assertSame([4], $ids('?is_active=false'));
        $this->assertSame([1, 2, 3], $ids('?is_active=true'));
        $this->assertSame([1], $ids('?q=Clinic+One'));
        $this->assertSame([3], $ids('?q='.rawurlencode('تجريبي ثلاثة')));
        $this->assertSame([1], $ids('?q=0100+000+0001'));
    }

    public function test_search_wildcards_are_literal(): void
    {
        $ids = fn (string $term) => array_column($this->api('/api/v2/providers?q='.rawurlencode($term), $this->token)->assertOk()->json('data'), 'id');

        $this->assertSame([], $ids('%'), '% must not match everything');
        $this->assertSame([], $ids('_'), '_ must not match any single character');
        $this->assertSame([], $ids('Synthetic%Two'));
        $this->assertSame([2], $ids('Lab Two'));

        $provider = Provider::query()->findOrFail(2);
        $provider->translations()->where('locale', 'en')->update(['specialization' => '100% pure_lab']);
        $this->assertSame([2], $ids('100% pure_lab'));
        $this->assertSame([2], $ids('0% p'));
    }

    public function test_sorting_is_whitelisted_with_a_stable_tie_break(): void
    {
        $ids = fn (string $qs) => array_column($this->api('/api/v2/providers'.$qs, $this->token)->assertOk()->json('data'), 'id');

        $this->assertSame([3, 2, 1], $ids('?sort=-id'));
        // name asc (English): Clinic One(1) < Lab Two(2); provider 3 has no English name and sorts last.
        $this->assertSame([1, 2, 3], $ids('?sort=name'));
        $this->assertSame([2, 1, 3], $ids('?sort=-name&lang=en'), 'missing names stay last in both directions');
        // Equal updated_at for all rows: the id tie-break keeps the order deterministic.
        $this->assertSame([1, 2, 3], $ids('?sort=updated_at'));
        $this->assertSame($ids('?sort=updated_at'), $ids('?sort=updated_at'));
    }

    public function test_pagination_is_bounded_and_carries_the_query_string(): void
    {
        config(['providers.api.max_per_page' => 2, 'providers.api.default_per_page' => 2]);

        $page1 = $this->api('/api/v2/providers?type=laboratory&per_page=1', $this->token)->assertOk();
        $page1->assertJsonPath('meta.per_page', 1)->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 2);
        $this->assertStringContainsString('type=laboratory', (string) $page1->json('links.next'));
        $this->assertNull($page1->json('links.prev'));

        $this->api('/api/v2/providers?type=laboratory&per_page=1&page=2', $this->token)->assertOk()->assertJsonPath('data.0.id', 3)->assertJsonPath('links.next', null);
        $this->api('/api/v2/providers?page=99', $this->token)->assertOk()->assertJsonPath('data', []);

        // Default applies when per_page is omitted; above the maximum is rejected (422), never silently honoured.
        $this->api('/api/v2/providers', $this->token)->assertOk()->assertJsonPath('meta.per_page', 2);
        $this->api('/api/v2/providers?per_page=3', $this->token)->assertStatus(422)->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonValidationErrorFor('per_page', 'error.details');
    }

    public function test_validation_errors_use_the_422_envelope(): void
    {
        foreach (['per_page=abc', 'per_page=0', 'page=0', 'page=x', 'sort=password', 'sort=-created_by', 'lang=fr', 'type=unknown', 'is_active=maybe', 'q[]=a', str_repeat('q=', 1).str_repeat('a', 101)] as $qs) {
            $r = $this->api('/api/v2/providers?'.$qs, $this->token)->assertStatus(422);
            $r->assertJsonPath('error.code', 'validation_failed');
            $this->assertIsArray($r->json('error.details'));
            $this->assertSame(['error'], array_keys($r->json()));
        }

        $this->api('/api/v2/providers/coordinates?per_page=5001', $this->token)->assertStatus(422);
        $this->api('/api/v2/providers/names?per_page=101', $this->token)->assertStatus(422);
    }

    public function test_query_count_is_constant_regardless_of_result_size(): void
    {
        // Sanctum rewrites last_used_at only when the second changes; freeze time so that write
        // happens once in the warm-up and never skews the comparison below.
        $this->freezeTime();
        $this->api('/api/v2/provider-types', $this->token)->assertOk(); // warm-up: Sanctum writes last_used_at on first use
        $small = $this->queryCount(fn () => $this->api('/api/v2/providers?per_page=100', $this->token)->assertOk());
        $coordsSmall = $this->queryCount(fn () => $this->api('/api/v2/providers/coordinates', $this->token)->assertOk());

        $type = ProviderType::query()->where('key', 'clinic')->firstOrFail();
        Provider::factory()->count(40)->for($type, 'type')->bilingual()->create(['latitude' => 30.1, 'longitude' => 31.2]);
        $this->app['auth']->forgetGuards();

        $large = $this->queryCount(fn () => $this->api('/api/v2/providers?per_page=100', $this->token)->assertOk());
        $this->assertSame($small, $large, 'listing must not run per-row queries (N+1)');

        $this->assertSame($coordsSmall, $this->queryCount(fn () => $this->api('/api/v2/providers/coordinates', $this->token)->assertOk()));
    }

    public function test_detail_is_404_for_unknown_deleted_and_non_numeric_ids(): void
    {
        $this->api('/api/v2/providers/999', $this->token)->assertStatus(404)->assertJsonPath('error.code', 'not_found');
        $this->api('/api/v2/providers/5', $this->token)->assertStatus(404); // soft-deleted
        $this->api('/api/v2/providers/abc', $this->token)->assertStatus(404);
    }

    public function test_names_are_distinct_alphabetical_and_paginated(): void
    {
        Provider::query()->findOrFail(2)->translations()->create([
            'locale' => 'ar', 'name' => 'معمل تجريبي ثلاثة', 'address' => 'x',
        ]);

        $en = $this->api('/api/v2/providers/names?lang=en', $this->token)->assertOk();
        $this->assertSame(['Synthetic Clinic One', 'Synthetic Lab Two'], $en->json('data'));

        $ar = $this->api('/api/v2/providers/names?lang=ar', $this->token)->assertOk();
        $this->assertSame(['عيادة تجريبية واحد', 'معمل تجريبي ثلاثة'], $ar->json('data'), 'duplicate names appear once');

        $this->api('/api/v2/providers/names?per_page=1', $this->token)->assertJsonPath('meta.total', 2)->assertJsonCount(1, 'data');
    }

    public function test_coordinates_only_include_providers_with_coordinates_and_are_capped(): void
    {
        $r = $this->api('/api/v2/providers/coordinates', $this->token)->assertOk();
        $this->assertSame([1, 3], array_column($r->json('data'), 'id'));
        $this->assertSame(['id', 'name', 'locale_fallback', 'type', 'latitude', 'longitude'], array_keys($r->json('data.0')));
        $this->assertSame(-33.8688197, $r->json('data.1.latitude'));

        config(['providers.api.coordinates_max' => 1, 'providers.api.coordinates_default_per_page' => 5]);
        $capped = $this->api('/api/v2/providers/coordinates', $this->token)->assertOk();
        $this->assertCount(1, $capped->json('data'));
        $capped->assertJsonPath('meta.total', 2)->assertJsonPath('meta.max_per_page', 1);
        $this->api('/api/v2/providers/coordinates?per_page=2', $this->token)->assertStatus(422);
        $this->assertSame([1], array_column($this->api('/api/v2/providers/coordinates?type=clinic', $this->token)->json('data'), 'id'));
    }

    public function test_distribution_counts_canonical_providers_not_translations(): void
    {
        $r = $this->api('/api/v2/providers/distribution', $this->token)->assertOk();

        $this->assertSame([
            ['type' => 'clinic', 'label' => 'Clinic', 'providers' => 1],       // provider 1 (bilingual) counts once; 4 inactive, 5 deleted
            ['type' => 'laboratory', 'label' => 'Laboratory', 'providers' => 2],
        ], $r->json('data'));
        $r->assertJsonPath('meta.total_providers', 3);
        $this->assertStringContainsString('counts once', (string) $r->json('meta.definition'));
        $this->assertNotContains('retired', array_column($r->json('data'), 'type'), 'inactive types are not listed');
    }

    public function test_provider_types_and_featured_providers(): void
    {
        $types = $this->api('/api/v2/provider-types?lang=ar', $this->token)->assertOk();
        $this->assertSame(['clinic', 'laboratory'], array_column($types->json('data'), 'key'));
        $this->assertSame('معمل تحاليل', $types->json('data.1.label'));

        $featured = $this->api('/api/v2/featured-providers', $this->token)->assertOk();
        $this->assertSame([2, 1], array_column($featured->json('data'), 'id'), 'ordered by sort_order then id, inactive excluded');
        $this->assertSame('Synthetic Brand B', $this->api('/api/v2/featured-providers?lang=ar', $this->token)->json('data.1.name_en'));
        // name falls back to the other language when the requested one is empty
        $this->assertSame('Synthetic Brand A', $this->api('/api/v2/featured-providers?lang=ar', $this->token)->json('data.0.name'));
    }

    public function test_no_sensitive_fields_or_internal_paths_leak_anywhere(): void
    {
        $user = User::factory()->admin()->create(['email' => 'secret.admin@leak-check.test', 'name' => 'Leak Check Admin']);
        Provider::query()->whereKey(1)->update(['created_by' => $user->id, 'updated_by' => $user->id]);
        $this->app['auth']->forgetGuards();

        $forbiddenKeys = ['revision', 'created_by', 'updated_by', 'migration_batch_id', 'logo_path', 'deleted_at', 'password', 'remember_token', 'provider_type_id', 'created_at'];
        $forbiddenText = ['secret.admin@leak-check.test', 'Leak Check Admin', 'secret-internal-path', 'featured-logos', 'storage/', '/var/', 'bootstrap/cache'];

        foreach (['/api/v2/providers', '/api/v2/providers/1', '/api/v2/providers/names', '/api/v2/providers/coordinates', '/api/v2/providers/distribution', '/api/v2/provider-types', '/api/v2/featured-providers'] as $uri) {
            $body = (string) $this->api($uri, $this->token)->assertOk()->getContent();
            $decoded = json_decode($body, true);
            $keys = [];
            $collect = function ($node) use (&$collect, &$keys) {
                if (is_array($node)) {
                    foreach ($node as $k => $v) {
                        if (is_string($k)) {
                            $keys[] = $k;
                        }
                        $collect($v);
                    }
                }
            };
            $collect($decoded);

            $this->assertSame([], array_values(array_intersect($forbiddenKeys, $keys)), "$uri leaks internal keys");
            foreach ($forbiddenText as $needle) {
                $this->assertStringNotContainsString($needle, $body, "$uri leaks '$needle'");
            }
        }
    }
}
