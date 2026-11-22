<?php

namespace Tests\Feature\Legacy;

use App\Services\Legacy\RowNormalizer;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NormalizationTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: ?float, 2: ?float, 3: string}> */
    public static function coordinateCases(): array
    {
        return [
            'q= pattern' => ['https://maps.example.test/?q=30.0444196,31.2357116', 30.0444196, 31.2357116, 'parsed'],
            '&q= pattern' => ['https://maps.example.test/place?hl=en&q=30.05,31.23&z=10', 30.05, 31.23, 'parsed'],
            '@ pattern' => ['https://maps.example.test/place/Name/@29.9792,31.1342,17z/data=x', 29.9792, 31.1342, 'parsed'],
            'negative values' => ['https://maps.example.test/?q=-33.8688,151.2093', -33.8688, 151.2093, 'parsed'],
            'integers' => ['https://maps.example.test/?q=30,31', 30.0, 31.0, 'parsed'],
            'lat out of range' => ['https://maps.example.test/?q=91.5,31.2', null, null, 'out_of_range'],
            'lng out of range' => ['https://maps.example.test/?q=30.5,181', null, null, 'out_of_range'],
            '@ out of range' => ['https://maps.example.test/@-95.0,10.0,15z', null, null, 'out_of_range'],
            'short link is never resolved' => ['https://maps.app.goo.gl/AbCdEf123', null, null, 'none'],
            'q is not coordinates' => ['https://maps.example.test/?q=Some+Clinic+Cairo', null, null, 'none'],
            'other param named q-like' => ['https://maps.example.test/?aq=30.0,31.0', null, null, 'none'],
            'at without trailing comma' => ['https://maps.example.test/@30.0,31.0', null, null, 'none'],
            'blank' => [null, null, null, 'none'],
        ];
    }

    #[DataProvider('coordinateCases')]
    public function test_coordinates_use_only_the_two_documented_patterns_with_range_validation(?string $url, ?float $lat, ?float $lng, string $status): void
    {
        Http::fake();

        [$gotLat, $gotLng, $gotStatus] = (new RowNormalizer)->coordinates($url);

        $this->assertSame([$lat, $lng, $status], [$gotLat, $gotLng, $gotStatus]);
        Http::assertNothingSent(); // URLs are never fetched
    }

    public function test_coordinates_are_rounded_to_the_seven_decimals_of_the_column(): void
    {
        [$lat, $lng] = (new RowNormalizer)->coordinates('https://x.test/?q=30.123456789,31.987654321');

        $this->assertSame([30.1234568, 31.9876543], [$lat, $lng]);
    }

    public function test_provider_normalization_trims_blanks_to_null_and_keeps_text_fields_lossless(): void
    {
        $n = new RowNormalizer;
        $out = $n->provider([
            'name' => "  Synthetic Name \u{00A0}", 'type' => 'Laboratory ', 'address' => "\t12 Test St\n", 'city' => '   ', 'district' => '',
            'phone' => ' 0100 000 0001 / 02-2222 2222 ', 'email' => null, 'website' => '', 'map_url' => '  https://m.test/?q=30,31  ',
            'logo_path' => ' logos/a.png ', 'hours' => "Sat-Thu\n9-5", 'discount_pct' => '10% to 20% discount', 'package' => 'A, B ,C',
            'specialization' => '', 'is_translated' => 1,
        ]);

        $this->assertSame('Synthetic Name', $out['translation']['name']);
        $this->assertSame('Laboratory', $out['type_raw'], 'trailing space variants collapse');
        $this->assertSame('12 Test St', $out['translation']['address']);
        $this->assertNull($out['translation']['city']);
        $this->assertNull($out['translation']['district']);
        $this->assertNull($out['email']);
        $this->assertNull($out['website']);
        $this->assertSame('0100 000 0001 / 02-2222 2222', $out['phone'], 'phone is trimmed but never reformatted');
        $this->assertSame('https://m.test/?q=30,31', $out['map_url'], 'original map URL kept');
        $this->assertSame('logos/a.png', $out['logo_path'], 'raw logo string kept');
        $this->assertSame("Sat-Thu\n9-5", $out['translation']['hours']);
        $this->assertSame('10% to 20% discount', $out['translation']['discount_text']);
        $this->assertSame('A, B ,C', $out['translation']['package_text']);
        $this->assertNull($out['translation']['specialization']);
    }

    public function test_phone_leading_zeros_and_numeric_looking_values_stay_strings(): void
    {
        $n = new RowNormalizer;

        $this->assertSame('01000000001', $n->provider(['name' => 'x', 'phone' => '01000000001'])['phone']);
        $this->assertSame('01000000001', $n->provider(['name' => 'x', 'phone' => 1000000001 > 0 ? '01000000001' : ''])['phone']);
        $this->assertSame('0123', $n->clean('0123'));
        $this->assertSame('0', $n->clean(0), 'a literal zero is data, not blank');
        $this->assertNull($n->clean(''));
        $this->assertNull($n->clean(['array']));
    }

    public function test_phone_key_normalizes_separators_and_arabic_digits_for_signals_only(): void
    {
        $n = new RowNormalizer;

        $this->assertSame('01000000001', $n->phoneKey('0100 000-0001'));
        $this->assertSame('01000000001', $n->phoneKey('٠١٠٠٠٠٠٠٠٠١'));
        $this->assertNull($n->phoneKey('12345'), 'too short to be an identifier');
        $this->assertNull($n->phoneKey(null));
    }

    public function test_featured_normalization_keeps_the_raw_logo_reference_for_the_payload_only(): void
    {
        $out = (new RowNormalizer)->featured([
            'name_english' => ' Brand ', 'name_arabic' => 'علامة', 'type_en' => ' laboratory ', 'type_ar' => null, 'logo_path' => 'x.png',
        ]);

        $this->assertSame(['Brand', 'علامة', 'laboratory', null, 'x.png'], array_values($out));
    }
}
