<?php

namespace Tests\Feature\Transfers;

use App\Models\ImportJob;
use App\Models\Provider;
use App\Services\Transfers\ImportJobService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ZipArchive;

class ImportUploadTest extends TransfersTestCase
{
    private function validWorkbook(): string
    {
        return $this->workbook([$this->row(['provider_type' => 'laboratory', 'name' => 'Alpha Lab', 'address' => '1 Test St'])]);
    }

    private function addEntry(string $path, string $name, string $content): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $zip->addFromString($name, $content);
        $zip->close();
    }

    private function assertRejected(string $path, string $clientName, string $expectedFragment): void
    {
        $before = ImportJob::count();
        try {
            $this->startImport($this->editor(), $path, ImportJob::MODE_UPSERT, $clientName);
            $this->fail('The upload should have been rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('file', $e->errors());
            $this->assertStringContainsStringIgnoringCase($expectedFragment, $e->errors()['file'][0]);
        }
        $this->assertSame($before, ImportJob::count(), 'a rejected upload must not create a job');
        $this->assertSame([], Storage::disk('local')->allFiles('imports'), 'a rejected upload must not leave a stored file');
        $this->assertDatabaseHas('audit_events', ['action' => 'import.upload_rejected']);
    }

    public function test_a_zero_byte_file_is_rejected_without_a_job(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, '');

        $this->assertRejected($path, 'empty.xlsx', 'empty');
    }

    public function test_a_header_only_workbook_is_accepted_but_fails_validation_with_a_clear_file_error(): void
    {
        $job = $this->startImport($this->editor(), $this->workbook([]));

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame('no_data_rows', $job->error_summary['file_errors'][0]['code']);
        $this->assertSame(0, Provider::count());
    }

    #[DataProvider('wrongNames')]
    public function test_only_the_xlsx_extension_is_accepted(string $clientName): void
    {
        $this->assertRejected($this->validWorkbook(), $clientName, '.xlsx');
    }

    /** @return array<string, array{string}> */
    public static function wrongNames(): array
    {
        return [
            'xlsm macro workbook' => ['providers.xlsm'],
            'legacy xls' => ['providers.xls'],
            'csv' => ['providers.csv'],
            'exe' => ['providers.xlsx.exe'],
            'no extension' => ['providers'],
            'ods' => ['providers.ods'],
        ];
    }

    public function test_a_non_zip_file_with_an_xlsx_name_is_rejected(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, random_bytes(2048));

        $this->assertRejected($path, 'fake.xlsx', 'xlsx');
    }

    public function test_a_corrupted_xlsx_is_rejected(): void
    {
        $good = $this->validWorkbook();
        $path = $this->tempPath();
        file_put_contents($path, substr((string) file_get_contents($good), 0, 300)); // truncated archive

        $this->assertRejected($path, 'corrupt.xlsx', 'corrupt');
    }

    public function test_a_zip_signature_followed_by_garbage_is_rejected(): void
    {
        $path = $this->tempPath();
        file_put_contents($path, "PK\x03\x04".random_bytes(512));

        $this->assertRejected($path, 'garbage.xlsx', 'corrupt');
    }

    public function test_an_oversized_file_is_rejected_before_it_is_read(): void
    {
        config(['providers.imports.max_file_bytes' => 1000]);

        $this->assertRejected($this->validWorkbook(), 'big.xlsx', 'larger than');
    }

    public function test_a_zip_bomb_by_total_uncompressed_size_is_rejected(): void
    {
        config(['providers.imports.max_uncompressed_bytes' => 1024 * 1024]);
        $path = $this->validWorkbook();
        $this->addEntry($path, 'xl/worksheets/sheet9.xml', str_repeat('A', 3 * 1024 * 1024));

        $this->assertRejected($path, 'bomb.xlsx', 'unreasonable size');
    }

    public function test_a_zip_bomb_by_compression_ratio_is_rejected(): void
    {
        config(['providers.imports.max_uncompressed_bytes' => 500 * 1024 * 1024, 'providers.imports.max_zip_ratio' => 50]);
        $path = $this->validWorkbook();
        $this->addEntry($path, 'xl/worksheets/sheet9.xml', str_repeat('A', 6 * 1024 * 1024)); // compresses ~1000:1

        $this->assertRejected($path, 'ratio.xlsx', 'unreasonable size');
    }

    #[DataProvider('forbiddenParts')]
    public function test_workbooks_with_macros_external_links_or_embedded_objects_are_rejected(string $entry): void
    {
        $path = $this->validWorkbook();
        $this->addEntry($path, $entry, 'x');

        $this->assertRejected($path, 'macro.xlsx', 'macros, external links');
    }

    /** @return array<string, array{string}> */
    public static function forbiddenParts(): array
    {
        return [
            'vba project' => ['xl/vbaProject.bin'],
            'external link' => ['xl/externalLinks/externalLink1.xml'],
            'embedded object' => ['xl/embeddings/oleObject1.bin'],
            'activex' => ['xl/activeX/activeX1.xml'],
        ];
    }

    public function test_a_workbook_declaring_a_macro_enabled_content_type_is_rejected(): void
    {
        $path = $this->validWorkbook();
        $zip = new ZipArchive;
        $zip->open($path);
        $types = (string) $zip->getFromName('[Content_Types].xml');
        $zip->deleteName('[Content_Types].xml');
        $zip->addFromString('[Content_Types].xml', str_replace(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
            $types
        ));
        $zip->close();

        $this->assertRejected($path, 'macro.xlsx', 'macros');
    }

    public function test_the_original_is_stored_privately_under_a_random_server_name(): void
    {
        $path = $this->validWorkbook();
        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_DRY_RUN, '../../..\\evil<script>name.xlsx');

        $this->assertStringStartsWith('imports/', $job->stored_path);
        $this->assertDoesNotMatchRegularExpression('/evil|script|\.\./i', $job->stored_path);
        $this->assertMatchesRegularExpression('#^imports/\d{4}/\d{2}/[0-9a-f-]{36}\.xlsx$#', $job->stored_path);
        Storage::disk('local')->assertExists($job->stored_path);
        $this->assertSame(hash_file('sha256', $path), $job->file_sha256);
        $this->assertSame('evilscriptname.xlsx', $job->original_filename);
        $this->assertNotNull($job->expires_at);
    }

    public function test_an_editor_can_upload_through_the_http_endpoint_and_an_audit_event_is_written(): void
    {
        $editor = $this->editor();

        $response = $this->actingAs($editor)->post(route('imports.store'), [
            'mode' => 'dry_run',
            'file' => $this->upload($this->validWorkbook()),
        ]);

        $job = ImportJob::firstOrFail();
        $response->assertRedirect(route('imports.show', $job));
        $this->assertSame($editor->id, $job->user_id);
        $this->assertDatabaseHas('audit_events', ['action' => 'import.uploaded', 'actor_id' => $editor->id, 'target_id' => (string) $job->id]);
    }

    public function test_an_invalid_mode_is_rejected_by_the_http_endpoint(): void
    {
        $this->actingAs($this->editor())->post(route('imports.store'), [
            'mode' => 'replace_all',
            'file' => $this->upload($this->validWorkbook()),
        ])->assertSessionHasErrors('mode');

        $this->assertSame(0, ImportJob::count());
    }

    public function test_a_rejected_http_upload_reports_a_file_error_and_creates_nothing(): void
    {
        $this->actingAs($this->editor())->post(route('imports.store'), [
            'mode' => 'upsert',
            'file' => UploadedFile::fake()->createWithContent('providers.xlsm', 'x'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, ImportJob::count());
    }

    public function test_the_upload_service_itself_refuses_a_viewer(): void
    {
        $this->expectException(AuthorizationException::class);

        app(ImportJobService::class)->upload($this->viewer(), $this->upload($this->validWorkbook()), 'upsert');
    }
}
