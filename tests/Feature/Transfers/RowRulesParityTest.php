<?php

namespace Tests\Feature\Transfers;

use App\Models\ProviderType;
use App\Models\User;
use App\Services\ProviderService;
use App\Services\Transfers\RowRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The spreadsheet import and the web form must accept and reject exactly the same URL values
 * (security review M1: the import previously accepted `javascript:` links).
 */
class RowRulesParityTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private static function corpus(): array
    {
        return [
            'https://example.com', 'http://example.com/a?b=1', 'HTTPS://EXAMPLE.COM', 'www.example.com',
            'example.com/path', 'example.com:8080/x', 'javascript:alert(1)', 'JaVaScRiPt:alert(1)',
            'data:text/html,<script>', 'vbscript:x', 'file:///etc/passwd', '//evil.test', 'https://ex ample.com',
            'https://example.com/"onmouseover=x', "java\tscript:alert(1)", 'mailto:a@b.test', 'example',
        ];
    }

    public function test_import_rejects_non_http_links_with_a_url_error_code(): void
    {
        foreach (['website', 'map_url'] as $field) {
            foreach (['javascript:alert(document.domain)', 'data:text/html,x', 'file:///etc/passwd'] as $value) {
                $errors = (new RowRules)->check([$field => $value]);
                $this->assertSame([['field' => $field, 'code' => 'validation_url', 'params' => []]], $errors, "$field=$value");
            }
        }
        $this->assertSame([], (new RowRules)->check(['website' => 'www.example.com', 'map_url' => 'https://maps.example.test/?q=1,2']));
    }

    public function test_import_and_form_accept_exactly_the_same_urls(): void
    {
        $admin = User::factory()->admin()->create();
        $type = ProviderType::factory()->create();
        $service = app(ProviderService::class);

        foreach (['website', 'map_url'] as $field) {
            foreach (self::corpus() as $value) {
                $importOk = (new RowRules)->check([$field => $value]) === [];
                try {
                    $service->create($admin, [
                        'provider_type_id' => $type->id,
                        'translations' => ['en' => ['name' => 'Parity', 'address' => '1 Road']],
                        $field => $value,
                    ]);
                    $formOk = true;
                } catch (ValidationException $e) {
                    $formOk = ! array_key_exists($field, $e->errors());
                }
                $this->assertSame($formOk, $importOk, "parity mismatch for $field=".json_encode($value));
            }
        }
    }
}
