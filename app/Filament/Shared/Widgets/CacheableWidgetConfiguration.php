<?php

namespace App\Filament\Shared\Widgets;

use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * Cache-safe {@see WidgetConfiguration}.
 *
 * A panel registers configured widgets through `Widget::make([...])`, which
 * returns a vendor {@see WidgetConfiguration}. `php artisan filament:cache-components`
 * persists the panel widget list with `var_export()`, which emits
 * `\Filament\Widgets\WidgetConfiguration::__set_state(array(...))`.
 *
 * The vendor class has no `__set_state()`, so the generated cache file is not
 * loadable: requiring it fatals with
 *
 *     Call to undefined method Filament\Widgets\WidgetConfiguration::__set_state()
 *
 * This subclass restores the missing factory so configured widgets survive
 * component caching (and therefore `php artisan optimize`), while behaving
 * exactly like the parent everywhere else (`instanceof WidgetConfiguration`
 * checks in Filament are unaffected).
 */
class CacheableWidgetConfiguration extends WidgetConfiguration
{
    /**
     * @param  class-string<Widget>  $widget
     * @param  array<string, mixed>  $properties
     */
    public static function make(string $widget, array $properties = []): self
    {
        return new self($widget, $properties);
    }

    /**
     * Reconstruct the configuration from the exported state.
     *
     * PHP 8.3+ exports object properties under their declared names, but older
     * builds prefix non-public properties with a null-byte scope marker. Both
     * shapes are accepted so the cache round-trips on PHP 8.4 and 8.5 alike.
     *
     * @param  array<string, mixed>  $state
     */
    public static function __set_state(array $state): self
    {
        return new self(
            (string) ($state['widget'] ?? ''),
            self::extractProperties($state),
        );
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function extractProperties(array $state): array
    {
        foreach ($state as $key => $value) {
            if ($key === 'properties' || str_ends_with((string) $key, "\0properties")) {
                return is_array($value) ? $value : [];
            }
        }

        return [];
    }
}
