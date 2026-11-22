<?php

namespace Tests\Feature\Transfers;

use App\Models\ImportJob;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\ProviderType;
use App\Models\User;
use App\Services\Transfers\ImportJobService;
use App\Services\Transfers\TemplateSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

/**
 * Shared helpers for the import/export tests. Workbooks are generated with OpenSpout in test code
 * (synthetic data only, no binary fixtures); storage is faked, the queue is sync, the cache is array.
 */
abstract class TransfersTestCase extends TestCase
{
    use RefreshDatabase;

    protected ProviderType $type;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->type = ProviderType::factory()->create(['key' => 'laboratory']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

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

    protected function tempPath(string $extension = 'xlsx'): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'transfers-test-'.bin2hex(random_bytes(6)).'.'.$extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * A full template row; unspecified columns are null (blank). schema_version defaults to 1.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function row(array $values): array
    {
        return $values + ['schema_version' => 1, 'locale' => 'en'];
    }

    /**
     * Build an .xlsx. $rows are assoc arrays keyed by column name; the header is $columns
     * (default: the full v1 template). Strings are written EXACTLY as given (explicit text cells).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>|null  $columns
     */
    protected function workbook(array $rows, ?array $columns = null): string
    {
        $columns ??= TemplateSchema::COLUMNS;
        $path = $this->tempPath();

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->addRow(new Row(array_map(fn (string $c) => new StringCell($c), $columns)));
        foreach ($rows as $row) {
            $writer->addRow(new Row(array_map(fn (string $c) => $this->cell($row[$c] ?? null), $columns)));
        }
        $writer->close();

        return $path;
    }

    private function cell(mixed $value): Cell
    {
        return match (true) {
            $value instanceof Cell => $value,
            $value === null => new EmptyCell(null),
            is_int($value) || is_float($value) => new NumericCell($value),
            default => new StringCell((string) $value),
        };
    }

    protected function upload(string $path, string $name = 'providers.xlsx'): UploadedFile
    {
        return new UploadedFile($path, $name, null, null, true);
    }

    /** Upload through the real service (validation runs synchronously). */
    protected function startImport(User $user, string $path, string $mode = ImportJob::MODE_UPSERT, string $name = 'providers.xlsx'): ImportJob
    {
        return app(ImportJobService::class)->upload($user, $this->upload($path, $name), $mode);
    }

    /** Upload, assert it reached `ready`, confirm (the apply runs synchronously) and return the refreshed job. */
    protected function importAndApply(User $user, string $path, string $mode = ImportJob::MODE_UPSERT): ImportJob
    {
        $job = $this->startImport($user, $path, $mode);
        $this->assertSame(ImportJob::STATUS_READY, $job->status, 'expected a ready preview: '.json_encode($job->error_summary));

        return app(ImportJobService::class)->confirm($user, $job);
    }

    protected function provider(array $translations = [], array $attributes = []): Provider
    {
        $provider = Provider::factory()->create(['provider_type_id' => $this->type->id] + $attributes);
        foreach ($translations as $locale => $fields) {
            $provider->translations()->create(['locale' => $locale] + $fields);
        }

        return $provider->load('translations');
    }

    /** @return array<string, int> */
    protected function canonicalCounts(): array
    {
        return [
            'providers' => Provider::withTrashed()->count(),
            'translations' => ProviderTranslation::query()->count(),
            'revision_sum' => (int) Provider::withTrashed()->sum('revision'),
        ];
    }

    /**
     * Read the first sheet of an xlsx back as a list of rows of plain values (header = row 0).
     *
     * @return list<list<mixed>>
     */
    protected function readWorkbook(string $path): array
    {
        $reader = new Reader(new Options);
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    /** Read a stored private file's rows as assoc arrays keyed by the header row. */
    protected function readStored(string $disk, string $relative): array
    {
        $rows = $this->readWorkbook(Storage::disk($disk)->path($relative));
        $header = array_shift($rows);

        return array_map(fn (array $r) => array_combine($header, array_pad($r, count($header), null)), $rows);
    }
}
