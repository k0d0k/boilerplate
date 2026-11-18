<?php

namespace Modules\Practitioners\Filament\Resources;

use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\UserCategory;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
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
use Illuminate\Support\Carbon;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Filament\Resources\PractitionerResource\Pages\CreatePractitioner;
use Modules\Practitioners\Filament\Resources\PractitionerResource\Pages\EditPractitioner;
use Modules\Practitioners\Filament\Resources\PractitionerResource\Pages\ListPractitioners;
use Modules\Practitioners\Filament\Resources\PractitionerResource\Pages\ViewPractitioner;
use Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers\AuditRelationManager;
use Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers\BranchAuthorizationsRelationManager;
use Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers\CredentialsRelationManager;
use Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers\PracticeSchedulesRelationManager;
use Modules\Practitioners\Filament\Resources\PractitionerResource\RelationManagers\ScheduleExceptionsRelationManager;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerRegistry;
use UnitEnum;

/**
 * WS7.A — Clinic practitioner roster.
 *
 * Operates on the verified current tenant's isolated database, so a
 * practitioner is never visible across tenants. Practitioners are never
 * deleted: suspension and archival are non-destructive, preserving their
 * appointment / consultation / examination history.
 *
 * The UI is never the authorization boundary — every action re-derives its
 * permission server-side through {@see PractitionerRegistry}.
 *
 * @extends resource<Practitioner>
 */
class PractitionerResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'practitioners';

    protected static ?string $model = Practitioner::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 25;

    protected static ?string $recordTitleAttribute = 'display_name';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.practitioners.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.practitioners.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.practitioners.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.practitioners.section_identity'))
                    ->schema([
                        Select::make('user_id')
                            ->label(__('lba.practitioners.user'))
                            ->options(static fn (): array => static::eligibleUserOptions())
                            ->searchable()
                            ->required()
                            ->visibleOn('create')
                            ->helperText(__('lba.practitioners.user_hint')),
                        TextInput::make('display_name')
                            ->label(__('lba.practitioners.display_name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('title')
                            ->label(__('lba.practitioners.title'))
                            ->maxLength(128)
                            ->helperText(__('lba.practitioners.title_hint')),
                        TextInput::make('professional_body')
                            ->label(__('lba.practitioners.professional_body'))
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make(__('lba.practitioners.section_profile'))
                    ->schema([
                        TagsInput::make('specialities')
                            ->label(__('lba.practitioners.specialities'))
                            ->helperText(__('lba.practitioners.specialities_hint'))
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label(__('lba.practitioners.notes'))
                            ->rows(2)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
                Section::make(__('lba.practitioners.section_status'))
                    ->schema([
                        Select::make('status')
                            ->label(__('lba.practitioners.status'))
                            ->options(static::statusOptions())
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn(['edit', 'view']),
                        DateTimePicker::make('verified_at')
                            ->label(__('lba.practitioners.verified_at'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn(['edit', 'view']),
                        DateTimePicker::make('expires_at')
                            ->label(__('lba.practitioners.expires_at'))
                            ->disabled()
                            ->dehydrated(false)
                            ->visibleOn(['edit', 'view']),
                    ])
                    ->columns(3),
            ]);
    }

    public static function table(Table $table): Table
    {
        $names = static::userNames();

        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('lba.practitioners.display_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user_id')
                    ->label(__('lba.practitioners.user'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($names[$state] ?? $state))
                    ->toggleable(),
                TextColumn::make('title')
                    ->label(__('lba.practitioners.title'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('lba.practitioners.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.practitioners.status_{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        Practitioner::STATUS_VERIFIED => 'success',
                        Practitioner::STATUS_PENDING => 'warning',
                        Practitioner::STATUS_SUSPENDED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('verified_at')
                    ->label(__('lba.practitioners.verified_at'))
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('expires_at')
                    ->label(__('lba.practitioners.expires_at'))
                    ->date()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.practitioners.filter_status'))
                    ->options(static::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('verify')
                    ->label(__('lba.practitioners.verify'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success')
                    ->visible(fn (Practitioner $record): bool => static::canVerify() && $record->isPending())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.practitioners.verify_confirm'))
                    ->schema([
                        DateTimePicker::make('expires_at')
                            ->label(__('lba.practitioners.expires_at'))
                            ->helperText(__('lba.practitioners.expires_hint')),
                    ])
                    ->action(function (Practitioner $record, array $data): void {
                        static::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->verify(
                                $actor,
                                $record,
                                isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                            ),
                            __('lba.practitioners.verified'),
                        );
                    }),
                Action::make('suspend')
                    ->label(__('lba.practitioners.suspend'))
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->visible(fn (Practitioner $record): bool => static::canVerify() && ! $record->isSuspended() && ! $record->isInactive())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.practitioners.suspend_confirm'))
                    ->action(function (Practitioner $record): void {
                        static::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->suspend($actor, $record),
                            __('lba.practitioners.suspended'),
                        );
                    }),
                Action::make('reactivate')
                    ->label(__('lba.practitioners.reactivate'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('success')
                    ->visible(fn (Practitioner $record): bool => static::canVerify() && ($record->isSuspended() || $record->isInactive()))
                    ->requiresConfirmation()
                    ->action(function (Practitioner $record): void {
                        static::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->reactivate($actor, $record),
                            __('lba.practitioners.reactivated'),
                        );
                    }),
                Action::make('archive')
                    ->label(__('lba.practitioners.archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('gray')
                    ->visible(fn (Practitioner $record): bool => static::canCreate() && ! $record->isInactive())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.practitioners.archive_confirm'))
                    ->action(function (Practitioner $record): void {
                        static::run(
                            fn (User $actor) => app(PractitionerRegistry::class)->archive($actor, $record),
                            __('lba.practitioners.archived'),
                        );
                    }),
            ])
            ->defaultSort('display_name')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedIdentification)
            ->emptyStateHeading(__('lba.practitioners.empty_heading'))
            ->emptyStateDescription(__('lba.practitioners.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        // Practitioner resolves the verified tenant connection at query time and
        // fails closed without it; the resource therefore never lists another
        // tenant's practitioners.
        return parent::getEloquentQuery();
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            CredentialsRelationManager::class,
            BranchAuthorizationsRelationManager::class,
            PracticeSchedulesRelationManager::class,
            ScheduleExceptionsRelationManager::class,
            AuditRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPractitioners::route('/'),
            'create' => CreatePractitioner::route('/create'),
            'view' => ViewPractitioner::route('/{record}'),
            'edit' => EditPractitioner::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PRACTITIONER_PROFILE_READ);
    }

    public static function canCreate(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PRACTITIONER_PROFILE_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PRACTITIONER_PROFILE_MANAGE);
    }

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PRACTITIONER_PROFILE_READ);
    }

    public static function canDelete(Model $record): bool
    {
        // Archiving is non-destructive; practitioners are never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * True when the acting identity may verify / suspend practitioners. Used
     * only to hide actions; the service re-derives the permission server-side.
     */
    public static function canVerify(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PRACTITIONER_CREDENTIAL_VERIFY);
    }

    /**
     * Run a registry operation and surface its outcome as a notification rather
     * than a 500.
     *
     * @param  callable(User): mixed  $operation
     */
    public static function run(callable $operation, string $success): void
    {
        $actor = static::currentUser();

        if ($actor === null) {
            Notification::make()->danger()->title(__('lba.practitioners.not_authorized'))->send();

            return;
        }

        try {
            $operation($actor);
        } catch (PractitionerException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    /**
     * Branch id => name options the identity may operate in; used by the
     * branch-authorization relation manager. A branch outside this set can
     * never be authorized.
     *
     * @return array<string, string>
     */
    public static function branchOptions(): array
    {
        return static::accessibleBranchOptions();
    }

    /**
     * Active clinical members of the verified tenant who are not yet registered
     * as practitioners — the only identities that may be added to the roster.
     *
     * @return array<string, string>
     */
    protected static function eligibleUserOptions(): array
    {
        $actor = static::currentUser();

        if ($actor === null) {
            return [];
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($actor);

        if ($tenantId === null) {
            return [];
        }

        $clinicalRoleIds = Role::query()
            ->whereIn('key', UserCategory::roleKeys(UserCategory::MEDICAL_STAFF))
            ->pluck('id')
            ->all();

        if ($clinicalRoleIds === []) {
            return [];
        }

        $candidateIds = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $clinicalRoleIds))
            ->pluck('user_id')
            ->unique()
            ->all();

        $registered = Practitioner::query()->pluck('user_id')->all();
        $eligible = array_values(array_diff($candidateIds, $registered));

        if ($eligible === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $eligible)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Central user display names for the rows on the current page.
     *
     * @return array<string, string>
     */
    protected static function userNames(): array
    {
        $ids = Practitioner::query()->pluck('user_id')->filter()->unique()->all();

        if ($ids === []) {
            return [];
        }

        return User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (Practitioner::STATUSES as $status) {
            $options[$status] = __("lba.practitioners.status_{$status}");
        }

        return $options;
    }
}
