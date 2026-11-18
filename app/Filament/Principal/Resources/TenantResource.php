<?php

namespace App\Filament\Principal\Resources;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Pages\TenantSupport;
use App\Filament\Principal\Resources\TenantResource\Pages\CreateTenant;
use App\Filament\Principal\Resources\TenantResource\Pages\EditTenant;
use App\Filament\Principal\Resources\TenantResource\Pages\ListTenants;
use App\Filament\Principal\Resources\TenantResource\RelationManagers\BrandsRelationManager;
use App\Filament\Principal\Resources\TenantResource\RelationManagers\MembershipsRelationManager;
use App\Filament\Principal\Resources\TenantResource\RelationManagers\SubscriptionsRelationManager;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\SupportTenantSession;
use App\Platform\Onboarding\ClinicSetupResult;
use App\Platform\Onboarding\ClinicSetupService;
use App\Platform\Tenancy\Models\TenantDatabase;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Phase 7D — Principal Tenant / Clinic Management.
 *
 * Administers the central tenant registry: lifecycle status, tenant owner
 * assignment, brand association and the ISOLATED tenant database's provisioning
 * status. A tenant is one independent organisation with its own clinical
 * database (ARCH-04); this resource never reads or writes clinical data and
 * NEVER drops a tenant database — suspension is the non-destructive action.
 *
 * Authorization is delegated to {@see AuthorizesPrincipalResources}: only an
 * active principal holding `platform.tenant.manage` may view or change tenants.
 *
 * @extends resource<Tenant>
 */
class TenantResource extends Resource
{
    use AuthorizesPrincipalResources;

    protected static ?string $model = Tenant::class;

    // The canonical "Brand & Mitra" entry is the TailAdmin governance page
    // (brand + partner tabs); this legacy resource stays routable but must not
    // duplicate that sidebar entry.
    protected static bool $shouldRegisterNavigation = false;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('lba.tenants.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.nav.principal.clinics');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.principal.group_governance');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.tenants.section_identity'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('lba.tenants.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label(__('lba.tenants.slug'))
                            ->required()
                            ->maxLength(255)
                            ->unique(table: 'tenants', column: 'slug', ignoreRecord: true),
                        Select::make('status')
                            ->label(__('lba.tenants.status'))
                            ->options(static::statusOptions())
                            ->default(Tenant::STATUS_ACTIVE)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('lba.tenants.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label(__('lba.tenants.slug'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.tenants.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.tenants.status_{$state}"))
                    ->color(fn (string $state): string => $state === Tenant::STATUS_ACTIVE ? 'success' : 'danger'),
                TextColumn::make('database_status')
                    ->label(__('lba.tenants.db_status'))
                    ->badge()
                    ->state(fn (Tenant $record): string => $record->database?->status ?? TenantDatabase::STATUS_PENDING)
                    ->formatStateUsing(fn (string $state): string => __("lba.tenants.db_status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        TenantDatabase::STATUS_MIGRATED => 'success',
                        TenantDatabase::STATUS_PROVISIONED => 'info',
                        TenantDatabase::STATUS_FAILED => 'danger',
                        TenantDatabase::STATUS_DROPPED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('brands_count')
                    ->label(__('lba.tenants.brands'))
                    ->counts('brands')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('lba.tenants.created_at'))
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.tenants.filter_status'))
                    ->options(static::statusOptions()),
            ])
            ->recordActions([
                Action::make('openTenant')
                    ->label(__('lba.support.open'))
                    ->icon(Heroicon::OutlinedEye)
                    ->color('gray')
                    ->visible(fn (Tenant $record): bool => $record->status === Tenant::STATUS_ACTIVE)
                    ->action(function (Tenant $record) {
                        $actor = Auth::user();

                        $opened = $actor instanceof User
                            && app(SupportTenantSession::class)->open($actor, (string) $record->getKey());

                        if (! $opened) {
                            Notification::make()->danger()->title(__('lba.support.open_failed'))->send();

                            return null;
                        }

                        return redirect()->to(TenantSupport::getUrl(panel: 'principal'));
                    }),
                EditAction::make(),
                Action::make('activate')
                    ->label(__('lba.tenants.activate'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (Tenant $record): bool => $record->status !== Tenant::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (Tenant $record): void {
                        $record->update(['status' => Tenant::STATUS_ACTIVE]);
                    }),
                Action::make('suspend')
                    ->label(__('lba.tenants.suspend'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible(fn (Tenant $record): bool => $record->status === Tenant::STATUS_ACTIVE)
                    ->requiresConfirmation()
                    ->action(function (Tenant $record): void {
                        // Non-destructive: the isolated tenant database is kept.
                        $record->update(['status' => Tenant::STATUS_SUSPENDED]);
                    }),
                Action::make('setupClinic')
                    ->label(__('lba.tenants.setup_clinic'))
                    ->icon(Heroicon::OutlinedRocketLaunch)
                    ->schema([
                        TextInput::make('admin_email')
                            ->label(__('lba.tenants.setup_admin_email'))
                            ->email()
                            ->maxLength(255),
                        TextInput::make('admin_name')
                            ->label(__('lba.tenants.setup_admin_name'))
                            ->maxLength(255),
                    ])
                    ->action(function (Tenant $record, array $data): void {
                        $result = app(ClinicSetupService::class)->setup(
                            $record,
                            (string) ($data['admin_email'] ?? ''),
                            (string) ($data['admin_name'] ?? ''),
                            ClinicSetupService::DEFAULT_MODULES,
                            Auth::user(),
                        );

                        static::notifySetupResult($result);
                    }),
            ])
            ->defaultSort('name')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedRectangleStack)
            ->emptyStateHeading(__('lba.tenants.empty_heading'))
            ->emptyStateDescription(__('lba.tenants.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        // Central registry: eager-load the database registry row and brand count
        // so the list never issues per-row queries (and never touches a tenant
        // clinical connection).
        return parent::getEloquentQuery()
            ->with('database')
            ->withCount('brands');
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            MembershipsRelationManager::class,
            SubscriptionsRelationManager::class,
            BrandsRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_TENANT_MANAGE);
    }

    public static function canCreate(): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_TENANT_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::principalCan(PermissionKey::PLATFORM_TENANT_MANAGE);
    }

    public static function canDelete(Model $record): bool
    {
        // Suspension is non-destructive; tenants are never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Surface the outcome of a one-click setup run: progress, the blocking
     * error when a step failed, the database readiness state and — exactly
     * once — the Clinic Admin's single-use activation link.
     */
    private static function notifySetupResult(ClinicSetupResult $result): void
    {
        $failed = $result->failedStep();

        if ($failed !== null) {
            Notification::make()
                ->danger()
                ->title(__('lba.tenants.setup_failed'))
                ->body("{$failed->key}: {$failed->detail}")
                ->persistent()
                ->send();

            return;
        }

        $body = __('lba.tenants.setup_readiness').' '
            .($result->ready ? __('lba.tenants.setup_ready') : __('lba.tenants.setup_not_ready'));

        if ($result->activationUrl !== null) {
            $body .= "\n".__('lba.tenants.setup_activation_ready').' '.$result->activationUrl;
        }

        Notification::make()
            ->success()
            ->title(__('lba.tenants.setup_complete'))
            ->body($body)
            ->persistent()
            ->send();
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        return [
            Tenant::STATUS_ACTIVE => __('lba.tenants.status_active'),
            Tenant::STATUS_SUSPENDED => __('lba.tenants.status_suspended'),
        ];
    }
}
