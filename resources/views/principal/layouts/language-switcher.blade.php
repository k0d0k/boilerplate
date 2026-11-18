{{-- The TailAdmin topbar variant of the one shared `language-switcher`
     component. It carries the same `wire:key` as the panel variant so the
     component is keyed identically in every shell, and labels its option list
     with `lba.language.choose` so the selector keeps an accessible name. --}}
<div class="relative" wire:key="lba-language-switcher" x-data x-on:keydown.escape="$wire.close()">
    <button type="button" class="principal-button" wire:click="toggle"
        aria-label="{{ __('lba.language.aria') }}" aria-expanded="{{ $open ? 'true' : 'false' }}">
        <span aria-hidden="true">{{ strtoupper($current) }}</span>
    </button>
    @if ($open)
        <div class="principal-card absolute end-0 z-30 mt-2 flex w-48 flex-col gap-1 p-2" aria-label="{{ __('lba.language.choose') }}">
            @foreach ($languages as $code => $label)
                <button type="button" class="principal-link w-full text-start" wire:click="setLocale('{{ $code }}')"
                    aria-pressed="{{ $code === $current ? 'true' : 'false' }}">{{ $label }}</button>
            @endforeach
        </div>
    @endif
</div>
