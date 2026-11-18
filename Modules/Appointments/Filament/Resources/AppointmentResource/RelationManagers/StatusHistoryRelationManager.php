<?php

namespace Modules\Appointments\Filament\Resources\AppointmentResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\AppointmentStatusHistory;
use Modules\Appointments\Services\AppointmentScheduler;

/**
 * Phase 3 — an appointment's status history.
 *
 * Read-only: the log is append-only and only ever written by
 * {@see AppointmentScheduler}.
 */
class StatusHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.appointments.history.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return AppointmentResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $names = $this->changedByNames();

        return $table
            ->columns([
                TextColumn::make('changed_at')
                    ->label(__('lba.appointments.history.changed_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('from_status')
                    ->label(__('lba.appointments.history.from'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : __("lba.appointments.status_{$state}"))
                    ->color(fn (?string $state): string => $state === null ? 'gray' : Appointment::statusColor($state)),
                TextColumn::make('to_status')
                    ->label(__('lba.appointments.history.to'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.appointments.status_{$state}"))
                    ->color(fn (string $state): string => Appointment::statusColor($state)),
                TextColumn::make('changed_by')
                    ->label(__('lba.appointments.history.changed_by'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($names[$state] ?? $state)),
                TextColumn::make('note')
                    ->label(__('lba.appointments.history.note'))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort('changed_at', 'desc')
            ->emptyStateHeading(__('lba.appointments.history.empty'));
    }

    /**
     * @return array<string, string>
     */
    private function changedByNames(): array
    {
        $ids = AppointmentStatusHistory::query()
            ->where('appointment_id', $this->getOwnerRecord()->getKey())
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
