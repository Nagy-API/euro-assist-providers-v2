<?php

namespace Tests\Feature\Transfers;

use App\Models\ImportJob;
use App\Models\Provider;
use App\Services\Transfers\ImportFileInspector;
use App\Services\Transfers\ImportRejected;
use App\Services\Transfers\TemplateSchema;
use ZipArchive;

/**
 * Security review S1: limits are enforced on the bytes actually produced while inflating, not on
 * the sizes an archive declares, and the parser bounds columns, cell length and rows. Every
 * rejection leaves the canonical data untouched.
 */
class ArchiveHardeningTest extends TransfersTestCase
{
    private function validWorkbook(): string
    {
        return $this->workbook([$this->row(['provider_type' => 'laboratory', 'name' => 'Alpha Lab', 'address' => '1 Test St'])]);
    }

    private function addEntry(string $path, string $name, string $content, bool $store = false): void
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $zip->addFromString($name, $content);
        if ($store) {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $zip->close();
    }

    private function assertInspectorRejects(string $path, array $codes): void
    {
        try {
            app(ImportFileInspector::class)->inspect($path);
            $this->fail('the archive should have been rejected');
        } catch (ImportRejected $e) {
            $this->assertContains($e->errorCode, $codes);
        }
    }

    /**
     * Rewrite the declared uncompressed size of one entry (local header + central directory) to
     * a small value: the archive now lies about how much it expands.
     */
    private function lieAboutSize(string $path, string $entry, int $fakeSize): void
    {
        $bytes = (string) file_get_contents($path);
        $patched = 0;
        foreach (["PK\x03\x04" => [22, 26], "PK\x01\x02" => [24, 28]] as $signature => [$sizeOffset, $nameLenOffset]) {
            $offset = 0;
            while (($pos = strpos($bytes, $signature, $offset)) !== false) {
                $nameLength = unpack('v', substr($bytes, $pos + $nameLenOffset, 2))[1];
                $nameStart = $pos + ($signature === "PK\x03\x04" ? 30 : 46);
                if (substr($bytes, $nameStart, $nameLength) === $entry) {
                    $bytes = substr_replace($bytes, pack('V', $fakeSize), $pos + $sizeOffset, 4);
                    $patched++;
                }
                $offset = $pos + 4;
            }
        }
        $this->assertSame(2, $patched, 'both headers patched');
        file_put_contents($path, $bytes);
    }

    public function test_an_archive_that_understates_its_expanded_size_is_rejected(): void
    {
        config(['providers.imports.max_uncompressed_bytes' => 2 * 1048576]);
        $path = $this->validWorkbook();
        $this->addEntry($path, 'xl/worksheets/sheet9.xml', str_repeat('A', 8 * 1048576)); // 8 MiB real
        $this->lieAboutSize($path, 'xl/worksheets/sheet9.xml', 1000);                     // claims 1 KB

        $this->assertInspectorRejects($path, ['corrupt_file', 'zip_bomb']);
    }

    public function test_the_per_entry_cap_is_enforced_on_real_bytes_even_without_compression(): void
    {
        config(['providers.imports.max_entry_bytes' => 1048576, 'providers.imports.max_uncompressed_bytes' => 50 * 1048576]);
        $path = $this->validWorkbook();
        $this->addEntry($path, 'xl/media/image1.bin', random_bytes(2 * 1048576), store: true); // ratio ~1, so only the measured cap can catch it

        $this->assertInspectorRejects($path, ['zip_bomb']);
    }

    public function test_the_total_cap_is_enforced_on_real_bytes_across_many_entries(): void
    {
        config(['providers.imports.max_entry_bytes' => 50 * 1048576, 'providers.imports.max_uncompressed_bytes' => 3 * 1048576]);
        $path = $this->validWorkbook();
        for ($i = 1; $i <= 4; $i++) {
            $this->addEntry($path, "xl/media/image{$i}.bin", random_bytes(1048576), store: true);
        }

        $this->assertInspectorRejects($path, ['zip_bomb']);
    }

    public function test_xml_parts_with_a_dtd_or_entity_declarations_are_rejected(): void
    {
        $lol = '<?xml version="1.0"?><!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]><lolz>&lol2;</lolz>';
        foreach (['xl/sharedStrings.xml', 'docProps/custom.xml'] as $entry) {
            $path = $this->validWorkbook();
            $this->addEntry($path, $entry, $lol);
            $this->assertInspectorRejects($path, ['forbidden_content']);
        }
    }

    public function test_a_valid_workbook_still_passes_the_measured_guard(): void
    {
        app(ImportFileInspector::class)->inspect($this->validWorkbook());
        $this->addToAssertionCount(1);
    }

    public function test_too_many_columns_rejects_the_whole_file_without_writes(): void
    {
        config(['providers.imports.max_columns' => 40]);
        $columns = [...TemplateSchema::COLUMNS];
        for ($i = count($columns); $i < 60; $i++) {
            $columns[] = 'extra_'.$i;
        }
        $before = Provider::count();

        $job = $this->startImport($this->editor(), $this->workbook([$this->row(['provider_type' => 'laboratory', 'name' => 'X', 'address' => 'Y'])], $columns));

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame('too_many_columns', $job->error_summary['file_errors'][0]['code']);
        $this->assertSame($before, Provider::count());
    }

    public function test_an_over_long_cell_rejects_the_whole_file(): void
    {
        config(['providers.imports.max_cell_chars' => 1000]);
        $job = $this->startImport($this->editor(), $this->workbook([$this->row(['provider_type' => 'laboratory', 'name' => 'X', 'address' => str_repeat('a', 1001)])]));

        $this->assertSame(ImportJob::STATUS_VALIDATION_FAILED, $job->status);
        $this->assertSame('cell_too_long', $job->error_summary['file_errors'][0]['code']);
    }

    public function test_memory_stays_bounded_while_measuring_a_large_but_legal_archive(): void
    {
        config(['providers.imports.max_entry_bytes' => 60 * 1048576, 'providers.imports.max_uncompressed_bytes' => 100 * 1048576, 'providers.imports.max_zip_ratio' => 100000]);
        $path = $this->validWorkbook();
        $this->addEntry($path, 'xl/media/big.bin', str_repeat('B', 40 * 1048576)); // 40 MiB inflated

        memory_reset_peak_usage();
        $base = memory_get_usage();
        app(ImportFileInspector::class)->inspect($path);
        $growth = memory_get_peak_usage() - $base;

        // Streaming in 64 KiB chunks: peak growth is a tiny fraction of the 40 MiB inflated.
        $this->assertLessThan(4 * 1048576, $growth, 'measured inflate must not buffer whole entries');
    }

    public function test_dtd_is_rejected_in_any_entry_name_and_non_utf8_xml_is_refused(): void
    {
        $lol = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><x>&a;</x>';
        $path = $this->validWorkbook();
        $this->addEntry($path, 'xl/worksheets/sheet1.bin', $lol); // rels could point a sheet at any name
        $this->assertInspectorRejects($path, ['forbidden_content']);

        foreach (["\xFF\xFE".mb_convert_encoding('<?xml version="1.0"?><x/>', 'UTF-16LE', 'UTF-8'),
            '<?xml version="1.0" encoding="UTF-16"?><x/>',
            '<?xml version="1.0" encoding="ISO-8859-1"?><x/>'] as $content) {
            $path = $this->validWorkbook();
            $this->addEntry($path, 'docProps/custom.xml', $content);
            $this->assertInspectorRejects($path, ['forbidden_content']);
        }

        $path = $this->validWorkbook();
        $this->addEntry($path, 'docProps/custom.xml', '<?xml version="1.0" encoding="UTF-8"?><x/>');
        app(ImportFileInspector::class)->inspect($path); // explicit UTF-8 stays accepted
    }
}
