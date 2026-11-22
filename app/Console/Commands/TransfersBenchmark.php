<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\ExportJob;
use App\Models\ImportJob;
use App\Models\Provider;
use App\Models\ProviderType;
use App\Models\User;
use App\Services\Transfers\ExportService;
use App\Services\Transfers\ImportFileInspector;
use App\Services\Transfers\ImportJobService;
use App\Services\Transfers\ImportValidator;
use App\Services\Transfers\PrivateFiles;
use App\Services\Transfers\TemplateSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Benchmark of the import/export pipeline against the CURRENT, NON-PRODUCTION database.
 *
 * Generates a synthetic .xlsx with N rows, then runs (synchronously, in this process) the zip
 * inspection, whole-file validation + staging, apply, export, and a dry-run round trip of the exported
 * file, printing rows/s, seconds and PEAK memory per phase (memory_reset_peak_usage between phases).
 * Everything runs inside one outer transaction that is ROLLED BACK, and the temporary files live in a
 * throw-away directory that is deleted, so no synthetic data remains.
 *
 * Refuses to run in production. For a fully isolated run use a throw-away database, e.g.
 *   DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array php artisan transfers:benchmark 20000
 * (an in-memory SQLite database is migrated automatically).
 */
class TransfersBenchmark extends Command
{
    protected $signature = 'transfers:benchmark {rows=20000 : number of synthetic data rows}';

    protected $description = 'Benchmark import validation/apply and export on synthetic data (non-production only).';

    /** @var list<array{phase: string, rows: int, seconds: float, peak_mb: float}> */
    private array $results = [];

    public function handle(ImportFileInspector $inspector, ImportJobService $jobs, ImportValidator $validator, ExportService $exports): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Refusing to run the benchmark in production.');

            return self::FAILURE;
        }
        $rows = (int) $this->argument('rows');
        if ($rows < 1 || $rows > 200000) {
            $this->error('rows must be between 1 and 200000.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        if ($connection->getDriverName() === 'sqlite' && ! Schema::hasTable('providers')) {
            Artisan::call('migrate', ['--force' => true]);
        }

        // Fully synchronous, isolated private storage, no queue rows.
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transfers-benchmark-'.Str::random(8);
        File::ensureDirectoryExists($root);
        config([
            'queue.default' => 'sync',
            'filesystems.disks.benchmark' => ['driver' => 'local', 'root' => $root, 'throw' => true],
            'providers.imports.disk' => 'benchmark',
            'providers.exports.disk' => 'benchmark',
            'providers.imports.max_rows' => max($rows, (int) config('providers.imports.max_rows')),
            'providers.imports.max_file_bytes' => 512 * 1024 * 1024,
            'providers.imports.max_uncompressed_bytes' => 2 * 1024 * 1024 * 1024,
            'providers.exports.max_rows' => max($rows, (int) config('providers.exports.max_rows')),
        ]);

        $this->line(sprintf('PHP %s | %s | memory_limit=%s | DB=%s | rows=%d', PHP_VERSION, php_uname('s').' '.php_uname('r'), ini_get('memory_limit'), $connection->getDriverName(), $rows));

        $connection->beginTransaction();
        try {
            $this->runBenchmark($rows, $inspector, $jobs, $validator, $exports);
        } finally {
            $connection->rollBack();
            File::deleteDirectory($root);
        }

        $this->table(['phase', 'rows', 'seconds', 'rows/s', 'peak memory (MB)'], array_map(fn (array $r) => [
            $r['phase'], $r['rows'], number_format($r['seconds'], 2), $r['seconds'] > 0 ? number_format($r['rows'] / $r['seconds']) : 'n/a', number_format($r['peak_mb'], 1),
        ], $this->results));
        $this->line('Synthetic data rolled back and temporary files deleted. Overall peak memory: '.number_format(memory_get_peak_usage(true) / 1048576, 1).' MB');

        return self::SUCCESS;
    }

    private function runBenchmark(int $rows, ImportFileInspector $inspector, ImportJobService $jobs, ImportValidator $validator, ExportService $exports): void
    {
        $disk = 'benchmark';

        $admin = User::query()->forceCreate([
            'name' => 'Benchmark Admin', 'email' => 'benchmark-'.Str::random(8).'@example.test',
            'password' => Str::random(40), 'role' => Role::Admin, 'is_active' => true,
        ]);
        $type = ProviderType::query()->create(['key' => 'bench_'.Str::lower(Str::random(6)), 'is_active' => true, 'sort_order' => 0]);
        $type->translations()->create(['locale' => 'en', 'name' => 'Benchmark type']);

        // ---- generate the workbook (streaming) ----
        $relative = PrivateFiles::newPath('imports');
        $path = PrivateFiles::absolute($disk, $relative, ensureDirectory: true);
        $this->measure('generate workbook', $rows, fn () => $this->writeWorkbook($path, $rows, $type->key));
        $this->line('   workbook size: '.number_format(filesize($path) / 1048576, 2).' MB');

        // ---- upload safety checks (zip central directory only) ----
        $this->measure('inspect (zip guard)', $rows, fn () => $inspector->inspect($path));

        // ---- validation + staging ----
        $job = ImportJob::query()->create([
            'user_id' => $admin->id, 'mode' => ImportJob::MODE_CREATE_ONLY, 'status' => ImportJob::STATUS_VALIDATING,
            'original_filename' => 'benchmark.xlsx', 'stored_path' => $relative, 'file_sha256' => hash_file('sha256', $path),
            'file_size' => filesize($path), 'chunk_size' => (int) config('providers.imports.chunk_size'),
            'expires_at' => now()->addHour(),
        ]);
        $this->measure('validate + stage', $rows, fn () => $validator->run($job));
        $job->refresh();
        $this->line(sprintf('   status=%s total=%d inserts=%d updates=%d unchanged=%d errors=%d', $job->status, $job->total_rows, $job->insert_count, $job->update_count, $job->unchanged_count, $job->error_count));
        if ($job->status !== ImportJob::STATUS_READY) {
            $this->error('Validation did not reach ready; aborting the benchmark. '.json_encode(['reason' => $job->failure_reason, 'codes' => ($job->error_summary['error_codes'] ?? []), 'file' => ($job->error_summary['file_errors'] ?? [])]));

            return;
        }

        // ---- confirm -> apply (synchronous queue) ----
        $this->measure('confirm + apply', $rows, fn () => $jobs->confirm($admin, $job));
        $job->refresh();
        $this->line(sprintf('   status=%s applied=%d providers=%d', $job->status, $job->applied_count, Provider::query()->where('provider_type_id', $type->id)->count()));

        // ---- export (filtered by the benchmark type, English rows) ----
        $export = null;
        $this->measure('export (en)', $rows, function () use ($exports, $admin, $type, &$export): void {
            $export = $exports->dispatch($admin, ExportJob::SCOPE_FILTERED, 'en', ['type' => $type->id]);
        });
        /** @var ExportJob $export */
        $this->line(sprintf('   status=%s matching=%d written=%d skipped(no en)=%d', $export->status, $export->total_rows, $export->written_rows, $export->total_rows - $export->written_rows));

        // ---- round trip: dry-run import of the exported file must show zero changes ----
        if ($export->status === ExportJob::STATUS_COMPLETED && $export->file_path) {
            $copy = PrivateFiles::newPath('imports');
            Storage::disk($disk)->copy($export->file_path, $copy);
            $dry = ImportJob::query()->create([
                'user_id' => $admin->id, 'mode' => ImportJob::MODE_DRY_RUN, 'status' => ImportJob::STATUS_VALIDATING,
                'original_filename' => 'roundtrip.xlsx', 'stored_path' => $copy, 'file_sha256' => hash_file('sha256', PrivateFiles::absolute($disk, $copy)),
                'file_size' => (int) Storage::disk($disk)->size($copy), 'chunk_size' => 200, 'expires_at' => now()->addHour(),
            ]);
            $this->measure('round trip dry-run (validate exported file)', (int) $export->written_rows, fn () => $validator->run($dry));
            $dry->refresh();
            $this->line(sprintf('   status=%s total=%d inserts=%d updates=%d unchanged=%d errors=%d (expected: all unchanged)', $dry->status, $dry->total_rows, $dry->insert_count, $dry->update_count, $dry->unchanged_count, $dry->error_count));
        }
    }

    private function measure(string $phase, int $rows, callable $work): void
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $t = hrtime(true);
        $work();
        $seconds = (hrtime(true) - $t) / 1e9;
        $this->results[] = ['phase' => $phase, 'rows' => $rows, 'seconds' => $seconds, 'peak_mb' => memory_get_peak_usage(true) / 1048576];
    }

    private function writeWorkbook(string $path, int $rows, string $typeKey): void
    {
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(new Row(array_map(fn (string $h) => new StringCell($h), TemplateSchema::COLUMNS)));

        for ($i = 1; $i <= $rows; $i++) {
            $ar = $i % 2 === 0;
            $cells = [
                '1', '', $ar ? 'ar' : 'en', $typeKey,
                '0100'.str_pad((string) $i, 7, '0', STR_PAD_LEFT).' / 0225'.str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                $i % 3 === 0 ? "bench{$i}@example.test" : '', $i % 5 === 0 ? "https://example.test/p/{$i}" : '',
                '', $i % 4 === 0 ? '30.0444000' : '', $i % 4 === 0 ? '31.2357000' : '', '1',
                $ar ? "عيادة اختبار رقم {$i}" : "Benchmark Clinic {$i}",
                $ar ? "شارع الاختبار {$i}، مبنى ".($i % 90) : "{$i} Test Street, Block ".($i % 90),
                $ar ? 'القاهرة' : 'Cairo', $ar ? 'مدينة نصر' : 'Nasr City',
                'Specialization text '.str_repeat('lorem ipsum ', 6), "9am - 5pm\nSat-Thu",
                $ar ? 'خصم ١٠٪ إلى ٢٠٪' : '10% - 20% discount', 'Package '.($i % 7),
            ];
            $writer->addRow(new Row(array_map(fn (string $v) => new StringCell($v), $cells)));
        }
        $writer->close();
    }
}
