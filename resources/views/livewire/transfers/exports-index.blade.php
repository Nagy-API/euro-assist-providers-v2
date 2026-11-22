<div class="mx-auto max-w-5xl space-y-6">
    <header class="space-y-2">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('transfers.exports.title') }}</h1>
        <p class="text-sm text-slate-700">{{ __('transfers.exports.intro') }}</p>
        <p class="text-sm"><a href="{{ route('jobs.index') }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.jobs.title') }}</a></p>
    </header>

    @if (session('status'))
        <div role="status" class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{{ session('status') }}</div>
    @endif

    @if ($canExport)
        <form method="POST" action="{{ route('exports.store') }}" class="space-y-5 rounded-lg border border-slate-200 bg-white p-4 sm:p-6">
            @csrf

            <fieldset class="space-y-2">
                <legend class="text-sm font-semibold text-slate-900">{{ __('transfers.exports.scope') }}</legend>
                @foreach (['all', 'filtered', 'selected'] as $scope)
                    <label class="flex items-center gap-3 text-sm text-slate-900">
                        <input type="radio" name="scope" value="{{ $scope }}" @checked(old('scope', 'all') === $scope) required class="size-4 border-slate-400 text-teal-700 focus:ring-teal-600">
                        {{ __('transfers.exports.scope_'.$scope) }}
                    </label>
                @endforeach
                @error('scope')
                    <p class="text-sm font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </fieldset>

            <div class="space-y-1">
                <label for="export-locale" class="block text-sm font-semibold text-slate-900">{{ __('transfers.exports.locale') }}</label>
                <select id="export-locale" name="locale" required aria-describedby="export-locale-hint" class="block w-full max-w-xs rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:ring-teal-600">
                    @foreach (config('providers.locales') as $loc)
                        <option value="{{ $loc }}" @selected(old('locale', app()->getLocale()) === $loc)>{{ strtoupper($loc) }}</option>
                    @endforeach
                </select>
                <p id="export-locale-hint" class="text-sm text-slate-700">{{ __('transfers.exports.locale_hint') }}</p>
                @error('locale')
                    <p class="text-sm font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-semibold text-slate-900">{{ __('transfers.exports.filters') }}</legend>
                <div class="space-y-1">
                    <label for="f-search" class="block text-sm text-slate-900">{{ __('transfers.exports.search') }}</label>
                    <input id="f-search" type="text" name="filters[search]" value="{{ old('filters.search') }}" maxlength="255" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:ring-teal-600">
                </div>
                <div class="space-y-1">
                    <label for="f-type" class="block text-sm text-slate-900">{{ __('transfers.exports.type') }}</label>
                    <select id="f-type" name="filters[type]" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:ring-teal-600">
                        <option value="">{{ __('transfers.exports.any') }}</option>
                        @foreach ($types as $type)
                            <option value="{{ $type['id'] }}" @selected((string) old('filters.type') === (string) $type['id'])>{{ $type['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="space-y-1">
                    <label for="f-city" class="block text-sm text-slate-900">{{ __('transfers.exports.city') }}</label>
                    <input id="f-city" type="text" name="filters[city]" value="{{ old('filters.city') }}" maxlength="255" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:ring-teal-600">
                </div>
                <div class="space-y-1">
                    <label for="f-district" class="block text-sm text-slate-900">{{ __('transfers.exports.district') }}</label>
                    <input id="f-district" type="text" name="filters[district]" value="{{ old('filters.district') }}" maxlength="255" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:ring-teal-600">
                </div>
                <div class="space-y-1">
                    <label for="f-status" class="block text-sm text-slate-900">{{ __('transfers.exports.status') }}</label>
                    <select id="f-status" name="filters[status]" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:ring-teal-600">
                        <option value="">{{ __('transfers.exports.any') }}</option>
                        <option value="active" @selected(old('filters.status') === 'active')>{{ __('transfers.exports.status_active') }}</option>
                        <option value="inactive" @selected(old('filters.status') === 'inactive')>{{ __('transfers.exports.status_inactive') }}</option>
                    </select>
                </div>
                @if ($isAdmin)
                    <div class="space-y-1">
                        <label for="f-trashed" class="block text-sm text-slate-900">{{ __('transfers.exports.trashed') }}</label>
                        <select id="f-trashed" name="filters[trashed]" class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:border-teal-600 focus:ring-teal-600">
                            <option value="">{{ __('transfers.exports.any') }}</option>
                            <option value="with" @selected(old('filters.trashed') === 'with')>{{ __('transfers.exports.trashed_with') }}</option>
                            <option value="only" @selected(old('filters.trashed') === 'only')>{{ __('transfers.exports.trashed_only') }}</option>
                        </select>
                    </div>
                @endif
            </fieldset>

            <div class="space-y-1">
                <label for="export-ids" class="block text-sm font-semibold text-slate-900">{{ __('transfers.exports.selected_ids') }}</label>
                <textarea id="export-ids" name="selected_ids" rows="3" dir="ltr" aria-describedby="export-ids-hint" class="block w-full rounded-md border-slate-300 font-mono text-sm focus:border-teal-600 focus:ring-teal-600">{{ old('selected_ids') }}</textarea>
                <p id="export-ids-hint" class="text-sm text-slate-700">{{ __('transfers.exports.selected_hint', ['max' => $selectedMax]) }}</p>
                @error('selected_ids')
                    <p class="text-sm font-medium text-red-700" role="alert">{{ $message }}</p>
                @enderror
            </div>

            <button type="submit" class="inline-flex min-h-10 items-center rounded-md bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-600 focus-visible:ring-offset-2">
                {{ __('transfers.exports.submit') }}
            </button>
        </form>
    @endif

    <section aria-labelledby="export-history" class="space-y-3" @if ($jobs->contains(fn ($j) => in_array($j->status, ['queued', 'running'], true))) wire:poll.5s @endif>
        <h2 id="export-history" class="text-lg font-semibold text-slate-900">{{ __('transfers.exports.history') }}</h2>

        @if ($jobs->isEmpty())
            <p class="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-700">{{ __('transfers.exports.empty') }}</p>
        @else
            <div class="relative overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="min-w-full text-start text-sm">
                    <thead class="bg-slate-50 text-slate-700">
                        <tr>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.exports.created') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.exports.scope_col') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.exports.locale') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.jobs.status') }}</th>
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.exports.rows_written') }}</th>
                            <th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('transfers.exports.download') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($jobs as $job)
                            <tr>
                                <td class="px-3 py-2"><time datetime="{{ $job->created_at?->toIso8601String() }}">{{ $job->created_at?->format('Y-m-d H:i') }}</time></td>
                                <td class="px-3 py-2">{{ __('transfers.exports.scope_'.$job->scope) }}</td>
                                <td class="px-3 py-2">{{ strtoupper($job->locale) }}</td>
                                <td class="px-3 py-2">{{ __('transfers.status.'.$job->status) }}</td>
                                <td class="px-3 py-2">
                                    {{ number_format($job->written_rows) }}
                                    @if ($job->status === 'completed' && $job->total_rows > $job->written_rows)
                                        <span class="block text-xs text-slate-700">{{ __('transfers.exports.skipped', ['count' => $job->total_rows - $job->written_rows]) }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-end">
                                    @if ($job->status === 'completed')
                                        <a href="{{ route('exports.download', $job) }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.exports.download') }}</a>
                                        <span class="block text-xs text-slate-700">{{ __('transfers.exports.expires', ['date' => $job->expires_at?->format('Y-m-d H:i')]) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $jobs->links() }}
        @endif
    </section>
</div>
