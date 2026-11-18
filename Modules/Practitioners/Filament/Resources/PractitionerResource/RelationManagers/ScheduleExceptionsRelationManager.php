<?php

namespace Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Component;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Models\PractitionerScheduleException;
use Modules\Practitioners\Services\PractitionerScheduleRegistry;

/**
 * WS7.B — dated overrides of a practitioner's weekly practice schedule:
 * leave, public holidays, cancelled sessions and ad-hoc extra sessions.
 *
 * A null branch means the exception applies to every branch the practitioner
 * practises at. Only branches the acting identity may operate in are offered,
 * every write goes through {@see PractitionerScheduleRegistry} (which re-derives
 * branch authorization and validity server-side and audits the change), and
 * cancelling is non-destructive so the history survives.
 */
class ScheduleExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'scheduleExceptions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('lba.practitioners.exceptions.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return PractitionerResource::canView($ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('lba.practitioners.exceptions.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.exceptions.type_{$state}"))
                    ->color(fn (string $state): string => $state === PractitionerScheduleException::TYPE_EXTRA_SESSION ? 'success' : 'warning'),
                TextColumn::make('branch_id')
                    ->label(__('lba.practitioners.exceptions.branch'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? __('lba.practitioners.exceptions.branch_all')
                        : (PractitionerResource::branchOptions()[$state] ?? '—')),
                TextColumn::make('starts_at')
                    ->label(__('lba.practitioners.exceptions.starts_at'))
                    ->dateTime(),
                TextColumn::make('ends_at')
                    ->label(__('lba.practitioners.exceptions.ends_at'))
                    ->dateTime(),
                IconColumn::make('all_day')
                    ->label(__('lba.practitioners.exceptions.all_day'))
                    ->boolean(),
                TextColumn::make('reason')
                    ->label(__('lba.practitioners.exceptions.reason'))
                    ->placeholder('—')
                    ->wrap()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.practitioners.exceptions.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.exceptions.status_{$state}"))
                    ->color(fn (string $state): string => $state === PractitionerScheduleException::STATUS_ACTIVE ? 'success' : 'gray'),
            ])
            ->headerActions([
                Action::make('add')
                    ->label(__('lba.practitioners.exceptions.add'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->visible(fn (): bool => PractitionerResource::canEdit($this->getOwnerRecord()))
                    ->schema(static::formSchema())
                    ->action(function (array $data): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerScheduleRegistry::class)->recordException(
                                $actor,
                                $this->getOwnerRecord(),
                                $data,
                            ),
                            __('lba.practitioners.exceptions.added'),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->label(__('lba.practitioners.exceptions.cancel'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (PractitionerScheduleException $record): bool => PractitionerResource::canEdit($this->getOwnerRecord()) && $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.practitioners.exceptions.cancel_confirm'))
                    ->action(function (PractitionerScheduleException $record): void {
                        PractitionerResource::run(
                            fn (User $actor) => app(PractitionerScheduleRegistry::class)->cancelException($actor, $record),
                            __('lba.practitioners.exceptions.cancelled'),
                        );
                    }),
            ])
            ->defaultSort('starts_at', 'desc')
            ->emptyStateHeading(__('lba.practitioners.exceptions.empty'));
    }

    /**
     * @return list<Component>
     */
    protected static function formSchema(): array
    {
        return [
            Select::make('type')
                ->label(__('lba.practitioners.exceptions.type'))
                ->options(static::typeOptions())
                ->required(),
            Select::make('branch_id')
                ->label(__('lba.practitioners.exceptions.branch'))
                ->options(PractitionerResource::branchOptions())
                ->placeholder(__('lba.practitioners.exceptions.branch_all'))
                ->searchable(),
            DateTimePicker::make('starts_at')
                ->label(__('lba.practitioners.exceptions.starts_at'))
                ->seconds(false)
                ->required(),
            DateTimePicker::make('ends_at')
                ->label(__('lba.practitioners.exceptions.ends_at'))
                ->seconds(false)
                ->required(),
            Toggle::make('all_day')
                ->label(__('lba.practitioners.exceptions.all_day')),
            Textarea::make('reason')
                ->label(__('lba.practitioners.exceptions.reason'))
                ->rows(2)
                ->maxLength(255),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function typeOptions(): array
    {
        $options = [];

        foreach (PractitionerScheduleException::TYPES as $type) {
            $options[$type] = __("lba.practitioners.exceptions.type_{$type}");
        }

        return $options;
    }
}
