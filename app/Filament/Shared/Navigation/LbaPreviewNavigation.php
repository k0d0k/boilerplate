<?php

namespace App\Filament\Shared\Navigation;

use BackedEnum;
use Closure;
use Illuminate\Contracts\View\View;

/**
 * Defines the "documented but not yet implemented" sidebar modules.
 *
 * Phase 1A-4 ships the dashboards only, so the module list from
 * docs/PANELS_NAVIGATION.md stays visible for design review as accessible,
 * NON-INTERACTIVE preview labels rather than fake links:
 *
 *  - nothing here is registered as Filament navigation (Filament renders an
 *    <a> for every item and silently drops items without a URL), and
 *  - no `href` is emitted at all: each label is rendered inside a dedicated
 *    "Pratinjau modul" sidebar group via the SIDEBAR_NAV_END render hook, so it
 *    can never be clicked, focused or mistaken for a real route.
 *
 * When a module is actually implemented, register its real resource/page and
 * remove it from this list.
 */
final class LbaPreviewNavigation
{
    public const VIEW = 'filament.lba.navigation.preview-group';

    /**
     * The localized preview-group label (Phase 1B-3).
     */
    public static function previewGroupLabel(): string
    {
        return __('lba.nav.preview_group');
    }

    /**
     * Build the sidebar render-hook closure that prints the preview group.
     *
     * @param  array<string, BackedEnum|string>  $items  label => icon
     */
    public static function renderHook(array $items): Closure
    {
        return fn (): View => view(self::VIEW, [
            'label' => self::previewGroupLabel(),
            'items' => self::items($items),
        ]);
    }

    /**
     * Resolve the preview items, translating each key at render time.
     *
     * The keys are stable `lba.nav.*` translation keys, not display strings:
     * panel providers run during application boot (before the request locale is
     * applied), so translating inside this render-time helper is what makes the
     * labels follow the active locale.
     *
     * @param  array<string, BackedEnum|string>  $items  translation key => icon
     * @return array<int, array{label: string, icon: BackedEnum|string}>
     */
    public static function items(array $items): array
    {
        $previewItems = [];

        foreach ($items as $key => $icon) {
            $previewItems[] = ['label' => __($key), 'icon' => $icon];
        }

        return $previewItems;
    }
}
