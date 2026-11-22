<?php

namespace Tests\Feature\Transfers;

use App\Services\Transfers\FormulaSafety;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FormulaSafetyTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function awkwardValues(): array
    {
        return [
            'excel dde payload' => ["=cmd|' /C calc'!A0"],
            'plus' => ['+1+1'],
            'minus' => ['-2'],
            'at' => ['@SUM(1)'],
            'tab start' => ["\tTAB"],
            'lf start' => ["\nline"],
            'cr start' => ["\rline"],
            'space then equals' => ['  =1+1'],
            'legit apostrophe' => ["'quoted text"],
            'apostrophe then formula' => ["'=1+1"],
            'two apostrophes then formula' => ["''=1+1"],
            'apostrophe then plus' => ["'+201001234567"],
            'phone with plus' => ['+201001234567'],
            'plain' => ['Cairo Lab'],
            'arabic' => ['عيادة الأمل'],
            'percent' => ['10% - 20%'],
            'empty' => [''],
            'only apostrophe' => ["'"],
            'mid equals' => ['a=b'],
            'trailing minus' => ['range 1-2'],
        ];
    }

    #[DataProvider('awkwardValues')]
    public function test_unescape_is_the_exact_inverse_of_escape(string $value): void
    {
        $this->assertSame($value, FormulaSafety::unescape(FormulaSafety::escape($value)));
    }

    public function test_dangerous_values_get_exactly_one_leading_apostrophe(): void
    {
        $this->assertSame("'=cmd|' /C calc'!A0", FormulaSafety::escape("=cmd|' /C calc'!A0"));
        $this->assertSame("'+1+1", FormulaSafety::escape('+1+1'));
        $this->assertSame("'-2", FormulaSafety::escape('-2'));
        $this->assertSame("'@SUM(1)", FormulaSafety::escape('@SUM(1)'));
        $this->assertSame("'\tTAB", FormulaSafety::escape("\tTAB"));
        $this->assertSame("'  =1+1", FormulaSafety::escape('  =1+1'));
    }

    public function test_safe_values_are_never_modified(): void
    {
        foreach (['Cairo', "'quoted", 'a=b', '10%', 'عيادة', '', "it's"] as $value) {
            $this->assertSame($value, FormulaSafety::escape($value));
            $this->assertSame($value, FormulaSafety::unescape($value));
        }
    }

    public function test_unescape_strips_only_when_the_remainder_is_dangerous(): void
    {
        $this->assertSame('=1+1', FormulaSafety::unescape("'=1+1"));
        $this->assertSame("'hello", FormulaSafety::unescape("'hello"));
        $this->assertSame("'", FormulaSafety::unescape("'"));
    }
}
