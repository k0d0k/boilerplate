@props([
    'items' => [],
    'label' => null,
])

@php
    use App\Filament\Shared\Navigation\LbaPreviewNavigation;
    use Filament\Support\Enums\IconSize;

    $label ??= LbaPreviewNavigation::previewGroupLabel();
@endphp

{{--
    Phase 1A-4: modules that are documented in docs/PANELS_NAVIGATION.md but not
    yet implemented are surfaced here as accessible, NON-INTERACTIVE preview
    labels.

    No <a> element and no `href` is emitted for any label, so nothing can be
    clicked, keyboard-focused or mistaken for a real route. The muted styling
    (resources/css/filament/lba-cloud/theme.css) plus the "belum aktif" group
    label communicate the unavailable state to sighted and assistive users.
--}}
<ul
    class="fi-sidebar-nav-groups lba-preview-groups"
    aria-label="{{ $label }}"
>
    <li class="fi-sidebar-group lba-preview-group" data-lba-preview>
        <div class="fi-sidebar-group-btn lba-preview-group__heading">
            <span class="fi-sidebar-group-label">{{ $label }}</span>
        </div>

        <ul class="fi-sidebar-group-items">
            @foreach ($items as $item)
                <li
                    class="fi-sidebar-item lba-preview-item"
                    data-lba-preview
                >
                    <span class="fi-sidebar-item-btn lba-preview-item__btn">
                        {{ \Filament\Support\generate_icon_html($item['icon'], size: IconSize::Large) }}

                        <span class="fi-sidebar-item-label">{{ $item['label'] }}</span>
                    </span>
                </li>
            @endforeach
        </ul>
    </li>
</ul>
