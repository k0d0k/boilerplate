@extends('principal.layouts.app', ['title' => __('lba.nav.principal.audit')])

@section('content')
    <section class="principal-card">
        <div class="flex flex-wrap items-end justify-between gap-3 border-b border-slate-200 p-4">
            <form method="get" class="flex min-w-0 flex-wrap items-end gap-3">
                <label class="grid gap-1 text-sm">
                    {{ __('lba.audit.filter_result') }}
                    <select class="principal-input" name="result">
                        <option value="">{{ __('lba.audit.filter_all') }}</option>
                        @foreach ([\App\Platform\ModuleRegistry\Models\ModuleAuditLog::RESULT_ALLOWED, \App\Platform\ModuleRegistry\Models\ModuleAuditLog::RESULT_DENIED] as $option)
                            <option value="{{ $option }}" @selected($result === $option)>{{ __('lba.audit.result_'.$option) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.audit.filter_action') }}
                    <select class="principal-input" name="action">
                        <option value="">{{ __('lba.audit.filter_all') }}</option>
                        @foreach ($actions as $value => $label)
                            <option value="{{ $value }}" @selected($action === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="grid gap-1 text-sm">
                    {{ __('lba.audit.filter_module') }}
                    <select class="principal-input" name="module_key">
                        <option value="">{{ __('lba.audit.filter_all') }}</option>
                        @foreach ($moduleKeys as $value => $label)
                            <option value="{{ $value }}" @selected($moduleKey === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
                <button class="principal-button">{{ __('principal.brands.apply') }}</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        @foreach (['column_time', 'column_module', 'column_action', 'column_result', 'column_actor', 'column_reason', 'column_scope', 'column_scope_key'] as $column)
                            <th scope="col" class="px-4 py-3 text-start">{{ __('lba.audit.'.$column) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $log->created_at?->format('d M Y H:i:s') ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $log->module_key ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $log->action ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-block rounded px-2 py-0.5 text-xs {{ $log->result === \App\Platform\ModuleRegistry\Models\ModuleAuditLog::RESULT_ALLOWED ? 'bg-brand-50 text-brand-700' : 'bg-red-50 text-red-700' }}">
                                    {{ __('lba.audit.result_'.$log->result) }}
                                </span>
                            </td>
                            <td class="px-4 py-3">{{ $log->actor?->name ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $log->reason ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $log->scope ?? '—' }}</td>
                            <td class="px-4 py-3">{{ $log->scope_key ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-8 text-center text-slate-500">
                                <strong class="block text-navy-900">{{ __('lba.audit.empty_heading') }}</strong>
                                {{ __('lba.audit.empty_description') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($logs->hasPages())
            <div class="border-t border-slate-200 p-4">{{ $logs->links() }}</div>
        @endif
    </section>
@endsection
