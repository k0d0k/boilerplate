<?php

namespace Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationEvent;
use Modules\LBA\Services\ExaminationWorkflow;

/**
 * Phase 4 — an examination's append-only workflow event log.
 *
 * Read-only: the log is written only by {@see ExaminationWorkflow}
 * and records every important state change with the acting identity.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.exams.events.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ExaminationResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $names = $this->actorNames();

        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label(__('lba.exams.events.occurred_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('from_status')
                    ->label(__('lba.exams.events.from'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? '—'
                        : __("lba.exams.status_{$state}"))
                    ->color(fn (?string $state): string => $state === null ? 'gray' : Examination::statusColor($state)),
                TextColumn::make('to_status')
                    ->label(__('lba.exams.events.to'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.exams.status_{$state}"))
                    ->color(fn (string $state): string => Examination::statusColor($state)),
                TextColumn::make('actor_id')
                    ->label(__('lba.exams.events.actor'))
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($names[$state] ?? $state)),
                TextColumn::make('note')
                    ->label(__('lba.exams.events.note'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->emptyStateHeading(__('lba.exams.events.empty'));
    }

    /**
     * @return array<string, string>
     */
    private function actorNames(): array
    {
        $ids = ExaminationEvent::query()
            ->where('examination_id', $this->getOwnerRecord()->getKey())
            ->pluck('actor_id')
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
