@php
    $statusClasses = [
        'ready' => 'bg-emerald-100 text-emerald-900',
        'completed' => 'bg-emerald-100 text-emerald-900',
        'validation_failed' => 'bg-red-100 text-red-900',
        'failed' => 'bg-red-100 text-red-900',
        'conflict' => 'bg-amber-100 text-amber-900',
        'interrupted' => 'bg-amber-100 text-amber-900',
        'cancelled' => 'bg-slate-200 text-slate-800',
    ];
    $badge = $statusClasses[$job->status] ?? 'bg-sky-100 text-sky-900';
    $fieldLabel = function (string $key): string {
        if (str_contains($key, '.')) {
            [$loc, $field] = explode('.', $key, 2);

            return __('transfers.fields.'.$field).' ('.strtoupper($loc).')';
        }

        return __('transfers.fields.'.$key);
    };
    $warningTotal = collect($warnings)->sum('count');
@endphp
<div class="mx-auto max-w-6xl space-y-6" @if ($polling) wire:poll.3s @endif>
    <header class="space-y-2">
        <p class="text-sm"><a href="{{ route('imports.index') }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.imports.title') }}</a></p>
        <h1 class="flex flex-wrap items-center gap-3 text-2xl font-semibold text-slate-900">
            <span dir="auto" class="break-all">{{ $job->original_filename }}</span>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium {{ $badge }}">{{ __('transfers.status.'.$job->status) }}</span>
        </h1>
        <dl class="flex flex-wrap gap-x-8 gap-y-1 text-sm text-slate-700">
            <div class="flex gap-2"><dt class="font-medium">{{ __('transfers.imports.mode_label') }}:</dt><dd>{{ __('transfers.modes.'.$job->mode) }}</dd></div>
            <div class="flex gap-2"><dt class="font-medium">{{ __('transfers.imports.created') }}:</dt><dd><time datetime="{{ $job->created_at?->toIso8601String() }}">{{ $job->created_at?->format('Y-m-d H:i') }}</time></dd></div>
        </dl>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>
    @endif
    @if ($errors->has('import'))
        <div role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ $errors->first('import') }}</div>
    @endif

    @switch($job->status)
        @case('uploaded')
        @case('validating')
            <p role="status" class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">{{ __('transfers.imports.validating_note') }}</p>
            @break
        @case('applying')
            <p role="status" class="rounded-md border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900">{{ __('transfers.imports.applying_note') }}</p>
            @break
        @case('validation_failed')
            <p role="alert" class="rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ __('transfers.imports.blocked_note') }}</p>
            @break
        @case('ready')
            <p class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                {{ $job->mode === 'dry_run' ? __('transfers.imports.dry_run_note') : __('transfers.imports.ready_note') }}
            </p>
            @break
        @case('conflict')
            <p role="alert" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('transfers.imports.conflict_note') }}</p>
            @break
        @case('interrupted')
            <p role="alert" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{{ __('transfers.imports.interrupted_note') }}</p>
            @break
    @endswitch

    @if ($partial)
        <p role="alert" class="rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ __('transfers.imports.partial_note', ['applied' => $job->applied_count, 'total' => $job->insert_count + $job->update_count]) }}
        </p>
    @endif

    @if ($job->failure_reason && in_array($job->status, ['failed', 'interrupted'], true))
        <p class="text-sm text-slate-700"><span class="font-medium">{{ __('transfers.imports.message') }}:</span> <code>{{ $job->failure_reason }}</code></p>
    @endif

    <section aria-labelledby="counts-title" class="space-y-3">
        <h2 id="counts-title" class="text-lg font-semibold text-slate-900">{{ __('transfers.imports.counts') }}</h2>
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-7">
            @foreach ([
                ['total', $job->total_rows], ['inserts', $job->insert_count], ['updates', $job->update_count],
                ['unchanged', $job->unchanged_count], ['errors', $job->error_count], ['warnings', $warningTotal], ['applied', $job->applied_count],
            ] as [$key, $value])
                <div class="rounded-lg border border-slate-200 bg-white p-3">
                    <dt class="text-xs font-medium text-slate-700">{{ __('transfers.imports.'.$key) }}</dt>
                    <dd class="text-2xl font-semibold text-slate-900">{{ number_format($value) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    @if ($canManage)
        <div class="flex flex-wrap items-start gap-3">
            @if ($canConfirm)
                <form method="POST" action="{{ route('imports.confirm', $job) }}">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">{{ __('transfers.imports.confirm') }}</button>
                </form>
            @endif
            @if ($canRetry)
                <form method="POST" action="{{ route('imports.retry', $job) }}">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-600 focus-visible:ring-offset-2">{{ __('transfers.imports.retry') }}</button>
                </form>
            @endif
            @if ($canCancel)
                <form method="POST" action="{{ route('imports.cancel', $job) }}" class="space-y-1">
                    @csrf
                    <button type="submit" class="inline-flex min-h-10 items-center rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-900 hover:bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-500 focus-visible:ring-offset-2">{{ __('transfers.imports.cancel') }}</button>
                    @if ($partial || in_array($job->status, ['interrupted', 'conflict'], true))
                        <p class="max-w-xs text-xs text-slate-700">{{ __('transfers.imports.cancel_partial') }}</p>
                    @endif
                </form>
            @endif
        </div>
    @endif

    @if ($job->error_report_path)
        <p><a href="{{ route('imports.error-report', $job) }}" class="inline-flex min-h-10 items-center rounded-md border border-red-300 bg-white px-4 py-2 text-sm font-semibold text-red-900 hover:bg-red-50">{{ __('transfers.imports.error_report') }}</a></p>
    @endif

    @if ($fileErrors !== [])
        <section aria-labelledby="file-errors-title" class="space-y-2">
            <h2 id="file-errors-title" class="text-lg font-semibold text-red-900">{{ __('transfers.imports.file_errors') }}</h2>
            <ul class="list-disc space-y-1 ps-5 text-sm text-red-900">
                @foreach ($fileErrors as $message)
                    <li dir="auto">{{ $message }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($warnings !== [])
        <section aria-labelledby="warnings-title" class="space-y-2">
            <h2 id="warnings-title" class="text-lg font-semibold text-slate-900">{{ __('transfers.imports.warning_list') }}</h2>
            <ul class="list-disc space-y-1 ps-5 text-sm text-slate-800">
                @foreach ($warnings as $warning)
                    <li>
                        {{ $warning['message'] }}
                        @if ($warning['count'])<span class="font-medium">({{ $warning['count'] }})</span>@endif
                        @if ($warning['rows'] !== [])<span class="text-slate-600">{{ __('transfers.imports.row') }}: {{ implode(', ', $warning['rows']) }}</span>@endif
                    </li>
                @endforeach
            </ul>
            @if ($unknownColumns !== [])
                <p class="text-sm text-slate-700">{{ __('transfers.imports.unknown_columns') }}: <code dir="ltr">{{ implode(', ', $unknownColumns) }}</code></p>
            @endif
        </section>
    @endif

    @if ($job->error_count > 0)
        <section aria-labelledby="errors-title" class="space-y-3">
            <h2 id="errors-title" class="text-lg font-semibold text-slate-900">{{ __('transfers.imports.error_list') }}</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="min-w-full text-start text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.row') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.field') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.message') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($errorRows as $row)
                            @foreach ((array) $row->errors as $error)
                                <tr>
                                    <td class="px-3 py-2">{{ $loop->first ? $row->row_number : '' }}</td>
                                    <td class="px-3 py-2">{{ ! empty($error['field']) ? $fieldLabel($error['field']) : '' }}</td>
                                    <td class="px-3 py-2" dir="auto">{{ \App\Services\Transfers\ErrorMessages::row($error) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $errorRows->links() }}
        </section>
    @endif

    @if ($diffRows->total() > 0)
        <section aria-labelledby="diffs-title" class="space-y-3">
            <h2 id="diffs-title" class="text-lg font-semibold text-slate-900">{{ __('transfers.imports.changes') }}</h2>
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="min-w-full text-start text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.row') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.action') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.provider') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.change') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 align-top">
                        @foreach ($diffRows as $row)
                            <tr>
                                <td class="px-3 py-2">{{ $row->row_number }}</td>
                                <td class="px-3 py-2">{{ __('transfers.imports.actions.'.$row->action) }}</td>
                                <td class="px-3 py-2">{{ $row->provider_id ?? '-' }}</td>
                                <td class="px-3 py-2">
                                    <ul class="space-y-1">
                                        @foreach ((array) $row->diff as $key => $change)
                                            <li>
                                                <span class="font-medium">{{ $fieldLabel((string) $key) }}:</span>
                                                @if (($change['old'] ?? null) !== null)
                                                    <span class="text-slate-600">{{ __('transfers.imports.old') }}</span>
                                                    <span dir="auto" class="whitespace-pre-wrap break-words rounded bg-red-50 px-1">{{ \Illuminate\Support\Str::limit((string) $change['old'], 160) }}</span>
                                                @endif
                                                <span class="text-slate-600">{{ __('transfers.imports.new') }}</span>
                                                <span dir="auto" class="whitespace-pre-wrap break-words rounded bg-emerald-50 px-1">{{ \Illuminate\Support\Str::limit((string) ($change['new'] ?? ''), 160) }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $diffRows->links() }}
        </section>
    @endif
</div>
