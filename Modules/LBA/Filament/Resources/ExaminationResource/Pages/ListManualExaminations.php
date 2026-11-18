<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Examination;

/**
 * Phase 4 — the basic manual workflow queue: only manual-mode examinations.
 *
 * This is a real, route-backed list (the "Pemeriksaan Manual" navigation entry).
 * It narrows the examination registry to `mode = manual`, the only mode Phase 4
 * can process.
 */
class ListManualExaminations extends ListExaminations
{
    protected static string $resource = ExaminationResource::class;

    public static function getNavigationLabel(): string
    {
        return __('lba.exams.nav_manual');
    }

    public static function getNavigationIcon(): string|\BackedEnum|null
    {
        return Heroicon::OutlinedDocumentText;
    }

    public static function getNavigationSort(): ?int
    {
        return 24;
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        return parent::getTableQuery()?->where('mode', Examination::MODE_MANUAL);
    }

    /**
     * @return array<CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('lba.exams.create_manual')),
        ];
    }
}
