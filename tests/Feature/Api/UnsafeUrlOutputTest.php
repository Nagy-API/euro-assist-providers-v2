<?php

namespace Tests\Feature\Api;

use App\Models\Provider;

/** Stored non-http(s) links (e.g. preserved legacy data) are never handed to API consumers. */
class UnsafeUrlOutputTest extends ApiTestCase
{
    public function test_unsafe_stored_links_are_returned_as_null(): void
    {
        $unsafe = Provider::factory()->bilingual()->create(['website' => 'javascript:alert(1)', 'map_url' => 'data:text/html,x']);
        $safe = Provider::factory()->bilingual()->create(['website' => 'www.example.com', 'map_url' => 'https://maps.example.test/?q=1,2']);
        $token = $this->viewerToken();

        $this->api('/api/v2/providers/'.$unsafe->id, $token)->assertOk()
            ->assertJsonPath('data.website', null)->assertJsonPath('data.map_url', null);
        $this->api('/api/v2/providers/'.$safe->id, $token)->assertOk()
            ->assertJsonPath('data.website', 'www.example.com')->assertJsonPath('data.map_url', 'https://maps.example.test/?q=1,2');

        $list = json_encode($this->api('/api/v2/providers?per_page=100', $token)->assertOk()->json());
        $this->assertStringNotContainsString('javascript:', (string) $list);
        $this->assertStringNotContainsString('data:text', (string) $list);
    }
}
