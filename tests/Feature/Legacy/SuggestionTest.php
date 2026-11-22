<?php

namespace Tests\Feature\Legacy;

use App\Models\LegacyIdMap;
use App\Models\LegacyPairSuggestion;
use App\Models\Provider;

class SuggestionTest extends LegacyTestCase
{
    private const ALLOWED_SIGNALS = ['same_source_id', 'same_phone_normalized', 'same_map_url', 'same_coordinates'];

    /** @return array<string, list<string>> */
    private function suggestionsBySourceId(): array
    {
        $out = [];
        foreach (LegacyPairSuggestion::query()->with(['left', 'right'])->get() as $s) {
            $out[$s->left->source_id.'-'.$s->right->source_id] = $s->signals;
        }
        ksort($out);

        return $out;
    }

    public function test_a_pair_needs_at_least_two_distinct_signal_kinds(): void
    {
        // 1: id only. 2: phone only. 3: id + phone. 4/5: map URL + coordinates (+ phone) from different ids.
        $this->en(['id' => 1, 'phone' => '01000000001']);
        $this->ar(['id' => 1, 'phone' => '01999999991']);
        $this->en(['id' => 2, 'phone' => '01222222222']);
        $this->ar(['id' => 22, 'phone' => '0122 222 2222']);
        $this->en(['id' => 3, 'phone' => '01333333333']);
        $this->ar(['id' => 3, 'phone' => '٠١٣٣٣٣٣٣٣٣٣']);
        $this->en(['id' => 4, 'map_url' => 'https://m.test/?q=30.1,31.2']);
        $this->ar(['id' => 44, 'map_url' => 'https://m.test/?q=30.1,31.2']);

        $this->runPipeline();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();

        $this->assertSame([
            '3-3' => ['same_phone_normalized', 'same_source_id'],
            '4-44' => ['same_coordinates', 'same_map_url'],
        ], $this->suggestionsBySourceId());
    }

    public function test_min_signals_is_configurable_and_signals_are_names_only(): void
    {
        $this->en(['id' => 1, 'phone' => '01000000001']);
        $this->ar(['id' => 1, 'phone' => '01999999991']);

        $this->runPipeline();
        $this->artisan('legacy:suggest-pairs', ['--min-signals' => 1])->assertSuccessful();

        $this->assertSame(['1-1' => ['same_source_id']], $this->suggestionsBySourceId());
        $json = (string) \DB::table('legacy_pair_suggestions')->value('signals');
        $this->assertSame(['same_source_id'], json_decode($json, true));
        $this->assertEmpty(array_diff(array_merge(...array_values($this->suggestionsBySourceId())), self::ALLOWED_SIGNALS));
        $this->assertStringNotContainsString('0100', $json);
    }

    public function test_suggestions_are_deduplicated_and_rerunning_changes_nothing(): void
    {
        $this->en(['id' => 1, 'phone' => '01000000001']);
        $this->ar(['id' => 1, 'phone' => '01000000001']);
        $this->runPipeline();

        $this->artisan('legacy:suggest-pairs')->assertSuccessful();
        $first = LegacyPairSuggestion::query()->orderBy('id')->get(['left_map_id', 'right_map_id', 'signals', 'signal_count', 'status'])->toArray();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();
        $this->artisan('legacy:suggest-pairs')->assertSuccessful();

        $this->assertCount(1, $first);
        $this->assertSame($first, LegacyPairSuggestion::query()->orderBy('id')->get(['left_map_id', 'right_map_id', 'signals', 'signal_count', 'status'])->toArray());
    }

    public function test_dry_run_writes_nothing_and_suggestions_never_change_identity_state(): void
    {
        $this->en(['id' => 1, 'phone' => '01000000001']);
        $this->ar(['id' => 1, 'phone' => '01000000001']);
        $this->runPipeline();

        $this->artisan('legacy:suggest-pairs', ['--dry-run' => true])->expectsOutputToContain('Dry run')->assertSuccessful();
        $this->assertSame(0, LegacyPairSuggestion::query()->count());

        $this->artisan('legacy:suggest-pairs')->assertSuccessful();
        $this->assertSame(1, LegacyPairSuggestion::query()->count());
        $this->assertSame(['suggested'], LegacyPairSuggestion::query()->pluck('status')->unique()->all(), 'nothing is approved automatically');
        $this->assertSame(['unpaired'], LegacyIdMap::query()->pluck('status')->unique()->all());
        $this->assertSame(2, Provider::query()->whereNull('deleted_at')->count());
    }

    public function test_generation_is_bounded_by_the_group_cap_and_the_per_row_cap(): void
    {
        // A brand-wide phone shared by many rows on both sides: 30 x 30 = 900 pairs, far above the cap.
        for ($i = 1; $i <= 30; $i++) {
            $this->en(['id' => $i, 'phone' => '0160 000 0000', 'name' => "Brand EN {$i}"]);
            $this->ar(['id' => $i + 1000, 'phone' => '01600000000', 'name' => "علامة {$i}"]);
        }
        // One genuine candidate group with two signals, mixed in the same data set.
        $this->en(['id' => 500, 'phone' => '01700000000', 'map_url' => 'https://m.test/?q=29.9,31.1']);
        $this->ar(['id' => 500, 'phone' => '01700000000', 'map_url' => 'https://m.test/?q=29.9,31.1']);

        $this->runPipeline();
        config(['legacy.suggestions.max_group_pairs' => 200]);
        $this->artisan('legacy:suggest-pairs', ['--min-signals' => 1])
            ->expectsOutputToContain('oversized key groups skipped')->assertSuccessful();

        // The 900-pair phone group is never expanded; only the small groups produce suggestions.
        $this->assertSame(['500-500'], array_keys($this->suggestionsBySourceId()));
        $this->assertSame(
            ['same_coordinates', 'same_map_url', 'same_phone_normalized', 'same_source_id'],
            $this->suggestionsBySourceId()['500-500'],
        );
    }

    public function test_max_per_row_caps_suggestions_per_source_row(): void
    {
        // One EN row whose map URL equals three AR rows (small group, under the cap).
        $this->en(['id' => 1, 'map_url' => 'https://m.test/?q=30.0,31.0', 'phone' => '01000000001']);
        foreach ([11, 12, 13] as $id) {
            $this->ar(['id' => $id, 'map_url' => 'https://m.test/?q=30.0,31.0', 'phone' => '01000000001']);
        }
        $this->runPipeline();

        $this->artisan('legacy:suggest-pairs', ['--max-per-row' => 2])->assertSuccessful();

        $this->assertSame(2, LegacyPairSuggestion::query()->count());
        $perLeft = LegacyPairSuggestion::query()->pluck('left_map_id')->countBy()->all();
        $this->assertSame([2], array_values($perLeft));
        // deterministic: the lowest map ids win ties
        $this->assertSame([['1', '11'], ['1', '12']], LegacyPairSuggestion::query()->with(['left', 'right'])->orderBy('id')->get()
            ->map(fn ($s) => [(string) $s->left->source_id, (string) $s->right->source_id])->all());
    }

    public function test_rows_that_are_already_paired_or_not_applied_are_not_suggested(): void
    {
        $this->en(['id' => 1, 'phone' => '01000000001']);
        $this->ar(['id' => 1, 'phone' => '01000000001']);
        $this->en(['id' => 2, 'type' => 'Nonsense', 'phone' => '01555555555']); // needs_review: no provider
        $this->ar(['id' => 2, 'phone' => '01555555555']);
        $this->runPipeline();

        $this->artisan('legacy:suggest-pairs')->assertSuccessful();

        $this->assertSame(['1-1'], array_keys($this->suggestionsBySourceId()));
    }
}
