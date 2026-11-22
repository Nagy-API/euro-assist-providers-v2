<?php

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\ImportJob;
use App\Services\AuditLogger;
use App\Services\Transfers\ImportActionRejected;
use App\Services\Transfers\ImportJobService;
use App\Services\Transfers\JobAccess;
use App\Services\Transfers\TemplateSchema;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function __construct(private readonly ImportJobService $jobs, private readonly AuditLogger $audit) {}

    /** POST /imports - multipart upload (CSRF protected by the web group). */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        JobAccess::create($user, ImportJob::class);

        $request->validate([
            'mode' => ['required', 'string', 'in:'.implode(',', ImportJob::MODES)],
            'file' => ['required', 'file'],
        ]);

        $job = $this->jobs->upload($user, $request->file('file'), (string) $request->input('mode'));

        return redirect()->route('imports.show', $job);
    }

    public function confirm(Request $request, ImportJob $importJob): RedirectResponse
    {
        JobAccess::manage($request->user(), $importJob);

        return $this->act(fn () => $this->jobs->confirm($request->user(), $importJob), $importJob, 'transfers.flash.confirmed');
    }

    public function cancel(Request $request, ImportJob $importJob): RedirectResponse
    {
        JobAccess::manage($request->user(), $importJob);

        return $this->act(fn () => $this->jobs->cancel($request->user(), $importJob), $importJob, 'transfers.flash.cancelled');
    }

    public function retry(Request $request, ImportJob $importJob): RedirectResponse
    {
        JobAccess::manage($request->user(), $importJob);

        return $this->act(fn () => $this->jobs->retry($request->user(), $importJob), $importJob, 'transfers.flash.retried');
    }

    /** GET /imports/{importJob}/errors - private error report, owner or Admin only. */
    public function errorReport(Request $request, ImportJob $importJob): Response
    {
        JobAccess::view($request->user(), $importJob);

        $disk = (string) config('providers.imports.disk');
        $path = $importJob->error_report_path;
        if (! $path || ! Storage::disk($disk)->exists($path)) {
            abort(404);
        }

        $this->audit->record('import.error_report_downloaded', $importJob, 'success', [], $request->user());

        return Storage::disk($disk)->download($path, 'import-errors-'.substr($importJob->uuid, 0, 8).'.xlsx', $this->headers());
    }

    /** GET /imports/template - header-only v1 template. */
    public function template(Request $request): StreamedResponse
    {
        JobAccess::create($request->user(), ImportJob::class);

        return response()->streamDownload(function (): void {
            $tmp = (string) tempnam(sys_get_temp_dir(), 'tpl');
            $writer = new Writer;
            $writer->openToFile($tmp);
            $bold = new Style(fontBold: true);
            $writer->addRow(new Row(array_map(fn (string $h) => new StringCell($h, $bold), TemplateSchema::COLUMNS)));
            $writer->close();
            readfile($tmp);
            @unlink($tmp);
        }, 'providers-import-template-v'.TemplateSchema::VERSION.'.xlsx', $this->headers());
    }

    /** @param callable(): mixed $action */
    private function act(callable $action, ImportJob $job, string $successKey): RedirectResponse
    {
        try {
            $action();
        } catch (ImportActionRejected $e) {
            return redirect()->route('imports.show', $job)->withErrors(['import' => $e->userMessage()]);
        }

        return redirect()->route('imports.show', $job)->with('status', __($successKey));
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ];
    }
}
