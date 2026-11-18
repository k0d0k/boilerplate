<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\Pages;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Examination;

/**
 * Phase 7E — the AI-mode examination queue.
 *
 * A real, route-backed list (the "Analisis AI" navigation entry) that narrows
 * the examination registry to `mode = ai`. It is read-only with respect to AI:
 * the page never starts an analysis. Production AI stays disabled behind the
 * existing server gate (`AiAnalysisService::aiEnabled()`), and any analysis job
 * is only ever launched from the examination record's existing workflow.
 */
class ListAiExaminations extends ListExaminations
{
    protected static string $resource = ExaminationResource::class;

    public static function getNavigationLabel(): string
    {
        return __('lba.exams.nav_ai');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedSparkles;
    }

    public static function getNavigationSort(): ?int
    {
        return 25;
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        return parent::getTableQuery()?->where('mode', Examination::MODE_AI);
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        // No create action: an AI-mode examination is recorded through the
        // examination registry, and this queue never implies that an AI
        // analysis can be run from here.
        return [];
    }
}
