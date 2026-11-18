<?php

namespace Modules\Consultations\Filament\Resources\ConsultationResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationStatusHistory;
use Modules\Consultations\Services\ConsultationManager;

/**
 * Workstream 2 — a consultation's status history.
 *
 * Read-only: the log is append-only and only ever written by
 * {@see ConsultationManager}.
 */
class ConsultationStatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.consultations.history.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return ConsultationResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $names = $this->changedByNames();

        return $table
            ->columns([
                TextColumn::make('changed_at')
                    ->label(__('lba.consultations.history.changed_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('from_status')
                    ->label(__('lba.consultations.history.from'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : __("lba.consultations.status_{$state}"))
                    ->color(fn (?string $state): string => $state === null ? 'gray' : Consultation::statusColor($state)),
                TextColumn::make('to_status')
                    ->label(__('lba.consultations.history.to'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.consultations.status_{$state}"))
                    ->color(fn (string $state): string => Consultation::statusColor($state)),
                TextColumn::make('changed_by')
                    ->label(__('lba.consultations.history.changed_by'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($names[$state] ?? $state)),
                TextColumn::make('note')
                    ->label(__('lba.consultations.history.note'))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort('changed_at', 'desc')
            ->emptyStateHeading(__('lba.consultations.history.empty'));
    }

    /**
     * @return array<string, string>
     */
    private function changedByNames(): array
    {
        $ids = ConsultationStatusHistory::query()
            ->where('consultation_id', $this->getOwnerRecord()->getKey())
            ->pluck('changed_by')
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
