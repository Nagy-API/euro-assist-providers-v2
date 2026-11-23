<?php

namespace App\Services\Transfers;

use App\Enums\Permission;
use App\Models\ExportJob;
use App\Models\Provider;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;

/**
 * Streams an export to a private temp file with bounded memory: providers are read in id order in
 * chunks (lazyById / id chunks) and written row by row through OpenSpout's streaming writer. The file
 * is renamed into place only when complete, then hashed. One row per (provider, requested locale).
 */
class ExportGenerator
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function generate(ExportJob $job): void
    {
        $actor = User::query()->find($job->user_id);
        if ($actor === null || ! Gate::forUser($actor)->allows(Permission::ExportsRun->value)) {
            throw new RuntimeException('actor_not_authorized');
        }
        $isAdmin = $actor->isAdmin();

        $cfg = config('providers.exports');
        $disk = (string) $cfg['disk'];
        $chunk = max(1, (int) $cfg['chunk_size']);
        $locale = $job->locale;

        $base = $this->query($job, $isAdmin);
        $total = (clone $base)->count();
        $job->forceFill(['total_rows' => $total])->save();

        $matching = (clone $base)->whereHas('translations', fn (Builder $t) => $t->where('locale', $locale));

        $relative = PrivateFiles::newPath((string) $cfg['directory']);
        $absolute = PrivateFiles::absolute($disk, $relative, ensureDirectory: true);
        $partial = $absolute.'.part';

        $writer = new Writer(new Options);
        $writer->openToFile($partial);
        $written = 0;
        $success = false;

        try {
            $writer->getCurrentSheet()->setName('providers');
            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(fn (string $h) => new StringCell($h, $bold), TemplateSchema::COLUMNS)));

            $with = ['type:id,key', 'translations' => fn ($t) => $t->where('locale', $locale)];

            if ($job->scope === ExportJob::SCOPE_SELECTED) {
                foreach (array_chunk((array) $job->selected_ids, $chunk) as $ids) {
                    $providers = (clone $matching)->with($with)->whereIn('id', $ids)->orderBy('id')->get();
                    foreach ($providers as $provider) {
                        $writer->addRow($this->row($provider, $locale));
                        $written++;
                    }
                    $this->progress($job, $written);
                }
            } else {
                $inChunk = 0;
                foreach ((clone $matching)->with($with)->lazyById($chunk, 'id') as $provider) {
                    $writer->addRow($this->row($provider, $locale));
                    $written++;
                    if (++$inChunk >= $chunk) {
                        $inChunk = 0;
                        $this->progress($job, $written);
                    }
                }
            }
            $success = true;
        } finally {
            $writer->close();
            if (! $success && is_file($partial)) {
                @unlink($partial);
            }
        }

        if (! rename($partial, $absolute)) {
            @unlink($partial);
            throw new RuntimeException('rename_failed');
        }

        $job->forceFill([
            'status' => ExportJob::STATUS_COMPLETED,
            'written_rows' => $written,
            'file_path' => $relative,
            'file_sha256' => (string) hash_file('sha256', $absolute),
            'finished_at' => now(),
            'expires_at' => now()->addHours((int) $cfg['retention_hours']),
            'failure_reason' => null,
        ])->save();

        Log::info('export.completed', ['job' => $job->uuid, 'rows' => $written, 'matching' => $total]);
    }

    public function markFailed(ExportJob $job, ?\Throwable $e): void
    {
        $fresh = ExportJob::query()->find($job->id);
        if ($fresh === null || in_array($fresh->status, [ExportJob::STATUS_COMPLETED, ExportJob::STATUS_EXPIRED], true)) {
            return;
        }
        $fresh->forceFill([
            'status' => ExportJob::STATUS_FAILED,
            'failure_reason' => $e !== null ? mb_substr(class_basename($e), 0, 100) : 'export_failed',
            'finished_at' => now(),
        ])->save();
        $this->audit->record('export.failed', $fresh, 'failure', ['reason' => $fresh->failure_reason], $fresh->user);
        Log::error('export.failed', ['job' => $fresh->uuid, 'class' => $e !== null ? $e::class : null]);
    }

    /** @return Builder<Provider> */
    private function query(ExportJob $job, bool $isAdmin): Builder
    {
        $query = Provider::query();

        if ($job->scope === ExportJob::SCOPE_SELECTED) {
            $query->whereIn('id', (array) $job->selected_ids);
            if ($isAdmin) {
                $query->withTrashed()->whereNull('merged_into_id'); // never export merge shells (ADR-017)
            }
        } elseif ($job->scope === ExportJob::SCOPE_FILTERED) {
            ExportFilters::apply($query, (array) $job->filters, $isAdmin);
        }

        return $query;
    }

    private function progress(ExportJob $job, int $written): void
    {
        ExportJob::query()->whereKey($job->id)->update(['written_rows' => $written, 'updated_at' => now()]);
    }

    private function row(Provider $provider, string $locale): Row
    {
        $t = $provider->translation($locale);

        return new Row([
            Cells::number((int) TemplateSchema::VERSION),
            Cells::number((int) $provider->id),
            Cells::text($locale),
            Cells::text($provider->type?->key),
            Cells::text($provider->phone),
            Cells::text($provider->email),
            Cells::text($provider->website),
            Cells::text($provider->map_url),
            $provider->latitude === null ? Cells::text(null) : Cells::number((float) $provider->latitude),
            $provider->longitude === null ? Cells::text(null) : Cells::number((float) $provider->longitude),
            Cells::number($provider->is_active ? 1 : 0),
            Cells::text($t?->name),
            Cells::text($t?->address),
            Cells::text($t?->city),
            Cells::text($t?->district),
            Cells::text($t?->specialization),
            Cells::text($t?->hours),
            Cells::text($t?->discount_text),
            Cells::text($t?->package_text),
        ]);
    }
}
