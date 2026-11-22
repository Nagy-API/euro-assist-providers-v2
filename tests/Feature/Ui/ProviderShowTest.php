<?php

namespace Tests\Feature\Ui;

use App\Livewire\Providers\Show;
use App\Services\AuditLogger;
use App\Services\Ui\SafeUrl;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class ProviderShowTest extends UiTestCase
{
    public function test_shows_both_translations_side_by_side(): void
    {
        $provider = $this->provider(en: ['name' => 'Alpha Clinic', 'specialization' => 'Cardiology'], ar: ['name' => 'عيادة ألفا', 'specialization' => 'القلب']);

        Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider])
            ->assertSee('Alpha Clinic')->assertSee('عيادة ألفا')->assertSee('Cardiology')->assertSee('القلب');
    }

    public function test_a_missing_language_is_stated_not_invented(): void
    {
        $provider = $this->englishOnlyProvider('Only English Lab');

        Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider])
            ->assertSee('Only English Lab')->assertSee(__('ui.translation.no_translation'));
    }

    public function test_unset_values_use_a_dash_placeholder(): void
    {
        $provider = $this->provider(['phone' => null, 'website' => null, 'map_url' => null]);

        Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider])->assertSee('—');
    }

    public function test_map_and_website_links_are_safe(): void
    {
        $provider = $this->provider(['map_url' => 'https://maps.example.test/?q=1&x=2', 'website' => 'www.example.test']);

        $html = Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider])->html();

        $this->assertStringContainsString('href="https://maps.example.test/?q=1&amp;x=2"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('href="https://www.example.test"', $html, 'a bare host is linked as https');
    }

    public function test_dangerous_schemes_are_never_rendered_as_links(): void
    {
        $provider = $this->provider(['map_url' => 'javascript:alert(document.cookie)', 'website' => 'javascript:alert(1)']);

        $c = Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider]);

        $c->assertDontSeeHtml('href="javascript:')
            ->assertSee(__('ui.providers.map_not_linked'));
        $this->assertStringNotContainsString('href="javascript', $c->html());
    }

    /** @return array<string, array{0: string|null, 1: string|null}> */
    public static function urls(): array
    {
        return [
            'https' => ['https://example.test/a?b=1', 'https://example.test/a?b=1'],
            'http' => ['http://example.test', 'http://example.test'],
            'uppercase scheme' => ['HTTPS://example.test', 'HTTPS://example.test'],
            'javascript' => ['javascript:alert(1)', null],
            'data' => ['data:text/html;base64,AAAA', null],
            'vbscript' => ['vbscript:msgbox(1)', null],
            'file' => ['file:///etc/passwd', null],
            'protocol relative' => ['//evil.test/x', null],
            'relative' => ['/etc/passwd', null],
            'whitespace' => ['https://exa mple.test', null],
            'quote injection' => ['https://example.test/"onmouseover="x', null],
            'newline' => ["https://example.test/\njavascript:x", null],
            'no host' => ['https://', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('urls')]
    public function test_safe_url_only_allows_http_and_https(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, SafeUrl::http($input));
    }

    public function test_audit_snippet_is_for_admins_only(): void
    {
        $provider = $this->provider();
        $admin = $this->admin();
        app(AuditLogger::class)->record('provider.updated', $provider, 'success', [], $admin);

        Livewire::actingAs($admin)->test(Show::class, ['provider' => $provider])->assertSee('provider.updated');
        Livewire::actingAs($this->editor())->test(Show::class, ['provider' => $provider])->assertDontSee('provider.updated')->assertDontSee(__('ui.providers.audit_snippet'));
        Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider])->assertDontSee('provider.updated');
    }

    public function test_deleted_provider_is_404_for_non_admins_and_visible_to_admins(): void
    {
        $provider = $this->provider();
        $provider->delete();

        Livewire::actingAs($this->editor())->test(Show::class, ['provider' => $provider])->assertNotFound();
        Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider])->assertNotFound();
        Livewire::actingAs($this->admin())->test(Show::class, ['provider' => $provider])->assertOk()->assertSee(__('ui.status.deleted'));
    }

    public function test_only_admin_can_delete_and_restore_from_the_details_page(): void
    {
        $provider = $this->provider();

        foreach ([$this->editor(), $this->viewer()] as $user) {
            Livewire::actingAs($user)->test(Show::class, ['provider' => $provider])->call('confirmDelete')->assertForbidden();
            Livewire::actingAs($user)->test(Show::class, ['provider' => $provider])->call('performDelete')->assertForbidden();
        }
        $this->assertNull($provider->fresh()->deleted_at);

        $c = Livewire::actingAs($this->admin())->test(Show::class, ['provider' => $provider])
            ->call('confirmDelete')->assertSet('dialog', 'delete')->call('performDelete');
        $this->assertNotNull($provider->fresh()->deleted_at);

        $c->call('confirmRestore')->call('performRestore');
        $this->assertNull($provider->fresh()->deleted_at);
    }

    public function test_edit_button_only_for_those_who_can_update(): void
    {
        $provider = $this->provider();

        Livewire::actingAs($this->viewer())->test(Show::class, ['provider' => $provider])->assertDontSee(route('providers.edit', $provider));
        Livewire::actingAs($this->editor())->test(Show::class, ['provider' => $provider])->assertSee(route('providers.edit', $provider));
    }
}
