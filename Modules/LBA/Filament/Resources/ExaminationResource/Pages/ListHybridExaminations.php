<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\Pages;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Examination;

/**
 * Phase 7E — the Hybrid-mode examination queue.
 *
 * A real, route-backed list (the "Analisis Hybrid" navigation entry) that
 * narrows the examination registry to `mode = hybrid`. Like the AI queue it is
 * read-only with respect to analysis: manual annotation and any AI suggestion
 * are handled on the examination record, under the existing server gates.
 */
class ListHybridExaminations extends ListExaminations
{
    protected static string $resource = ExaminationResource::class;

    public static function getNavigationLabel(): string
    {
        return __('lba.exams.nav_hybrid');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedSquares2x2;
    }

    public static function getNavigationSort(): ?int
    {
        return 26;
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        return parent::getTableQuery()?->where('mode', Examination::MODE_HYBRID);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
