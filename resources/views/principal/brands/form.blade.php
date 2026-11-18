@extends('principal.layouts.app', ['title' => __($brand->exists ? 'principal.brands.edit' : 'principal.brands.create')])

@section('content')
    <section class="principal-card max-w-2xl p-5 sm:p-6">
        <form method="post" action="{{ $brand->exists ? route('principal.tailadmin.brands.update', $brand) : route('principal.tailadmin.brands.store') }}" class="grid gap-5">
            @csrf
            @if ($brand->exists)
                @method('PATCH')
            @endif
            @foreach (['name', 'slug'] as $field)
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('lba.brands.'.$field) }}
                    <input class="principal-input" type="text" name="{{ $field }}" value="{{ is_string(old($field, $brand->{$field})) ? old($field, $brand->{$field}) : '' }}"
                        maxlength="255" required @error($field) aria-invalid="true" @enderror>
                </label>
            @endforeach
            @if ($brand->exists)
                <div class="flex flex-wrap items-center gap-3 text-sm font-medium">
                    {{ __('lba.brands.status') }}
                    <x-principal.brand-status :status="$brand->status" />
                </div>
            @else
                <label class="grid gap-1.5 text-sm font-medium">
                    {{ __('lba.brands.status') }}
                    <select class="principal-input" name="status" required>
                        @foreach ([App\Models\Brand::STATUS_ACTIVE, App\Models\Brand::STATUS_SUSPENDED] as $status)
                            <option value="{{ $status }}" @selected(old('status', $brand->status) === $status)>{{ __('lba.brands.status_'.$status) }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-5">
                <button type="submit" class="principal-button principal-button-primary">{{ __('principal.brands.save') }}</button>
                <a href="{{ route('principal.tailadmin.brands.index') }}" class="principal-button">{{ __('principal.brands.cancel') }}</a>
            </div>
        </form>
    </section>
@endsection
