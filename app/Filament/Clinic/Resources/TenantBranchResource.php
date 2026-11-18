<?php

namespace App\Filament\Clinic\Resources;

use App\Filament\Clinic\Resources\TenantBranchResource\Pages\CreateBranch;
use App\Filament\Clinic\Resources\TenantBranchResource\Pages\EditBranch;
use App\Filament\Clinic\Resources\TenantBranchResource\Pages\ListBranches;
use App\Filament\Clinic\Resources\TenantBranchResource\RelationManagers\BranchStaffRelationManager;
use App\Models\User;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\TenantBranch;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Phase 2B — Clinic panel branch management.
 *
 * Branches are administered at TENANT level: the list, create and edit screens
 * operate on the verified current tenant's isolated database and therefore work
 * even when the tenant has no branch yet (a freshly provisioned tenant is never
 * deadlocked — its first branch can always be created here).
 *
 * Authorization is delegated entirely to {@see ManagesBranchContext}: a
 * management role in the verified tenant may administer branches; every other
 * identity is denied. The resource never trusts a client-supplied tenant.
 *
 * Branches are never deleted — deactivation is non-destructive.
 *
 * @extends resource<TenantBranch>
 */
class TenantBranchResource extends Resource
{
    protected static ?string $model = TenantBranch::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('lba.branches.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.branches.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.settings');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.branches.section_identity'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('lba.branches.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label(__('lba.branches.code'))
                            ->required()
                            ->maxLength(64)
                            // Validated against the tenant's own database (the
                            // TenantBranch model resolves that connection).
                            ->unique(table: TenantBranch::class, column: 'code', ignoreRecord: true),
                        Select::make('status')
                            ->label(__('lba.branches.status'))
                            ->options(static::statusOptions())
                            ->default(TenantBranch::STATUS_ACTIVE)
                            ->required(),
                    ])
                    ->columns(2),
                Section::make(__('lba.branches.section_location'))
                    ->schema([
                        Textarea::make('address')
                            ->label(__('lba.branches.address'))
                            ->rows(2)
                            ->columnSpanFull()
                            ->maxLength(255),
                        TextInput::make('city')
                            ->label(__('lba.branches.city'))
                            ->maxLength(255),
                        TextInput::make('region')
                            ->label(__('lba.branches.region'))
                            ->maxLength(255),
                        TextInput::make('country')
                            ->label(__('lba.branches.country'))
                            ->default('ID')
                            ->maxLength(2),
                        TextInput::make('timezone')
                            ->label(__('lba.branches.timezone'))
                            ->default('Asia/Jakarta')
                            ->maxLength(64),
                    ])
                    ->columns(2),
                Section::make(__('lba.branches.section_scheduling'))
                    ->schema([
                        Select::make('location_type')
                            ->label(__('lba.branches.location_type'))
                            ->options([
                                TenantBranch::LOCATION_PERMANENT => __('lba.branches.location_type_permanent'),
                                TenantBranch::LOCATION_TEMPORARY => __('lba.branches.location_type_temporary'),
                                TenantBranch::LOCATION_EVENT => __('lba.branches.location_type_event'),
                            ])
                            ->default(TenantBranch::LOCATION_PERMANENT)
                            ->required()
                            ->live(),
                        TextInput::make('slot_minutes')
                            ->label(__('lba.branches.slot_minutes'))
                            ->numeric()
                            ->integer()
                            ->minValue(5)
                            ->maxValue(240)
                            ->default(TenantBranch::DEFAULT_SLOT_MINUTES),
                        DateTimePicker::make('operates_from')
                            ->label(__('lba.branches.operates_from'))
                            ->seconds(false)
                            ->helperText(__('lba.branches.operates_hint'))
                            ->visible(fn (Get $get): bool => static::isTemporaryLocation($get('location_type'))),
                        DateTimePicker::make('operates_until')
                            ->label(__('lba.branches.operates_until'))
                            ->seconds(false)
                            ->after('operates_from')
                            ->visible(fn (Get $get): bool => static::isTemporaryLocation($get('location_type'))),
                        TimePicker::make('opens_at')
                            ->label(__('lba.branches.opens_at'))
                            ->seconds(false)
                            ->format('H:i'),
                        TimePicker::make('closes_at')
                            ->label(__('lba.branches.closes_at'))
                            ->seconds(false)
                            ->format('H:i'),
                        TextInput::make('capacity')
                            ->label(__('lba.branches.capacity'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->helperText(__('lba.branches.capacity_hint')),
                    ])
                    ->columns(2),
                Section::make(__('lba.branches.section_contact'))
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('lba.branches.phone'))
                            ->tel()
                            ->maxLength(32),
                        TextInput::make('email')
                            ->label(__('lba.branches.email'))
                            ->email()
                            ->maxLength(255),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.branches.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label(__('lba.branches.code'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city')
                    ->label(__('lba.branches.city'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('location_type')
                    ->label(__('lba.branches.location_type'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        TenantBranch::LOCATION_TEMPORARY => __('lba.branches.location_type_temporary'),
                        TenantBranch::LOCATION_EVENT => __('lba.branches.location_type_event'),
                        default => __('lba.branches.location_type_permanent'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        TenantBranch::LOCATION_TEMPORARY => 'warning',
                        TenantBranch::LOCATION_EVENT => 'info',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.branches.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.branches.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        TenantBranch::STATUS_ACTIVE => 'success',
                        TenantBranch::STATUS_SUSPENDED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('phone')
                    ->label(__('lba.branches.phone'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.branches.filter_status'))
                    ->options(static::statusOptions()),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('activate')
                    ->label(__('lba.branches.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (TenantBranch $record): bool => $record->status !== TenantBranch::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (TenantBranch $record): void {
                        $record->activate();
                    }),
                Action::make('deactivate')
                    ->label(__('lba.branches.deactivate'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (TenantBranch $record): bool => $record->status === TenantBranch::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (TenantBranch $record): void {
                        $record->deactivate();
                    }),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedMapPin)
            ->emptyStateHeading(__('lba.branches.empty_heading'))
            ->emptyStateDescription(__('lba.branches.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        // TenantBranch resolves the verified tenant connection at query time and
        // fails closed without it; the resource therefore never lists another
        // tenant's branches.
        return parent::getEloquentQuery();
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            BranchStaffRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBranches::route('/'),
            'create' => CreateBranch::route('/create'),
            'edit' => EditBranch::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::manageable();
    }

    public static function canCreate(): bool
    {
        return static::manageable();
    }

    public static function canEdit(Model $record): bool
    {
        return static::manageable();
    }

    public static function canDelete(Model $record): bool
    {
        // Deactivation is non-destructive; branches are never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        return [
            TenantBranch::STATUS_ACTIVE => __('lba.branches.status_active'),
            TenantBranch::STATUS_INACTIVE => __('lba.branches.status_inactive'),
            TenantBranch::STATUS_SUSPENDED => __('lba.branches.status_suspended'),
        ];
    }

    /**
     * True when the selected location type carries an explicit operating window
     * (temporary or event) rather than the default permanent branch behaviour.
     */
    protected static function isTemporaryLocation(mixed $type): bool
    {
        return in_array($type, [TenantBranch::LOCATION_TEMPORARY, TenantBranch::LOCATION_EVENT], true);
    }

    /**
     * The acting identity may administer branches in the verified current tenant
     * (active membership + tenant role granting `branch.operations.manage`).
     */
    protected static function manageable(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        return app(ManagesBranchContext::class)->canManageBranches($user);
    }
}
