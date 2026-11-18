@extends('principal.layouts.app', ['title' => $consultation->patient?->full_name ?? __('lba.consultations.single'), 'panel' => 'clinic'])

@section('content')
    @php($actionLabels = [
        'in_progress' => 'lba.consultations.action_start',
        'completed' => 'lba.consultations.action_complete',
        'cancelled' => 'lba.consultations.action_cancel',
        'no_show' => 'lba.consultations.action_no_show',
    ])
    <div class="grid gap-4">
        <section class="principal-card">
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 p-4">
                <div class="min-w-0">
                    <h2 class="wrap-break-word font-semibold text-navy-900">{{ $consultation->patient?->full_name ?? '—' }}</h2>
                    <p class="text-sm text-slate-600">
                        {{ __('lba.consultations.channel_'.$consultation->channel) }}
                        · {{ __('lba.consultations.type_'.$consultation->type) }}
                        @if ($consultation->branch) · {{ $consultation->branch->name }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded-full px-3 py-1 text-sm font-medium
                        @class([
                            'bg-slate-100 text-slate-600' => $consultation->status === 'scheduled',
                            'bg-amber-100 text-amber-700' => $consultation->status === 'in_progress',
                            'bg-emerald-100 text-emerald-700' => $consultation->status === 'completed',
                            'bg-rose-100 text-rose-700' => in_array($consultation->status, ['cancelled', 'no_show'], true),
                        ])">{{ __('lba.consultations.status_'.$consultation->status) }}</span>
                    <a class="principal-button" href="{{ route('clinic.tailadmin.index', 'consultations') }}">{{ __('lba.consultations.back') }}</a>
                </div>
            </div>

            @if ($errors->any())
                <div class="border-b border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{{ $errors->first() }}</div>
            @endif

            <dl class="grid gap-x-6 gap-y-4 p-4 sm:grid-cols-2 lg:grid-cols-3">
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.scheduled_at') }}</dt>
                    <dd class="font-medium">{{ $consultation->scheduled_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.started_at') }}</dt>
                    <dd class="font-medium">{{ $consultation->started_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.ended_at') }}</dt>
                    <dd class="font-medium">{{ $consultation->ended_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.staff') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $staffName ?? __('lba.consultations.staff_unassigned') }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.appointment') }}</dt>
                    <dd class="font-medium">{{ $consultation->appointment?->starts_at?->translatedFormat('d M Y H:i') ?? __('lba.consultations.appointment_none') }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.branch') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $consultation->branch?->name ?? '—' }}</dd>
                </div>
                <div class="min-w-0 sm:col-span-2 lg:col-span-3">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.reason') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $consultation->reason ?: '—' }}</dd>
                </div>
                <div class="min-w-0 sm:col-span-2 lg:col-span-3">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.summary') }}</dt>
                    <dd class="wrap-break-word font-medium">{{ $consultation->summary ?: '—' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.tariff') }}</dt>
                    <dd class="font-medium">{{ $tariffLabel ?? __('lba.consultations.tariff_unset') }}</dd>
                </div>
                <div class="min-w-0 sm:col-span-2">
                    <dt class="text-sm text-slate-500">{{ __('lba.consultations.refund_terms') }}</dt>
                    <dd class="text-sm font-medium text-slate-600">
                        {{ __($cancellableBeforeStart ? 'lba.consultations.policy.cancellable' : 'lba.consultations.policy.not_cancellable') }}
                        · {{ __($refundable ? 'lba.consultations.policy.refundable' : 'lba.consultations.policy.not_refundable') }}
                    </dd>
                </div>
            </dl>

            @if ($canManage && $allowedTransitions !== [])
                <form method="post" action="{{ route('clinic.tailadmin.consultations.transition', $consultation) }}"
                    class="flex flex-wrap items-end gap-3 border-t border-slate-100 p-4">
                    @csrf
                    <label class="grid flex-1 gap-1 text-sm">
                        {{ __('lba.consultations.note') }}
                        <input class="principal-input" name="note" maxlength="1000" value="{{ is_string(old('note')) ? old('note') : '' }}">
                    </label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($allowedTransitions as $status)
                            <button class="principal-button" name="status" value="{{ $status }}" type="submit">
                                {{ __($actionLabels[$status] ?? 'lba.consultations.status_'.$status) }}
                            </button>
                        @endforeach
                    </div>
                </form>
            @elseif ($consultation->isTerminal())
                <div class="border-t border-slate-100 p-4 text-sm text-slate-500">{{ __('lba.consultations.no_transitions') }}</div>
            @endif
        </section>

        <section class="principal-card">
            <div class="border-b border-slate-200 p-4">
                <h3 class="font-semibold text-navy-900">{{ __('lba.consultations.transcript') }}</h3>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($messages as $message)
                    <li class="flex flex-col gap-1 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-sm font-semibold text-navy-900">{{ __('lba.consultations.messages.role_'.$message->role) }}</span>
                            <span class="text-xs text-slate-500">{{ $message->created_at?->translatedFormat('d M Y H:i') ?? '—' }}</span>
                        </div>
                        <p class="wrap-break-word whitespace-pre-line text-sm">{{ $message->content }}</p>
                        @if ($message->provider || $message->model || $message->credits)
                            <p class="text-xs text-slate-500">
                                @if ($message->provider) {{ __('lba.consultations.messages.provider') }}: {{ $message->provider }} @endif
                                @if ($message->model) · {{ __('lba.consultations.messages.model') }}: {{ $message->model }} @endif
                                @if ($message->credits) · {{ __('lba.consultations.messages.credits') }}: {{ $message->credits }} @endif
                            </p>
                        @endif
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.consultations.messages.empty') }}</li>
                @endforelse
            </ul>
        </section>

        <section class="principal-card">
            <div class="border-b border-slate-200 p-4">
                <h3 class="font-semibold text-navy-900">{{ __('lba.consultations.history.title') }}</h3>
            </div>
            <ul class="divide-y divide-slate-100">
                @forelse ($histories as $history)
                    <li class="flex flex-wrap items-center justify-between gap-3 p-4">
                        <div class="min-w-0">
                            <p class="font-medium">
                                {{ $history->from_status ? __('lba.consultations.status_'.$history->from_status) : '—' }}
                                → {{ __('lba.consultations.status_'.$history->to_status) }}
                            </p>
                            @if ($history->note)
                                <p class="wrap-break-word text-sm text-slate-600">{{ $history->note }}</p>
                            @endif
                        </div>
                        <div class="text-right text-sm text-slate-500">
                            <p>{{ $history->changed_at?->translatedFormat('d M Y H:i') ?? '—' }}</p>
                            <p>{{ $actorNames[$history->changed_by] ?? '—' }}</p>
                        </div>
                    </li>
                @empty
                    <li class="p-8 text-center text-sm text-slate-500">{{ __('lba.consultations.history.empty') }}</li>
                @endforelse
            </ul>
        </section>
    </div>
@endsection
