<?php

namespace App\Http\Controllers\Transfers;

use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use App\Services\AuditLogger;
use App\Services\Transfers\ExportService;
use App\Services\Transfers\JobAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function __construct(private readonly ExportService $exports, private readonly AuditLogger $audit) {}

    /** POST /exports - request an export (all / filtered / selected ids). */
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        JobAccess::create($user, ExportJob::class);

        $data = $request->validate([
            'scope' => ['required', 'string', 'in:all,filtered,selected'],
            'locale' => ['required', 'string', 'in:'.implode(',', config('providers.locales'))],
            'filters' => ['nullable', 'array'],
            'filters.search' => ['nullable', 'string', 'max:255'],
            'filters.type' => ['nullable', 'integer', 'min:1'],
            'filters.city' => ['nullable', 'string', 'max:255'],
            'filters.district' => ['nullable', 'string', 'max:255'],
            'filters.status' => ['nullable', 'string', 'in:active,inactive'],
            'filters.trashed' => ['nullable', 'string', 'in:with,only'],
            'selected_ids' => ['nullable', 'string', 'max:200000'],
        ]);

        $ids = [];
        if ($data['scope'] === 'selected') {
            $ids = array_map('intval', preg_split('/[\s,;]+/', (string) ($data['selected_ids'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: []);
        }

        $this->exports->dispatch($user, $data['scope'], $data['locale'], (array) ($data['filters'] ?? []), $ids);

        return redirect()->route('exports.index')->with('status', __('transfers.flash.export_requested'));
    }

    /** GET /exports/{exportJob}/download - authenticated owner/Admin download of the private file. */
    public function download(Request $request, ExportJob $exportJob): Response
    {
        JobAccess::view($request->user(), $exportJob);

        $disk = (string) config('providers.exports.disk');
        $expired = $exportJob->status === ExportJob::STATUS_EXPIRED
            || ($exportJob->expires_at !== null && $exportJob->expires_at->isPast());

        if ($exportJob->status === ExportJob::STATUS_COMPLETED && $exportJob->file_path) {
            if ($expired || ! Storage::disk($disk)->exists($exportJob->file_path)) {
                abort(410);
            }
        } elseif ($exportJob->status === ExportJob::STATUS_EXPIRED) {
            abort(410);
        } else {
            abort(404); // queued, running or failed: there is no file to download
        }

        $this->audit->record('export.downloaded', $exportJob, 'success', ['scope' => $exportJob->scope, 'locale' => $exportJob->locale], $request->user());

        $name = sprintf('providers-%s-%s.xlsx', $exportJob->locale, $exportJob->created_at?->format('Ymd-His') ?? 'export');

        return Storage::disk($disk)->download((string) $exportJob->file_path, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
    }
}
