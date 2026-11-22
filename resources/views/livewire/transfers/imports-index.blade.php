<div class="mx-auto max-w-5xl space-y-6">
    <header class="space-y-2">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('transfers.imports.title') }}</h1>
        <p class="text-sm text-slate-700">{{ __('transfers.imports.intro') }}</p>
        <p class="flex flex-wrap gap-4 text-sm">
            @if ($canUpload)
                <a href="{{ route('imports.template') }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.imports.template') }}</a>
            @endif
            <a href="{{ route('jobs.index') }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.jobs.title') }}</a>
        </p>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>
    @endif

    @if ($canUpload)
        <form method="POST" action="{{ route('imports.store') }}" enctype="multipart/form-data" class="space-y-5 rounded-lg border border-slate-200 bg-white p-4 sm:p-6">
            @csrf

            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-slate-900">{{ __('transfers.imports.mode') }}</legend>
                @foreach ($modes as $mode)
                    <label class="flex items-start gap-3 rounded-md border border-slate-200 p-3 has-[:checked]:border-teal-600 has-[:checked]:bg-teal-50">
                        <input type="radio" name="mode" value="{{ $mode }}" @checked(old('mode', 'dry_run') === $mode) required class="mt-1 size-4 border-slate-400 text-teal-700 focus:ring-teal-600">
                        <span class="min-w-0">
                            <span class="block text-sm font-medium text-slate-900">{{ __('transfers.modes.'.$mode) }}</span>
                            <span class="block text-sm text-slate-700">{{ __('transfers.mode_help.'.$mode) }}</span>
                        </span>
                    </label>
                @endforeach
                @error('mode')
                    <p class="text-sm font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="space-y-2">
                <label for="import-file" class="block text-sm font-semibold text-slate-900">{{ __('transfers.imports.file') }}</label>
                <input id="import-file" type="file" name="file" required
                       accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                       aria-describedby="import-file-hint"
                       class="block w-full text-sm text-slate-900 file:me-4 file:rounded-md file:border-0 file:bg-slate-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-slate-900 hover:file:bg-slate-200">
                <p id="import-file-hint" class="text-sm text-slate-700">{{ __('transfers.imports.file_hint', ['mb' => $maxMb, 'rows' => $maxRows]) }}</p>
                @error('file')
                    <p class="text-sm font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <ul class="list-disc space-y-1 ps-5 text-sm text-slate-700">
                <li>{{ __('transfers.imports.blank_hint') }}</li>
                <li>{{ __('transfers.imports.one_row_hint') }}</li>
            </ul>

            <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">
                {{ __('transfers.imports.upload') }}
            </button>
        </form>
    @endif

    <section aria-labelledby="import-history" class="space-y-3">
        <h2 id="import-history" class="text-lg font-semibold text-slate-900">{{ __('transfers.imports.history') }}</h2>

        @if ($jobs->isEmpty())
            <p class="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-700">{{ __('transfers.imports.empty') }}</p>
        @else
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="min-w-full text-start text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.file_name') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.mode_label') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.status_label') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.rows') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.created') }}</th>
                            <th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('transfers.imports.open') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($jobs as $job)
                            <tr>
                                <td class="max-w-xs truncate px-3 py-2" dir="auto">{{ $job->original_filename }}</td>
                                <td class="px-3 py-2">{{ __('transfers.modes.'.$job->mode) }}</td>
                                <td class="px-3 py-2">{{ __('transfers.status.'.$job->status) }}</td>
                                <td class="px-3 py-2">{{ $job->total_rows }}</td>
                                <td class="px-3 py-2"><time datetime="{{ $job->created_at?->toIso8601String() }}">{{ $job->created_at?->format('Y-m-d H:i') }}</time></td>
                                <td class="px-3 py-2 text-end"><a href="{{ route('imports.show', $job) }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.imports.open') }}</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $jobs->links() }}
        @endif
    </section>
</div>
