<?php

namespace App\Services\Transfers;

use App\Models\ImportJob;
use App\Models\ImportRow;
use App\Models\Provider;
use App\Models\ProviderTranslation;
use App\Models\ProviderType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell;

/**
 * Whole-file validation + staging (ADR-008). Parses the stored workbook incrementally, validates
 * EVERY row, computes the diff against the current database with batched lookups (one set of
 * queries per chunk, none per row) and writes import_rows. Any row error makes the job
 * validation_failed: there is no apply path for such a job. Canonical tables are never written.
 */
class ImportValidator
{
    public function __construct(
        private readonly TemplateRowParser $parser,
        private readonly RowRules $rules,
        private readonly ErrorReportWriter $reports,
    ) {}

    public function run(ImportJob $job): void
    {
        $disk = (string) config('providers.imports.disk');
        $path = PrivateFiles::absolute($disk, $job->stored_path);
        $chunkSize = max(1, (int) config('providers.imports.validation_chunk_size'));
        $maxRows = (int) config('providers.imports.max_rows');

        ImportRow::query()->where('import_job_id', $job->id)->delete();

        $state = new ValidationState;
        $stream = new XlsxRowStream;
        $fileErrors = [];

        try {
            if (! is_file($path)) {
                throw new ImportRejected('file_missing');
            }
            // Defense in depth: re-run the measured zip guard right before parsing (retries included).
            app(ImportFileInspector::class)->inspect($path);

            [$typeMap, $typeKeyById] = $this->loadTypes();
            $columns = null;
            $buffer = [];

            foreach ($stream->rows($path, $maxRows * 2 + 1000) as $rowNumber => $cells) {
                if ($rowNumber === 1) {
                    $columns = $this->readHeader($cells, $job->mode, $state);

                    continue;
                }
                $parsed = $this->parser->parse($rowNumber, $cells, $columns ?? []);
                if ($parsed === null) {
                    continue;
                }
                if (++$state->total > $maxRows) {
                    throw new ImportRejected('too_many_rows', ['max' => $maxRows]);
                }
                $buffer[] = $parsed;

                if (count($buffer) >= $chunkSize) {
                    $this->flush($job, $buffer, $state, $typeMap, $typeKeyById);
                    $buffer = [];
                    if (! $this->stillValidating($job)) {
                        $this->discardStaged($job);

                        return;
                    }
                }
            }
            if ($columns === null) {
                throw new ImportRejected('header_missing');
            }
            if ($buffer !== []) {
                $this->flush($job, $buffer, $state, $typeMap, $typeKeyById);
            }
            if ($state->total === 0) {
                throw new ImportRejected('no_data_rows');
            }
            if ($stream->ignoredSheets > 0) {
                $state->warn('extra_sheets_ignored');
            }
        } catch (ImportRejected $e) {
            $fileErrors[] = ['code' => $e->errorCode] + ($e->params !== [] ? ['params' => $e->params] : []);
            ImportRow::query()->where('import_job_id', $job->id)->delete();
            Log::info('import.validation.rejected', ['job' => $job->uuid, 'code' => $e->errorCode]);
        }

        $this->finish($job, $state, $fileErrors);
    }

    // ------------------------------------------------------------------ header

    /**
     * @param  list<Cell>  $cells
     * @return array<string, int> column key => cell index
     *
     * @throws ImportRejected
     */
    private function readHeader(array $cells, string $mode, ValidationState $state): array
    {
        $columns = [];
        $duplicates = [];
        foreach ($cells as $index => $cell) {
            $name = $cell->getValue();
            if (! is_string($name) && ! is_int($name) && ! is_float($name)) {
                continue;
            }
            $name = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $name) ?? ''));
            if ($name === '') {
                continue;
            }
            if (isset($columns[$name])) {
                $duplicates[$name] = true;

                continue;
            }
            $columns[$name] = $index;
        }

        if ($columns === []) {
            throw new ImportRejected('header_missing');
        }
        if ($duplicates !== []) {
            throw new ImportRejected('duplicate_columns', ['columns' => $this->nameList(array_keys($duplicates))]);
        }

        $required = TemplateSchema::ALWAYS_REQUIRED;
        if ($mode === ImportJob::MODE_UPDATE_ONLY) {
            $required[] = 'provider_id';
        }
        if ($mode === ImportJob::MODE_CREATE_ONLY) {
            array_push($required, 'provider_type', 'name', 'address');
        }
        $missing = array_values(array_diff($required, array_keys($columns)));
        if ($missing !== []) {
            throw new ImportRejected('missing_columns', ['columns' => implode(', ', $missing)]);
        }

        $known = [];
        foreach ($columns as $name => $index) {
            if (in_array($name, TemplateSchema::COLUMNS, true)) {
                $known[$name] = $index;
            } else {
                $state->unknownColumns[] = mb_substr($name, 0, 60);
            }
        }
        if ($state->unknownColumns !== []) {
            $state->warn('unknown_column');
        }

        return $known;
    }

    /** @param list<string> $names */
    private function nameList(array $names): string
    {
        return implode(', ', array_map(fn (string $n) => mb_substr($n, 0, 60), array_slice($names, 0, 10)));
    }

    // ------------------------------------------------------------------ chunk

    /**
     * @return array{0: array<string, array{id: int, key: string, active: bool}>, 1: array<int, string>}
     */
    private function loadTypes(): array
    {
        $byKey = [];
        $byId = [];
        foreach (ProviderType::query()->get(['id', 'key', 'is_active']) as $type) {
            $byKey[mb_strtolower($type->key)] = ['id' => (int) $type->id, 'key' => $type->key, 'active' => (bool) $type->is_active];
            $byId[(int) $type->id] = $type->key;
        }

        return [$byKey, $byId];
    }

    /**
     * @param  list<ParsedRow>  $rows
     * @param  array<string, array{id: int, key: string, active: bool}>  $typeMap
     * @param  array<int, string>  $typeKeyById
     */
    private function flush(ImportJob $job, array $rows, ValidationState $state, array $typeMap, array $typeKeyById): void
    {
        $ids = [];
        foreach ($rows as $row) {
            if ($row->providerId !== null) {
                $ids[$row->providerId] = true;
            }
        }
        /** @var Collection<int, Provider> $existing */
        $existing = $ids === []
            ? collect()
            : Provider::query()->withTrashed()->with('translations')->whereIn('id', array_keys($ids))->get()->keyBy('id');

        $records = [];
        $creates = [];
        $now = now()->toDateTimeString();

        foreach ($rows as $row) {
            $records[] = $this->stage($job, $row, $existing, $typeMap, $typeKeyById, $state, $creates) + [
                'import_job_id' => $job->id,
                'row_number' => $row->rowNumber,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->flagPossibleDuplicates($creates, $state);

        DB::table('import_rows')->insert($records);

        ImportJob::query()->whereKey($job->id)->where('status', ImportJob::STATUS_VALIDATING)->update([
            'total_rows' => $state->total,
            'insert_count' => $state->creates,
            'update_count' => $state->updates,
            'unchanged_count' => $state->unchanged,
            'error_count' => $state->errorRows,
            'updated_at' => $now,
        ]);
    }

    /**
     * Validate one row, compute its action and diff, and return the import_rows columns.
     *
     * @param  Collection<int, Provider>  $existing
     * @param  array<string, array{id: int, key: string, active: bool}>  $typeMap
     * @param  array<int, string>  $typeKeyById
     * @param  list<array{row: int, locale: string, name: string, address: string}>  $creates
     * @return array<string, mixed>
     */
    private function stage(ImportJob $job, ParsedRow $p, Collection $existing, array $typeMap, array $typeKeyById, ValidationState $state, array &$creates): array
    {
        $mode = $job->mode;
        $creating = $p->providerId === null;
        $provider = null;

        if ($mode === ImportJob::MODE_CREATE_ONLY && ! $creating) {
            $p->error('provider_id', 'provider_id_not_allowed');
        }
        if ($mode === ImportJob::MODE_UPDATE_ONLY && $creating) {
            $p->error('provider_id', 'provider_id_required');
        }

        if (! $creating) {
            if ($p->locale !== null) {
                $key = $p->providerId.'|'.$p->locale;
                if (isset($state->seen[$key])) {
                    $p->error('provider_id', 'duplicate_row', ['first_row' => $state->seen[$key]]);
                } else {
                    $state->seen[$key] = $p->rowNumber;
                }
            }
            $provider = $existing->get($p->providerId);
            if ($provider === null) {
                $p->error('provider_id', 'unknown_provider_id');
            } elseif ($provider->trashed()) {
                $p->error('provider_id', 'provider_trashed');
                $provider = null;
            }
        }

        foreach ($this->rules->check($p->values) as $e) {
            $p->error($e['field'], $e['code'], $e['params']);
        }

        $type = null;
        if ($p->typeKey !== null) {
            $type = $typeMap[mb_strtolower($p->typeKey)] ?? null;
            if ($type === null) {
                $p->error('provider_type', 'unknown_provider_type');
            } elseif (! $type['active'] && ($creating || ($provider && $provider->provider_type_id !== $type['id']))) {
                $p->error('provider_type', 'provider_type_inactive');
            }
        }

        if ($creating) {
            if ($p->typeKey === null) {
                $p->error('provider_type', 'provider_type_required');
            }
            foreach (['name', 'address'] as $required) {
                if ($p->value($required) === null) {
                    $p->error($required, $required.'_required');
                }
            }
        } elseif ($provider !== null && $p->locale !== null && $p->hasTranslationContent()) {
            $stored = $provider->translation($p->locale);
            foreach (['name', 'address'] as $required) {
                if ($p->value($required) === null && ! ($stored && filled($stored->{$required}))) {
                    $p->error($required, $required.'_required');
                }
            }
        }

        if ($creating && $p->errors === [] && $p->locale !== null) {
            $identity = sha1(implode('|', [
                mb_strtolower((string) $p->typeKey), $p->locale,
                mb_strtolower((string) $p->value('name')), mb_strtolower((string) $p->value('address')),
            ]));
            if (isset($state->createSeen[$identity])) {
                $p->error(null, 'duplicate_create_row', ['first_row' => $state->createSeen[$identity]]);
            } else {
                $state->createSeen[$identity] = $p->rowNumber;
            }
        }

        $diff = [];
        $baseRevision = null;
        $planned = null;
        if ($p->errors === []) {
            $planned = $provider !== null ? ($state->planned[$provider->id] ?? ['bumps' => 0, 'values' => []]) : null;
            $diff = $creating
                ? $this->createDiff($p, $type, $typeKeyById)
                : $this->updateDiff($p, $provider, $type, $planned['values'] ?? [], $typeKeyById);
        }

        foreach ($p->warnings as $warning) {
            $state->warn($warning, $p->rowNumber);
        }

        if ($p->errors !== []) {
            $state->errorRows++;
            foreach ($p->errors as $e) {
                $state->errorCodes[$e['code']] = ($state->errorCodes[$e['code']] ?? 0) + 1;
            }

            return [
                'action' => ImportRow::ACTION_ERROR,
                'provider_id' => null,
                'base_revision' => null,
                'payload' => $this->json(['provider_id' => $p->providerId, 'locale' => $p->locale, 'type' => $p->typeKey, 'values' => $p->values]),
                'diff' => null,
                'errors' => $this->json($p->errors),
                'apply_status' => ImportRow::APPLY_SKIPPED,
            ];
        }

        $payload = $this->payload($p, $type);

        if ($creating) {
            $state->creates++;
            $creates[] = ['row' => $p->rowNumber, 'locale' => (string) $p->locale, 'name' => (string) $p->value('name'), 'address' => (string) $p->value('address')];

            return [
                'action' => ImportRow::ACTION_CREATE,
                'provider_id' => null,
                'base_revision' => null,
                'payload' => $this->json($payload),
                'diff' => $this->json($diff),
                'errors' => null,
                'apply_status' => ImportRow::APPLY_PENDING,
            ];
        }

        \assert($provider !== null && $planned !== null);
        $baseRevision = $provider->revision + $planned['bumps'];

        if ($diff === []) {
            $state->unchanged++;

            return [
                'action' => ImportRow::ACTION_UNCHANGED,
                'provider_id' => $provider->id,
                'base_revision' => $baseRevision,
                'payload' => $this->json($payload),
                'diff' => null,
                'errors' => null,
                'apply_status' => ImportRow::APPLY_SKIPPED,
            ];
        }

        $state->updates++;
        $planned['bumps']++;
        foreach ($this->plannedValues($payload) as $field => $value) {
            $planned['values'][$field] = $value;
        }
        $state->planned[$provider->id] = $planned;

        return [
            'action' => ImportRow::ACTION_UPDATE,
            'provider_id' => $provider->id,
            'base_revision' => $baseRevision,
            'payload' => $this->json($payload),
            'diff' => $this->json($diff),
            'errors' => null,
            'apply_status' => ImportRow::APPLY_PENDING,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> provider-level values this row will write (for sibling-row consistency)
     */
    private function plannedValues(array $payload): array
    {
        $values = $payload['scalars'] ?? [];
        if (isset($payload['provider_type_id'])) {
            $values['provider_type_id'] = $payload['provider_type_id'];
        }
        if (array_key_exists('is_active', $payload)) {
            $values['is_active'] = $payload['is_active'];
        }

        return $values;
    }

    /**
     * @param  array{id: int, key: string, active: bool}|null  $type
     * @return array<string, mixed>
     */
    private function payload(ParsedRow $p, ?array $type): array
    {
        $payload = ['provider_id' => $p->providerId, 'locale' => $p->locale];
        if ($type !== null) {
            $payload['provider_type_id'] = $type['id'];
        }
        foreach (TemplateSchema::SCALAR_FIELDS as $field) {
            if ($p->value($field) !== null) {
                $payload['scalars'][$field] = $p->value($field);
            }
        }
        if ($p->isActive !== null) {
            $payload['is_active'] = $p->isActive;
        }
        foreach (TemplateSchema::translationFields() as $field) {
            if ($p->value($field) !== null) {
                $payload['translation'][$field] = $p->value($field);
            }
        }

        return $payload;
    }

    /**
     * @param  array{id: int, key: string, active: bool}|null  $type
     * @param  array<int, string>  $typeKeyById
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function createDiff(ParsedRow $p, ?array $type, array $typeKeyById): array
    {
        $diff = [];
        if ($type !== null) {
            $diff['provider_type'] = ['old' => null, 'new' => $type['key']];
        }
        foreach (TemplateSchema::SCALAR_FIELDS as $field) {
            if ($p->value($field) !== null) {
                $diff[$field] = ['old' => null, 'new' => $p->value($field)];
            }
        }
        if ($p->isActive !== null) {
            $diff['is_active'] = ['old' => null, 'new' => $p->isActive ? '1' : '0'];
        }
        foreach (TemplateSchema::translationFields() as $field) {
            if ($p->value($field) !== null) {
                $diff["{$p->locale}.$field"] = ['old' => null, 'new' => $p->value($field)];
            }
        }

        return $diff;
    }

    /**
     * @param  array{id: int, key: string, active: bool}|null  $type
     * @param  array<string, mixed>  $plannedValues  values earlier rows of this file already set on the provider
     * @param  array<int, string>  $typeKeyById
     * @return array<string, array{old: mixed, new: mixed}>
     */
    private function updateDiff(ParsedRow $p, ?Provider $provider, ?array $type, array $plannedValues, array $typeKeyById): array
    {
        if ($provider === null || $p->locale === null) {
            return [];
        }

        $diff = [];
        $current = function (string $field) use ($provider, $plannedValues): mixed {
            if (array_key_exists($field, $plannedValues)) {
                return $plannedValues[$field];
            }
            $value = $provider->getAttribute($field);

            return match ($field) {
                'latitude', 'longitude' => $value === null ? null : number_format((float) $value, 7, '.', ''),
                default => $value,
            };
        };
        $consider = function (string $field, mixed $new, mixed $display) use (&$diff, $current, $plannedValues, $p): void {
            $old = $current($field);
            if (array_key_exists($field, $plannedValues)) {
                if ($plannedValues[$field] !== $new) {
                    $p->error($field === 'provider_type_id' ? 'provider_type' : $field, 'conflicting_values');
                }

                return; // already planned by an earlier row of this provider; not a second change
            }
            if ($this->same($old, $new)) {
                return;
            }
            $diff[$field === 'provider_type_id' ? 'provider_type' : $field] = ['old' => $display['old'] ?? $old, 'new' => $display['new'] ?? $new];
        };

        foreach (TemplateSchema::SCALAR_FIELDS as $field) {
            $new = $p->value($field);
            if ($new !== null) {
                $consider($field, $new, []);
            }
        }
        if ($type !== null) {
            $consider('provider_type_id', $type['id'], ['old' => $typeKeyById[$provider->provider_type_id] ?? null, 'new' => $type['key']]);
        }
        if ($p->isActive !== null) {
            $consider('is_active', $p->isActive, ['old' => $provider->is_active ? '1' : '0', 'new' => $p->isActive ? '1' : '0']);
        }

        $stored = $provider->translation($p->locale);
        foreach (TemplateSchema::translationFields() as $field) {
            $new = $p->value($field);
            if ($new === null) {
                continue;
            }
            $old = $stored?->{$field};
            if (! $this->same($old, $new)) {
                $diff["{$p->locale}.$field"] = ['old' => $old, 'new' => $new];
            }
        }

        return $diff;
    }

    private function same(mixed $old, mixed $new): bool
    {
        if (is_bool($new) || is_bool($old)) {
            return (bool) $old === (bool) $new;
        }
        if (is_int($new)) {
            return (int) $old === $new;
        }
        $normalize = fn (mixed $v): ?string => $v === null ? null : str_replace(["\r\n", "\r"], "\n", (string) $v);

        return $normalize($old) === $normalize($new);
    }

    /**
     * Possible duplicates of EXISTING providers (same locale + name + address, case-insensitive).
     * Reported as a warning only: nothing is ever matched or merged by name (ADR-009).
     *
     * @param  list<array{row: int, locale: string, name: string, address: string}>  $creates
     */
    private function flagPossibleDuplicates(array $creates, ValidationState $state): void
    {
        $byLocale = [];
        foreach ($creates as $c) {
            $byLocale[$c['locale']][] = $c;
        }
        foreach ($byLocale as $locale => $items) {
            $names = array_values(array_unique(array_column($items, 'name')));
            $found = [];
            foreach (ProviderTranslation::query()->where('locale', $locale)->whereIn('name', $names)->whereHas('provider')->get(['name', 'address']) as $t) {
                $found[mb_strtolower($t->name).'|'.mb_strtolower($t->address)] = true;
            }
            foreach ($items as $c) {
                if (isset($found[mb_strtolower($c['name']).'|'.mb_strtolower($c['address'])])) {
                    $state->warn('possible_duplicate_existing', $c['row']);
                }
            }
        }
    }

    // ------------------------------------------------------------------ completion

    private function stillValidating(ImportJob $job): bool
    {
        return ImportJob::query()->whereKey($job->id)->value('status') === ImportJob::STATUS_VALIDATING;
    }

    private function discardStaged(ImportJob $job): void
    {
        ImportRow::query()->where('import_job_id', $job->id)->delete();
    }

    /** @param list<array{code: string, params?: array<string, scalar>}> $fileErrors */
    private function finish(ImportJob $job, ValidationState $state, array $fileErrors): void
    {
        $failed = $fileErrors !== [] || $state->errorRows > 0;
        $fileLevel = $fileErrors !== [];

        $summary = [
            'file_errors' => $fileErrors,
            'error_codes' => $state->errorCodes,
            'warnings' => $state->warnings,
            'unknown_columns' => array_slice($state->unknownColumns, 0, 20),
        ];

        $updated = ImportJob::query()->whereKey($job->id)->where('status', ImportJob::STATUS_VALIDATING)->update([
            'status' => $failed ? ImportJob::STATUS_VALIDATION_FAILED : ImportJob::STATUS_READY,
            'template_version' => TemplateSchema::VERSION,
            'total_rows' => $fileLevel ? 0 : $state->total,
            'insert_count' => $fileLevel ? 0 : $state->creates,
            'update_count' => $fileLevel ? 0 : $state->updates,
            'unchanged_count' => $fileLevel ? 0 : $state->unchanged,
            'error_count' => $fileLevel ? 0 : $state->errorRows,
            'error_summary' => $this->json($summary),
            'failure_reason' => $failed ? ($fileLevel ? $fileErrors[0]['code'] : 'row_errors') : null,
            'finished_at' => $failed ? now() : null,
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            // cancelled while validating: drop anything staged, leave the status alone
            $this->discardStaged($job);

            return;
        }

        if ($failed) {
            $fresh = ImportJob::query()->findOrFail($job->id);
            $report = $this->reports->write($fresh);
            if ($report !== null) {
                $fresh->forceFill(['error_report_path' => $report])->save();
            }
        }

        Log::info('import.validation.finished', [
            'job' => $job->uuid, 'rows' => $state->total, 'inserts' => $state->creates, 'updates' => $state->updates,
            'unchanged' => $state->unchanged, 'errors' => $state->errorRows, 'file_errors' => count($fileErrors),
        ]);
    }

    /** @param array<array-key, mixed> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
