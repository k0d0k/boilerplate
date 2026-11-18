<?php

namespace App\Filament\Shared\Widgets;

use App\Filament\Shared\Data\LbaDemoData;
use Filament\Widgets\Widget;

/**
 * One reusable card widget for every "section" on the dashboards.
 *
 * Instead of a widget class per section, a panel registers this class as many
 * times as it needs via {@see static::make()} and passes a synthetic dataset key
 * plus a presentation `variant`. The variant selects which section partial is
 * rendered, so all three panels share one card shell, one spacing rhythm and one
 * typographic scale with zero duplicated markup.
 *
 * Variants: list | breakdown | metric | cards
 *
 * Data always comes from the typed {@see LbaDemoData} provider (replaceable by a
 * module-backed service later); this widget never reads the database.
 */
class DemoSectionWidget extends Widget
{
    protected static bool $isDiscovered = false;

    /**
     * Every section shares the same sort weight, so the (stable) widget sort
     * keeps them in registration order, after the hero, KPI strip and charts.
     */
    protected static ?int $sort = 10;

    /**
     * Grid span for the dashboard's 2-column grid. Overridable per registration
     * through {@see static::make()}.
     *
     * @var int | string | array<string, int | null>
     */
    protected int|string|array $columnSpan = 1;

    /**
     * Public, registration-settable mirror of {@see $columnSpan} so a panel can
     * request e.g. a full-width section without a dedicated widget class.
     *
     * @var int | string | array<string, int | null>
     */
    public int|string|array $span = 1;

    /**
     * @var view-string
     */
    protected string $view = 'filament.lba.widgets.section';

    /** Synthetic dataset key resolved against {@see LbaDemoData}. */
    public string $dataset = '';

    /** Section presentation variant: list | breakdown | metric | cards. */
    public string $variant = 'list';

    /** Optional heading override; otherwise the dataset heading is used. */
    public ?string $heading = null;

    /** Optional description override. */
    public ?string $description = null;

    /**
     * @return int | string | array<string, int | null>
     */
    public function getColumnSpan(): int|string|array
    {
        return $this->span;
    }

    protected function getViewData(): array
    {
        $data = LbaDemoData::get($this->dataset);

        return [
            'variant' => $this->variant,
            'heading' => $this->heading ?? ($data['heading'] ?? null),
            'description' => $this->description ?? ($data['description'] ?? null),
            'items' => $data['items'] ?? [],
            'primary' => $data['primary'] ?? null,
            'secondary' => $data['secondary'] ?? null,
            'progress' => $data['progress'] ?? null,
            'progressLabel' => $data['progressLabel'] ?? null,
            'note' => $data['note'] ?? null,
        ];
    }
}
