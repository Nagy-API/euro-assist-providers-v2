<?php

namespace App\Console\Commands;

use App\Services\Legacy\PairSuggester;

class LegacySuggestPairsCommand extends LegacyCommand
{
    protected $signature = 'legacy:suggest-pairs
        {--batch= : Batch uuid or id (default: the latest active batch)}
        {--min-signals= : Distinct signal kinds that must agree (default legacy.suggestions.min_signals = 2)}
        {--max-per-row= : Suggestions kept per source row (default legacy.suggestions.max_per_row)}
        {--dry-run : Count only; write nothing}';

    protected $description = 'Suggest English/Arabic pairs for human review. Never approves anything.';

    public function handle(PairSuggester $suggester): int
    {
        return $this->guarded(function () use ($suggester) {
            $result = $suggester->suggest(
                $this->resolveBatch(),
                (int) ($this->option('min-signals') ?: config('legacy.suggestions.min_signals')),
                (int) ($this->option('max-per-row') ?: config('legacy.suggestions.max_per_row')),
                (bool) $this->option('dry-run'),
            );

            $this->printCounts('Suggestions', [
                'english rows considered' => $result['rows']['en'],
                'arabic rows considered' => $result['rows']['ar'],
                'candidate pairs' => $result['candidates'],
                'qualified (>= min signals)' => $result['qualified'],
                'kept after per-row cap' => $result['kept'],
                'created' => $result['created'],
                'already existing' => $result['existing'],
                'oversized key groups skipped' => $result['skipped_groups'],
            ] + array_combine(
                array_map(fn ($k) => 'signal: '.$k, array_keys($result['signal_kinds'])),
                array_values($result['signal_kinds']),
            ));
            $this->info($result['dry_run'] ? 'Dry run: nothing was written. Nothing is ever auto-approved.' : 'Suggestions stored for review. Nothing was approved.');

            return self::SUCCESS;
        });
    }
}
