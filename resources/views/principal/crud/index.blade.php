@extends('principal.layouts.app', ['title' => __('principal.crud.title')])

@section('content')
    <form method="post" action="{{ route('principal.tailadmin.crud.preview') }}" class="principal-card grid gap-6 p-5 sm:p-6">
        @csrf
        <div class="grid gap-5 sm:grid-cols-2">
            <label class="grid gap-1.5 text-sm font-medium">
                {{ __('principal.crud.model') }}
                <select class="principal-input" name="model">
                    <option value="{{ App\Models\Brand::class }}">{{ __('principal.brands.title') }}</option>
                </select>
            </label>
            <label class="grid gap-1.5 text-sm font-medium">
                {{ __('principal.crud.validation') }}
                <select class="principal-input" name="validation">
                    <option value="brand">{{ __('principal.crud.brand_validation') }}</option>
                </select>
            </label>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="me-1 font-semibold">{{ __('principal.crud.fields') }}</span>
            @foreach (App\Platform\Crud\BrandCrudDefinition::FIELDS as $field)
                <input type="hidden" name="fields[]" value="{{ $field }}">
                <span class="rounded-md bg-slate-100 px-2.5 py-1">{{ __('lba.brands.'.$field) }}</span>
            @endforeach
        </div>
        <div class="grid gap-5 sm:grid-cols-3">
            @foreach ([
                'columns' => App\Platform\Crud\BrandCrudDefinition::FIELDS,
                'filters' => App\Platform\Crud\BrandCrudDefinition::FILTERS,
                'actions' => App\Platform\Crud\BrandCrudDefinition::ACTIONS,
            ] as $group => $items)
                <fieldset class="grid content-start gap-2.5">
                    <legend class="mb-3 text-sm font-semibold">{{ __('principal.crud.'.$group) }}</legend>
                    @php($selected = old($group, session()->hasOldInput() ? [] : $definition[$group]))
                    @foreach ($items as $item)
                        <label class="flex items-center gap-2.5 text-sm">
                            @if ($group === 'columns' && $item === 'name')
                                <input type="hidden" name="columns[]" value="name">
                                <input type="checkbox" class="size-4 accent-teal-700" checked disabled>
                            @else
                                <input type="checkbox" class="size-4 accent-teal-700" name="{{ $group }}[]" value="{{ $item }}"
                                    @checked(in_array($item, (array) $selected, true))>
                            @endif
                            {{ __($group === 'actions' ? 'principal.crud.'.$item : 'lba.brands.'.$item) }}
                        </label>
                    @endforeach
                </fieldset>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2 border-t border-slate-200 pt-5">
            <button type="submit" class="principal-button principal-button-primary">{{ __('principal.crud.preview') }}</button>
            <button type="submit" class="principal-button" formaction="{{ route('principal.tailadmin.crud.export') }}">{{ __('principal.crud.export') }}</button>
        </div>
    </form>
    @isset($source)
        <section class="principal-card mt-5 p-5 sm:p-6">
            <h2 class="mb-3 font-semibold text-navy-900">{{ __('principal.crud.generated') }}</h2>
            <pre class="overflow-x-auto rounded-lg bg-navy-900 p-4 text-sm text-slate-100"><code>{{ $source }}</code></pre>
        </section>
    @endisset
@endsection
