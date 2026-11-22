<div class="mx-auto max-w-6xl space-y-6">
    <header class="space-y-2">
        <h1 class="text-2xl font-semibold text-slate-900">{{ __('transfers.jobs.title') }}</h1>
        <p class="text-sm text-slate-700">{{ $all ? __('transfers.jobs.scope_note_all') : __('transfers.jobs.scope_note_own') }}</p>
    </header>

    @if ($jobs->isEmpty())
        <p class="rounded-md border border-dashed border-slate-300 p-4 text-sm text-slate-700">{{ __('transfers.jobs.empty') }}</p>
    @else
        <div class="relative overflow-x-auto rounded-lg border border-slate-200 bg-white">
            <table class="min-w-full text-start text-sm">
                <thead class="bg-slate-50 text-slate-700">
                    <tr>
                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.jobs.kind') }}</th>
                        @if ($all)
                            <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.jobs.user') }}</th>
                        @endif
                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.jobs.detail') }}</th>
                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.jobs.status') }}</th>
                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.imports.rows') }}</th>
                        <th scope="col" class="px-3 py-2 text-start font-semibold">{{ __('transfers.jobs.created') }}</th>
                        <th scope="col" class="px-3 py-2"><span class="sr-only">{{ __('transfers.jobs.open') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($jobs as $job)
                        <tr>
                            <td class="px-3 py-2">{{ __('transfers.jobs.'.$job->kind) }}</td>
                            @if ($all)
                                <td class="px-3 py-2" dir="auto">{{ $names[$job->user_id] ?? '' }}</td>
                            @endif
                            <td class="max-w-xs truncate px-3 py-2" dir="auto">
                                @if ($job->kind === 'import')
                                    {{ $job->label }} ({{ __('transfers.modes.'.$job->detail) }})
                                @else
                                    {{ __('transfers.exports.scope_'.$job->label) }} ({{ strtoupper($job->detail) }})
                                @endif
                            </td>
                            <td class="px-3 py-2">{{ __('transfers.status.'.$job->status) }}</td>
                            <td class="px-3 py-2">{{ number_format((int) $job->rows) }}</td>
                            <td class="px-3 py-2"><time datetime="{{ \Illuminate\Support\Carbon::parse($job->created_at)->toIso8601String() }}">{{ \Illuminate\Support\Carbon::parse($job->created_at)->format('Y-m-d H:i') }}</time></td>
                            <td class="px-3 py-2 text-end">
                                @if ($job->kind === 'import')
                                    <a href="{{ route('imports.show', $job->uuid) }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.jobs.open') }}</a>
                                @elseif ($job->status === 'completed')
                                    <a href="{{ route('exports.download', $job->uuid) }}" class="font-medium text-teal-800 underline underline-offset-2">{{ __('transfers.exports.download') }}</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $jobs->links() }}
    @endif
</div>
