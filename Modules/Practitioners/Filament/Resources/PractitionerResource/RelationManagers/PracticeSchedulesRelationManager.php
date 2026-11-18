<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Models\PractitionerSchedule;
use Modules\Practitioners\Services\PractitionerScheduleRegistry;

/**
 * WS7.B — the recurring weekly practice windows of a practitioner.
 *
 * Only branches the acting identity may operate in are offered, and every write
 * goes through {@see PractitionerScheduleRegistry}, which re-derives branch
 * authorization and validity server-side and audits the change. Removal is
 * non-destructive.
 */
class PracticeSchedulesRelationManager extends RelationManager
{
    protected static string $relationship = 'schedules';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.practitioners.schedules.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PractitionerResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('branch_id')
                    ->label(__('lba.practitioners.schedules.branch'))
                    ->formatStateUsing(fn (?string $state): string => PractitionerResource::branchOptions()[$state] ?? '—'),
                TextColumn::make('weekday')
                    ->label(__('lba.practitioners.schedules.weekday'))
                    ->formatStateUsing(fn (int $state): string => __("lba.practitioners.schedules.weekday_{$state}")),
                TextColumn::make('starts_at')
                    ->label(__('lba.practitioners.schedules.starts_at')),
                TextColumn::make('ends_at')
                    ->label(__('lba.practitioners.schedules.ends_at')),
                TextColumn::make('slot_minutes')
                    ->label(__('lba.practitioners.schedules.slot_minutes'))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('lba.practitioners.schedules.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.schedules.status_{$state}"))
                    ->color(fn (string $state): string => $state === PractitionerSchedule::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label(__('lba.practitioners.schedules.add'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (): bool => PractitionerResource::canEdit($this->getOwnerRecord()))
                    ->schema(static::formSchema())
                    ->action(function (array $data): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerScheduleRegistry::class)->addWindow(
                                $actor,
                                $this->getOwnerRecord(),
                                $data,
                            ),
                            __('lba.practitioners.schedules.added'),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label(__('lba.practitioners.schedules.remove'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (PractitionerSchedule $record): bool => PractitionerResource::canEdit($this->getOwnerRecord()) && $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.practitioners.schedules.remove_confirm'))
                    ->action(function (PractitionerSchedule $record): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerScheduleRegistry::class)->removeWindow($actor, $record),
                            __('lba.practitioners.schedules.removed'),
                        );
                    }),
            ])
            ->emptyStateHeading(__('lba.practitioners.schedules.empty'));
    }

    /**
     * @return list<Component>
     */
    protected static function formSchema(): array
    {
        return [
            Select::make('branch_id')
                ->label(__('lba.practitioners.schedules.branch'))
                ->options(PractitionerResource::branchOptions())
                ->searchable()
                ->required(),
            Select::make('weekday')
                ->label(__('lba.practitioners.schedules.weekday'))
                ->options(static::weekdayOptions())
                ->required(),
            TimePicker::make('starts_at')
                ->label(__('lba.practitioners.schedules.starts_at'))
                ->seconds(false)
                ->required(),
            TimePicker::make('ends_at')
                ->label(__('lba.practitioners.schedules.ends_at'))
                ->seconds(false)
                ->required(),
            TextInput::make('slot_minutes')
                ->label(__('lba.practitioners.schedules.slot_minutes'))
                ->numeric()
                ->minValue(5)
                ->maxValue(480)
                ->helperText(__('lba.practitioners.schedules.slot_minutes_hint')),
            Select::make('service_type')
                ->label(__('lba.practitioners.schedules.service_type'))
                ->options(static::serviceOptions())
                ->placeholder(__('lba.practitioners.schedules.service_any')),
            TextInput::make('timezone')
                ->label(__('lba.practitioners.schedules.timezone'))
                ->maxLength(64)
                ->helperText(__('lba.practitioners.schedules.timezone_hint')),
            DatePicker::make('effective_from')
                ->label(__('lba.practitioners.schedules.effective_from')),
            DatePicker::make('effective_until')
                ->label(__('lba.practitioners.schedules.effective_until')),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function weekdayOptions(): array
    {
        $options = [];

        foreach (range(1, 7) as $weekday) {
            $options[$weekday] = __("lba.practitioners.schedules.weekday_{$weekday}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function serviceOptions(): array
    {
        $options = [];

        foreach (Appointment::SERVICES as $service) {
            $options[$service] = __("lba.appointments.service_{$service}");
        }

        return $options;
    }
}
