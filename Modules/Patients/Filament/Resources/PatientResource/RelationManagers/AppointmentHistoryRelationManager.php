<?php

namespace Modules\Patients\Filament\Resources\PatientResource\RelationManagers;

use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Filament\Resources\PatientResource;

/**
 * Phase 3 — a patient's appointment history.
 *
 * Read-only: bookings are created and managed in the Appointments resource.
 * A patient's history spans every branch of the tenant the staff identity is
 * authorized to see.
 */
class AppointmentHistoryRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.patients.appointments.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PatientResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        $names = $this->staffNames();

        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label(__('lba.appointments.starts_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('service_type')
                    ->label(__('lba.appointments.service_type')),
                TextColumn::make('branch.name')
                    ->label(__('lba.appointments.branch'))
                    ->placeholder('—'),
                TextColumn::make('staff_user_id')
                    ->label(__('lba.appointments.staff'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($names[$state] ?? $state)),
                TextColumn::make('status')
                    ->label(__('lba.appointments.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.appointments.status_{$state}"))
                    ->color(fn (string $state): string => Appointment::statusColor($state)),
            ])
            ->defaultSort('starts_at', 'desc')
            ->emptyStateHeading(__('lba.patients.appointments.empty'));
    }

    /**
     * @return array<string, string>
     */
    private function staffNames(): array
    {
        $ids = Appointment::query()
            ->where('patient_id', $this->getOwnerRecord()->getKey())
            ->pluck('staff_user_id')
            ->filter()
            ->unique()
            ->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
