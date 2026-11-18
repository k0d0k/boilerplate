@props(['status'])
<span @class([
    'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
    'bg-emerald-50 text-emerald-800' => $status === 'active',
    'bg-slate-100 text-slate-700' => $status !== 'active',
])>{{ __('lba.brands.status_'.$status) }}</span>
