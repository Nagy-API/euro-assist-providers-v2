<?php

namespace Tests\Feature\Legacy;

use App\Models\LegacyMigrationBatch;
use App\Models\User;
use Database\Seeders\LegacyTypeMappingSeeder;
use Database\Seeders\ProviderTypeSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Builds a SYNTHETIC legacy source: the `legacy_audit` connection is pointed at its own in-memory
 * SQLite database (it must never share tables with the V2 schema, whose `providers` table has the
 * same name as the legacy one) and filled with fake rows. No real data and no real connection are
 * ever used by these tests.
 */
abstract class LegacyTestCase extends TestCase
{
    use RefreshDatabase;

    public const SHA = '19a71fe301c1038b52473b76bec64d2a95ca606dd701c4a2054c72f5bb791814';

    protected int $seq = 0;

    protected string $reportsDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.legacy_audit' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false,
        ]]);
        DB::purge('legacy_audit');
        $this->createLegacySchema();

        // Reports must never be written into the repository by tests.
        $this->reportsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legacy-reports-test-'.bin2hex(random_bytes(4));
        config(['legacy.reports_path' => $this->reportsDir]);

        $this->seed(ProviderTypeSeeder::class);
        $this->seed(LegacyTypeMappingSeeder::class);
    }

    protected function tearDown(): void
    {
        if ($this->reportsDir !== '' && is_dir($this->reportsDir)) {
            foreach (glob($this->reportsDir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($this->reportsDir);
        }
        parent::tearDown();
    }

    protected function legacy(): Connection
    {
        /** @var Connection */
        return DB::connection('legacy_audit');
    }

    private function createLegacySchema(): void
    {
        $schema = Schema::connection('legacy_audit');
        foreach (['providers', 'arabic_providers'] as $table) {
            $schema->create($table, function (Blueprint $t) use ($table) {
                $t->unsignedBigInteger('id')->primary();
                $t->string('name');
                $t->string('type')->nullable();
                $t->string('address')->nullable();
                $t->string('city')->nullable();
                $t->string('district')->nullable();
                $t->string('discount_pct')->nullable();
                $t->text('hours')->nullable();
                $t->string('phone')->nullable();
                $t->string('email')->nullable();
                $t->string('website')->nullable();
                $t->text('map_url')->nullable();
                $t->string('logo_path')->nullable();
                $t->string('specialization')->nullable();
                $t->string('package')->nullable();
                if ($table === 'providers') {
                    $t->boolean('is_translated')->nullable();
                }
                $t->timestamp('created_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }
        $schema->create('top_providers', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('name_english');
            $t->string('name_arabic');
            $t->string('logo_path')->nullable();
            $t->string('type_en')->nullable();
            $t->string('type_ar')->nullable();
            $t->timestamps();
        });
        $schema->create('types', function (Blueprint $t) {
            $t->unsignedBigInteger('id')->primary();
            $t->string('type_key');
            $t->string('name_en');
            $t->string('name_ar');
            $t->timestamps();
        });
    }

    /** @param array<string, mixed> $attrs */
    protected function en(array $attrs = []): int
    {
        return $this->insertSource('providers', $attrs + [
            'name' => 'Synthetic EN Provider '.($this->seq + 1), 'type' => 'Laboratory', 'address' => 'EN Street '.($this->seq + 1),
            'city' => 'Cairo', 'district' => 'Nasr City', 'phone' => '0100000'.str_pad((string) ($this->seq + 1), 4, '0', STR_PAD_LEFT),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    protected function ar(array $attrs = []): int
    {
        return $this->insertSource('arabic_providers', $attrs + [
            'name' => 'مزود تجريبي '.($this->seq + 1), 'type' => 'معمل تحاليل', 'address' => 'شارع '.($this->seq + 1),
            'city' => 'القاهرة', 'district' => 'مدينة نصر', 'phone' => '0100000'.str_pad((string) ($this->seq + 1), 4, '0', STR_PAD_LEFT),
        ]);
    }

    /** @param array<string, mixed> $attrs */
    protected function top(array $attrs = []): int
    {
        return $this->insertSource('top_providers', $attrs + [
            'name_english' => 'Synthetic Brand '.($this->seq + 1), 'name_arabic' => 'علامة تجريبية '.($this->seq + 1),
            'type_en' => 'laboratory', 'type_ar' => 'laboratory', 'logo_path' => 'brand-logo-'.($this->seq + 1).'.png',
        ]);
    }

    /** @param array<string, mixed> $attrs */
    private function insertSource(string $table, array $attrs): int
    {
        $this->seq++;
        $id = $attrs['id'] ?? $this->seq;
        unset($attrs['id']);
        $this->legacy()->table($table)->insert(['id' => $id] + $attrs);

        return (int) $id;
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function stage(): void
    {
        $this->artisan('legacy:stage', ['--snapshot-sha256' => self::SHA])->assertSuccessful();
    }

    /** Stage + apply as $actor; returns the batch. */
    protected function runPipeline(?User $actor = null): LegacyMigrationBatch
    {
        $actor ??= $this->admin();
        $this->stage();
        $this->artisan('legacy:apply', ['--actor' => $actor->email])->assertSuccessful();

        return LegacyMigrationBatch::query()->latest('id')->firstOrFail();
    }

    /** @return array<string, int> */
    protected function targetCounts(): array
    {
        return [
            'providers' => DB::table('providers')->count(),
            'provider_translations' => DB::table('provider_translations')->count(),
            'featured_providers' => DB::table('featured_providers')->count(),
            'legacy_id_maps' => DB::table('legacy_id_maps')->count(),
            'legacy_pair_suggestions' => DB::table('legacy_pair_suggestions')->count(),
        ];
    }

    /** Whole legacy source as a comparable structure (for the "source was never modified" checks). */
    protected function sourceSnapshot(): string
    {
        $out = [];
        foreach (['providers', 'arabic_providers', 'top_providers', 'types'] as $table) {
            $out[$table] = $this->legacy()->table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return hash('sha256', json_encode($out, JSON_UNESCAPED_UNICODE));
    }
}
