<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * docs/openapi/v2.yaml is a contract: it must parse, be internally consistent (every $ref
 * resolves) and describe exactly the GET routes registered under /api/v2.
 */
class OpenApiContractTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private static ?array $spec = null;

    /** @return array<string, mixed> */
    private function spec(): array
    {
        return self::$spec ??= MiniYaml::parse((string) file_get_contents(base_path('docs/openapi/v2.yaml')));
    }

    public function test_the_document_is_openapi_3_1_with_a_bearer_security_scheme(): void
    {
        $spec = $this->spec();

        $this->assertStringStartsWith('3.1', $spec['openapi']);
        $this->assertNotEmpty($spec['info']['title']);
        $this->assertSame('/api/v2', $spec['servers'][0]['url']);
        $this->assertSame(['http', 'bearer'], [$spec['components']['securitySchemes']['bearerAuth']['type'], $spec['components']['securitySchemes']['bearerAuth']['scheme']]);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
        $this->assertArrayHasKey('ErrorResponse', $spec['components']['schemas']);
        $this->assertSame(
            ['validation_failed', 'unauthenticated', 'forbidden', 'not_found', 'rate_limited', 'server_error'],
            array_values(array_intersect(
                ['validation_failed', 'unauthenticated', 'forbidden', 'not_found', 'rate_limited', 'server_error'],
                $spec['components']['schemas']['ErrorResponse']['properties']['error']['properties']['code']['enum'],
            )),
        );
    }

    public function test_every_documented_path_exists_as_a_route_and_vice_versa(): void
    {
        $documented = [];
        foreach ($this->spec()['paths'] as $path => $operations) {
            $this->assertSame(['get'], array_keys($operations), "$path must document GET only (the API is read-only)");
            $documented[] = 'GET api/v2'.preg_replace('/\{[^}]+\}/', '{}', $path);
        }

        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v2')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $routes[] = $method.' '.preg_replace('/\{[^}]+\}/', '{}', $route->uri());
                }
            }
        }

        sort($documented);
        sort($routes);
        $this->assertSame($routes, $documented, 'docs/openapi/v2.yaml and routes/api.php disagree');
    }

    /** @return array<string, array{0: string}> */
    public static function operations(): array
    {
        $paths = MiniYaml::parse((string) file_get_contents(dirname(__DIR__, 3).'/docs/openapi/v2.yaml'))['paths'];

        return array_combine(array_keys($paths), array_map(fn ($p) => [$p], array_keys($paths)));
    }

    #[DataProvider('operations')]
    public function test_every_operation_documents_the_standard_errors_and_rate_limit_headers(string $path): void
    {
        $op = $this->spec()['paths'][$path]['get'];

        $this->assertNotEmpty($op['operationId']);
        foreach (['200', '401', '403', '429', '500'] as $status) {
            $this->assertArrayHasKey($status, $op['responses'], "$path lacks the $status response");
        }
        $this->assertArrayHasKey('X-RateLimit-Limit', $op['responses']['200']['headers']);
        $this->assertSame('#/components/responses/RateLimited', $op['responses']['429']['$ref']);
        $this->assertArrayHasKey('Retry-After', $this->spec()['components']['responses']['RateLimited']['headers']);
    }

    public function test_every_ref_resolves(): void
    {
        $spec = $this->spec();
        $refs = [];
        $walk = function ($node) use (&$walk, &$refs) {
            if (is_array($node)) {
                foreach ($node as $key => $value) {
                    if ($key === '$ref') {
                        $refs[] = $value;
                    }
                    $walk($value);
                }
            }
        };
        $walk($spec);

        $this->assertNotEmpty($refs);
        foreach (array_unique($refs) as $ref) {
            $this->assertStringStartsWith('#/', $ref);
            $node = $spec;
            foreach (explode('/', substr($ref, 2)) as $segment) {
                $this->assertIsArray($node);
                $this->assertArrayHasKey($segment, $node, "Unresolved \$ref {$ref}");
                $node = $node[$segment];
            }
        }
    }

    public function test_documented_resource_schemas_list_exactly_the_keys_the_api_returns(): void
    {
        $schemas = $this->spec()['components']['schemas'];

        foreach (['providers_index_en' => ['Provider', null], 'provider_show' => ['Provider', 'ProviderDetail'], 'providers_coordinates' => ['ProviderCoordinate', null],
            'provider_types' => ['ProviderType', null], 'featured_providers' => ['FeaturedProvider', null], 'providers_distribution' => ['DistributionItem', null]] as $snapshot => [$schema]) {
            $json = json_decode((string) file_get_contents(base_path("tests/fixtures/api/{$snapshot}.json")), true);
            $item = array_is_list($json['data']) ? $json['data'][0] : $json['data'];
            $documented = array_keys($schemas[$schema]['properties']);
            $actual = array_keys($item);
            if ($snapshot === 'provider_show') {
                $actual = array_values(array_diff($actual, ['translations']));
            }

            $this->assertEqualsCanonicalizing($documented, $actual, "Schema {$schema} and snapshot {$snapshot} disagree");
            $this->assertEqualsCanonicalizing($schemas[$schema]['required'], $actual, "Schema {$schema} required list is stale");
        }
    }

    public function test_the_mini_parser_rejects_malformed_documents(): void
    {
        $this->expectException(RuntimeException::class);
        MiniYaml::parse("a: 1\na: 2\n");
    }
}
