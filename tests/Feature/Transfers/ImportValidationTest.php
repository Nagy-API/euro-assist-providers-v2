<?php

namespace Tests\Feature\Transfers;

use App\Jobs\ApplyImportJob;
use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Services\Transfers\ErrorReportWriter;
use App\Services\Transfers\ImportApplier;
use App\Services\Transfers\ImportNotApplicable;
use App\Services\Transfers\TemplateSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ImportValidationTest extends TransfersTestCase
{
    private function newRow(array $overrides = []): array
    {
        return $this->row($overrides + ['provider_type' => 'laboratory', 'name' => 'Alpha Lab', 'address' => '1 Test St']);
    }

    private function errorCodes(ImportJob $job): array
    {
        return array_keys((array) ($job->error_summary['error_codes'] ?? []));
    }

    // ------------------------------------------------------------------ header handling

    public function test_columns_are_matched_by_name_not_position_and_case_and_whitespace_are_ignored(): void
    {
        $columns = ['  ADDRESS ', 'Name', 'provider_type', 'LOCALE', 'schema_version'];
        $path = $this->workbook([['  ADDRESS ' => '5 Reordered Rd', 'Name' => 'Reordered Lab', 'provider_type' => 'laboratory', 'LOCALE' => 'en', 'schema_version' => 1]], $columns);

        $job = $this->importAndApply($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $this->assertDatabaseHas('provider_translations', ['locale' => 'en', 'name' => 'Reordered Lab', 'address' => '5 Reordered Rd']);
    }

    public function test_a_missing_required_column_rejects_the_whole_file_with_a_clear_error(): void
    {
        $columns = ['schema_version', 'locale', 'provider_type', 'name']; // no address
        $path = $this->workbook([['schema_version' => 1, 'locale' => 'en', 'provider_type' => 'laboratory', 'name' => 'X']], $columns);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame('missing_columns', $job->error_summary['file_errors'][0]['code']);
        $this->assertStringContainsString('address', $job->error_summary['file_errors'][0]['params']['columns']);
        $this->assertSame(0, ImportRow::count());
        $this->assertSame(0, Provider::count());
    }

    public function test_update_only_requires_the_provider_id_column_and_locale_is_always_required(): void
    {
        $noId = $this->workbook([['schema_version' => 1, 'locale' => 'en', 'name' => 'X']], ['schema_version', 'locale', 'name']);
        $job = $this->startImport($this->editor(), $noId, ImportJob::MODE_UPDATE_ONLY);
        $this->assertSame('missing_columns', $job->error_summary['file_errors'][0]['code']);
        $this->assertStringContainsString('provider_id', $job->error_summary['file_errors'][0]['params']['columns']);

        $noLocale = $this->workbook([['schema_version' => 1, 'name' => 'X']], ['schema_version', 'name']);
        $job = $this->startImport($this->editor(), $noLocale, ImportJob::MODE_UPSERT);
        $this->assertSame('missing_columns', $job->error_summary['file_errors'][0]['code']);
        $this->assertStringContainsString('locale', $job->error_summary['file_errors'][0]['params']['columns']);
    }

    public function test_duplicate_header_names_reject_the_file(): void
    {
        $columns = ['schema_version', 'locale', 'provider_type', 'name', 'Name', 'address'];
        $path = $this->workbook([['schema_version' => 1, 'locale' => 'en']], $columns);
        // workbook() looks values up by header text, so the duplicate headers are present in the file itself.

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPSERT);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame('duplicate_columns', $job->error_summary['file_errors'][0]['code']);
        $this->assertSame(0, ImportRow::count());
    }

    public function test_unknown_columns_are_ignored_with_a_warning(): void
    {
        $columns = [...TemplateSchema::COLUMNS, 'internal_notes'];
        $path = $this->workbook([$this->newRow(['internal_notes' => 'secret'])], $columns);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_READY, $job->status);
        $this->assertSame(1, $job->error_summary['warnings']['unknown_column']['count']);
        $this->assertContains('internal_notes', $job->error_summary['unknown_columns']);
    }

    public function test_a_header_with_an_unsupported_schema_version_is_a_row_error(): void
    {
        $path = $this->workbook([$this->newRow(['schema_version' => 2])]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertContains('unsupported_schema_version', $this->errorCodes($job));
    }

    // ------------------------------------------------------------------ values

    public function test_arabic_and_english_rows_map_to_the_right_translation_records(): void
    {
        $editor = $this->editor();
        $en = $this->importAndApply($editor, $this->workbook([$this->newRow([
            'name' => 'Nile Clinic', 'address' => '12 Corniche Rd', 'city' => 'Cairo', 'district' => 'Maadi',
        ])]), ImportJob::MODE_CREATE_ONLY);
        $this->assertSame(ImportJob::STATUS_COMPLETED, $en->status);
        $provider = Provider::firstOrFail();

        $ar = $this->importAndApply($editor, $this->workbook([$this->row([
            'provider_id' => $provider->id, 'locale' => 'ar', 'name' => 'عيادة النيل', 'address' => '١٢ شارع الكورنيش',
            'city' => 'القاهرة', 'district' => 'المعادي', 'specialization' => 'باطنة - أطفال',
        ])]), ImportJob::MODE_UPDATE_ONLY);
        $this->assertSame(ImportJob::STATUS_COMPLETED, $ar->status);

        $provider->load('translations');
        $this->assertSame('Nile Clinic', $provider->translation('en')->name);
        $this->assertSame('عيادة النيل', $provider->translation('ar')->name);
        $this->assertSame('١٢ شارع الكورنيش', $provider->translation('ar')->address);
        $this->assertSame('Maadi', $provider->translation('en')->district);
        $this->assertSame('المعادي', $provider->translation('ar')->district);
        $this->assertSame(2, $provider->translations->count());
    }

    public function test_phone_hours_and_discount_are_kept_as_exact_text(): void
    {
        $path = $this->workbook([$this->newRow([
            'phone' => '0100 000 1234 / 02-2555-0000',
            'hours' => "Sat-Thu 9:00 - 17:00\nFri closed",
            'discount_text' => '10% - 20% (up to 30%)',
            'package_text' => 'Pkg "A" & B',
        ])]);

        $this->importAndApply($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $provider = Provider::firstOrFail();
        $this->assertSame('0100 000 1234 / 02-2555-0000', $provider->phone);
        $t = $provider->translation('en') ?? $provider->load('translations')->translation('en');
        $this->assertSame("Sat-Thu 9:00 - 17:00\nFri closed", $t->hours);
        $this->assertSame('10% - 20% (up to 30%)', $t->discount_text);
        $this->assertSame('Pkg "A" & B', $t->package_text);
    }

    public function test_a_leading_zero_phone_stored_as_text_survives_and_a_numeric_phone_cell_only_warns(): void
    {
        $path = $this->workbook([
            $this->newRow(['phone' => '01001234567', 'name' => 'Text Phone', 'address' => 'A1']),
            $this->newRow(['phone' => new NumericCell(1001234567), 'name' => 'Numeric Phone', 'address' => 'A2']),
        ]);

        $job = $this->importAndApply($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $this->assertDatabaseHas('providers', ['phone' => '01001234567']);
        $this->assertDatabaseHas('providers', ['phone' => '1001234567']); // kept exactly as the cell held it: never "repaired"
        $this->assertSame(1, $job->error_summary['warnings']['phone_numeric_cell']['count']);
        $this->assertSame([3], $job->error_summary['warnings']['phone_numeric_cell']['rows']);
    }

    public function test_cells_with_formulas_or_a_leading_equals_sign_are_rejected(): void
    {
        $path = $this->workbook([
            $this->newRow(['name' => new FormulaCell('=1+1'), 'address' => 'A1']),
            $this->newRow(['name' => '=HYPERLINK("http://x")', 'address' => 'A2']),
        ]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame(2, $job->error_summary['error_codes']['formula_not_allowed']);
        $this->assertSame(0, Provider::count());
    }

    public function test_invalid_values_are_reported_per_row_with_the_sheet_row_number(): void
    {
        $path = $this->workbook([
            $this->newRow(['name' => 'Fine', 'address' => 'A1']),
            [],  // blank row: skipped but still counted in numbering
            $this->newRow(['name' => 'Bad Email', 'address' => 'A2', 'email' => 'not-an-email', 'latitude' => '123.4', 'provider_type' => 'no_such_type']),
        ]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame(2, $job->total_rows);
        $bad = ImportRow::where('import_job_id', $job->id)->where('action', 'error')->firstOrFail();
        $this->assertSame(4, $bad->row_number); // header = 1, valid = 2, blank = 3, bad = 4
        $codes = array_column($bad->errors, 'code');
        $this->assertContains('validation_email', $codes);
        $this->assertContains('validation_between', $codes);
        $this->assertContains('unknown_provider_type', $codes);
    }

    public function test_a_create_row_needs_type_name_and_address(): void
    {
        $path = $this->workbook([$this->row(['name' => 'Only Name'])], ['schema_version', 'locale', 'name', 'address', 'provider_type']);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPSERT);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertEqualsCanonicalizing(['address_required', 'provider_type_required'], $this->errorCodes($job));
    }

    // ------------------------------------------------------------------ identity and duplicates

    public function test_the_same_provider_and_locale_twice_in_the_file_is_an_error(): void
    {
        $p = $this->provider(['en' => ['name' => 'Existing', 'address' => 'A']]);
        $path = $this->workbook([
            $this->row(['provider_id' => $p->id, 'name' => 'First']),
            $this->row(['provider_id' => $p->id, 'name' => 'Second']),
        ]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPDATE_ONLY);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame(['duplicate_row'], $this->errorCodes($job));
        $this->assertSame('Existing', $p->translation('en')->fresh()->name);
    }

    public function test_two_identical_new_providers_in_one_file_are_flagged_as_duplicates(): void
    {
        $path = $this->workbook([$this->newRow(), $this->newRow()]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame(['duplicate_create_row'], $this->errorCodes($job));
    }

    public function test_an_unknown_provider_id_is_rejected_never_silently_created(): void
    {
        $path = $this->workbook([$this->row(['provider_id' => 987654, 'name' => 'Ghost'])]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPSERT);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame(['unknown_provider_id'], $this->errorCodes($job));
        $this->assertSame(0, Provider::withTrashed()->count());
    }

    public function test_a_trashed_provider_id_is_rejected(): void
    {
        $p = $this->provider(['en' => ['name' => 'Gone', 'address' => 'A']]);
        $p->delete();
        $path = $this->workbook([$this->row(['provider_id' => $p->id, 'name' => 'Revived'])]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPSERT);

        $this->assertSame(['provider_trashed'], $this->errorCodes($job));
        $this->assertSame('Gone', ProviderTranslation::where('provider_id', $p->id)->value('name'));
    }

    public function test_nothing_is_ever_matched_by_name(): void
    {
        $this->provider(['en' => ['name' => 'Alpha Lab', 'address' => '1 Test St']]);

        $job = $this->importAndApply($this->editor(), $this->workbook([$this->newRow()]), ImportJob::MODE_UPSERT);

        $this->assertSame(2, Provider::count(), 'a blank provider_id always creates, even with an identical name');
        $this->assertSame(1, $job->error_summary['warnings']['possible_duplicate_existing']['count']);
    }

    public function test_conflicting_provider_level_values_between_language_rows_are_rejected(): void
    {
        $p = $this->provider(['en' => ['name' => 'E', 'address' => 'A']], ['phone' => '0100']);
        $path = $this->workbook([
            $this->row(['provider_id' => $p->id, 'locale' => 'en', 'phone' => '0111']),
            $this->row(['provider_id' => $p->id, 'locale' => 'ar', 'phone' => '0122', 'name' => 'ع', 'address' => 'ع']),
        ]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPDATE_ONLY);

        $this->assertSame(['conflicting_values'], $this->errorCodes($job));
    }

    // ------------------------------------------------------------------ modes

    public function test_create_only_rejects_rows_that_reference_an_existing_provider(): void
    {
        $p = $this->provider(['en' => ['name' => 'E', 'address' => 'A']]);
        $path = $this->workbook([$this->row(['provider_id' => $p->id, 'locale' => 'ar', 'name' => 'ع', 'address' => 'ع', 'provider_type' => 'laboratory'])]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(['provider_id_not_allowed'], $this->errorCodes($job));
    }

    public function test_update_only_rejects_rows_without_a_provider_id(): void
    {
        $path = $this->workbook([$this->newRow()]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPDATE_ONLY);

        $this->assertSame(['provider_id_required'], $this->errorCodes($job));
    }

    public function test_a_dry_run_validates_and_previews_but_can_never_be_applied(): void
    {
        $editor = $this->editor();
        $before = $this->canonicalCounts();
        $job = $this->startImport($editor, $this->workbook([$this->newRow()]), ImportJob::MODE_DRY_RUN);

        $this->assertSame(ImportJob::STATUS_READY, $job->status);
        $this->assertSame(1, $job->insert_count);
        $this->assertSame($before, $this->canonicalCounts());

        $this->actingAs($editor)->post(route('imports.confirm', $job))->assertSessionHasErrors('import');
        $this->assertSame(ImportJob::STATUS_READY, $job->fresh()->status);

        $this->expectException(ImportNotApplicable::class);
        app(ImportApplier::class)->apply($job);
    }

    // ------------------------------------------------------------------ whole-file blocking

    public function test_one_invalid_row_blocks_the_whole_file_and_nothing_is_written(): void
    {
        $existing = $this->provider(['en' => ['name' => 'Keep Me', 'address' => 'Old']], ['phone' => '0100']);
        $before = $this->canonicalCounts();
        $path = $this->workbook([
            $this->newRow(['name' => 'Valid One', 'address' => 'V1']),
            $this->row(['provider_id' => $existing->id, 'name' => 'Changed Name']),
            $this->newRow(['name' => 'Valid Two', 'address' => 'V2']),
            $this->newRow(['name' => 'Invalid', 'address' => 'V3', 'email' => 'nope']),
        ]);
        $editor = $this->editor();

        $job = $this->startImport($editor, $path, ImportJob::MODE_UPSERT);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame(1, $job->error_count);
        $this->assertSame(2, $job->insert_count);
        $this->assertSame(1, $job->update_count);
        $this->assertSame($before, $this->canonicalCounts());
        $this->assertSame('Keep Me', $existing->translation('en')->fresh()->name);

        // No apply path: HTTP confirm is rejected, and so are the job handler and the applier.
        $this->actingAs($editor)->post(route('imports.confirm', $job))->assertSessionHasErrors('import');
        ApplyImportJob::dispatchSync($job->id);
        $this->assertSame($before, $this->canonicalCounts());
        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->fresh()->status);
        $this->assertNull($job->fresh()->confirmed_at);

        try {
            app(ImportApplier::class)->apply($job);
            $this->fail('apply() must refuse a validation_failed job');
        } catch (ImportNotApplicable) {
            $this->assertSame($before, $this->canonicalCounts());
        }
    }

    // ------------------------------------------------------------------ blank cells never clear

    public function test_empty_update_cells_preserve_existing_values(): void
    {
        $p = $this->provider(['en' => ['name' => 'Old Name', 'address' => 'Old Address', 'city' => 'Cairo', 'hours' => '9-5']], ['phone' => '0100', 'email' => 'old@example.test']);
        $path = $this->workbook([$this->row([
            'provider_id' => $p->id, 'name' => 'New Name', 'phone' => '', 'email' => null, 'city' => '   ', 'hours' => null,
        ])]);

        $job = $this->importAndApply($this->editor(), $path, ImportJob::MODE_UPDATE_ONLY);

        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $p->refresh()->load('translations');
        $t = $p->translation('en');
        $this->assertSame('New Name', $t->name);
        $this->assertSame('Old Address', $t->address);
        $this->assertSame('Cairo', $t->city);
        $this->assertSame('9-5', $t->hours);
        $this->assertSame('0100', $p->phone);
        $this->assertSame('old@example.test', $p->email);
    }

    public function test_absent_columns_preserve_existing_values(): void
    {
        $p = $this->provider(['en' => ['name' => 'Old', 'address' => 'Addr', 'city' => 'Giza']], ['phone' => '0100', 'website' => 'https://old.example.test']);
        $path = $this->workbook([['schema_version' => 1, 'provider_id' => $p->id, 'locale' => 'en', 'name' => 'Renamed']], ['schema_version', 'provider_id', 'locale', 'name']);

        $this->importAndApply($this->editor(), $path, ImportJob::MODE_UPDATE_ONLY);

        $p->refresh()->load('translations');
        $this->assertSame('Renamed', $p->translation('en')->name);
        $this->assertSame('Giza', $p->translation('en')->city);
        $this->assertSame('Addr', $p->translation('en')->address);
        $this->assertSame('0100', $p->phone);
        $this->assertSame('https://old.example.test', $p->website);
    }

    public function test_an_update_that_changes_nothing_is_reported_unchanged_and_writes_nothing(): void
    {
        $p = $this->provider(['en' => ['name' => 'Same', 'address' => 'Addr']], ['phone' => '0100']);
        $path = $this->workbook([$this->row(['provider_id' => $p->id, 'name' => 'Same', 'address' => 'Addr', 'phone' => '0100'])]);

        $job = $this->importAndApply($this->editor(), $path, ImportJob::MODE_UPDATE_ONLY);

        $this->assertSame(0, $job->update_count);
        $this->assertSame(1, $job->unchanged_count);
        $this->assertSame(ImportJob::STATUS_COMPLETED, $job->status);
        $this->assertSame(1, $p->fresh()->revision);
    }

    // ------------------------------------------------------------------ limits and structure

    public function test_the_row_cap_rejects_the_file(): void
    {
        config(['providers.imports.max_rows' => 2]);
        $path = $this->workbook([$this->newRow(['name' => 'A', 'address' => '1']), $this->newRow(['name' => 'B', 'address' => '2']), $this->newRow(['name' => 'C', 'address' => '3'])]);

        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame('too_many_rows', $job->error_summary['file_errors'][0]['code']);
        $this->assertSame(0, ImportRow::count());
    }

    public function test_validation_runs_in_chunks_with_staged_rows_and_exact_counts(): void
    {
        config(['providers.imports.validation_chunk_size' => 7]);
        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = $this->newRow(['name' => "Lab $i", 'address' => "Street $i"]);
        }

        $job = $this->startImport($this->editor(), $this->workbook($rows), ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_READY, $job->status);
        $this->assertSame(25, $job->total_rows);
        $this->assertSame(25, $job->insert_count);
        $this->assertSame(25, ImportRow::where('import_job_id', $job->id)->count());
        $this->assertSame(2, ImportRow::where('import_job_id', $job->id)->orderBy('row_number')->first()->row_number);
        $this->assertSame(0, Provider::count(), 'validation must never touch the canonical tables');
    }

    public function test_validation_does_not_run_a_query_per_row(): void
    {
        config(['providers.imports.validation_chunk_size' => 100]);
        $p = $this->provider(['en' => ['name' => 'E', 'address' => 'A']]);
        $rows = [];
        for ($i = 1; $i <= 60; $i++) {
            $rows[] = $this->newRow(['name' => "Lab $i", 'address' => "Street $i"]);
        }
        $rows[] = $this->row(['provider_id' => $p->id, 'name' => 'E2']);
        $path = $this->workbook($rows);

        $queries = 0;
        DB::listen(function ($q) use (&$queries) {
            if (str_contains($q->sql, 'import_rows') || str_contains($q->sql, 'providers') || str_contains($q->sql, 'provider_')) {
                $queries++;
            }
        });
        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_UPSERT);

        $this->assertSame(ImportJob::STATUS_READY, $job->status);
        $this->assertLessThan(20, $queries, "61 rows used $queries provider/import queries");
    }

    // ------------------------------------------------------------------ error report

    public function test_a_failed_validation_produces_a_private_error_report_with_sheet_row_numbers(): void
    {
        $path = $this->workbook([
            $this->newRow(['name' => 'Fine', 'address' => 'A']),
            $this->newRow(['name' => 'Bad', 'address' => 'B', 'email' => 'x']),
        ]);
        $job = $this->startImport($this->editor(), $path, ImportJob::MODE_CREATE_ONLY);

        $this->assertNotNull($job->error_report_path);
        $this->assertStringStartsWith('imports/reports/', $job->error_report_path);
        Storage::disk('local')->assertExists($job->error_report_path);

        $rows = $this->readStored('local', $job->error_report_path);
        $this->assertCount(1, $rows);
        $this->assertEquals(3, $rows[0]['row_number']);
        $this->assertSame('email', $rows[0]['field']);
        $this->assertSame('validation_email', $rows[0]['code']);
        $this->assertStringNotContainsString('@', (string) $rows[0]['message_en'], 'reports must not echo cell values');
    }

    public function test_the_error_report_writer_emits_formula_safe_text_cells(): void
    {
        $job = ImportJob::create([
            'user_id' => $this->editor()->id, 'mode' => 'upsert', 'status' => 'validation_failed',
            'original_filename' => 'x.xlsx', 'stored_path' => 'imports/x.xlsx', 'file_sha256' => str_repeat('a', 64), 'file_size' => 1,
            'error_summary' => ['file_errors' => [['code' => '=cmd|calc!A0']]],
        ]);

        $relative = app(ErrorReportWriter::class)->write($job);

        $absolute = Storage::disk('local')->path($relative);
        $zip = new \ZipArchive;
        $zip->open($absolute);
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringNotContainsString('<f>', $sheet, 'no cell may be a formula');
        $rows = $this->readStored('local', $relative);
        $this->assertSame("'=cmd|calc!A0", $rows[0]['code']);
    }

    public function test_a_file_with_more_than_one_sheet_warns_and_reads_the_first(): void
    {
        $path = $this->workbook([$this->newRow()]);
        $writer = new Writer;
        $multi = $this->tempPath();
        $writer->openToFile($multi);
        $writer->addRow(new Row(array_map(fn ($c) => new StringCell($c), ['schema_version', 'locale', 'provider_type', 'name', 'address'])));
        $writer->addRow(new Row([new NumericCell(1), new StringCell('en'), new StringCell('laboratory'), new StringCell('Multi'), new StringCell('Addr')]));
        $writer->addNewSheetAndMakeItCurrent();
        $writer->addRow(new Row([new StringCell('ignored')]));
        $writer->close();

        $job = $this->startImport($this->editor(), $multi, ImportJob::MODE_CREATE_ONLY);

        $this->assertSame(ImportJob::STATUS_READY, $job->status);
        $this->assertSame(1, $job->error_summary['warnings']['extra_sheets_ignored']['count']);
        unset($path);
    }
}
