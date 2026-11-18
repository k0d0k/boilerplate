<?php

namespace Modules\Patients\Filament\Resources;

use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
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
use Modules\Appointments\Models\Appointment;
use Modules\Patients\Exceptions\PatientLinkException;
use Modules\Patients\Filament\Resources\PatientResource\Pages\CreatePatient;
use Modules\Patients\Filament\Resources\PatientResource\Pages\EditPatient;
use Modules\Patients\Filament\Resources\PatientResource\Pages\ListPatients;
use Modules\Patients\Filament\Resources\PatientResource\Pages\ViewPatient;
use Modules\Patients\Filament\Resources\PatientResource\RelationManagers\AppointmentHistoryRelationManager;
use Modules\Patients\Filament\Resources\PatientResource\RelationManagers\ConsentsRelationManager;
use Modules\Patients\Models\Patient;
use Modules\Patients\Services\PatientAccountLinker;
use Modules\Patients\Services\PatientRegistrar;
use UnitEnum;

/**
 * Phase 3 — Clinic panel patient registry.
 *
 * Operates on the verified current tenant's isolated database, so a patient is
 * never visible across tenants. Patients are archived, never deleted, and the
 * registration branch is recorded without restricting the patient's history to
 * that branch.
 *
 * @extends resource<Patient>
 */
class PatientResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'patients';

    protected static ?string $model = Patient::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.patients.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.patients.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.patients.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.patients.section_identity'))
                    ->schema([
                        TextInput::make('patient_number')
                            ->label(__('lba.patients.number'))
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('lba.patients.number_auto'))
                            ->visibleOn('edit'),
                        TextInput::make('full_name')
                            ->label(__('lba.patients.full_name'))
                            ->required()
                            ->maxLength(255),
                        DatePicker::make('date_of_birth')
                            ->label(__('lba.patients.date_of_birth'))
                            ->maxDate(now()),
                        Select::make('sex')
                            ->label(__('lba.patients.sex'))
                            ->options(static::sexOptions()),
                        TextInput::make('gender')
                            ->label(__('lba.patients.gender'))
                            ->maxLength(64)
                            ->helperText(__('lba.patients.gender_hint')),
                    ])
                    ->columns(2),
                Section::make(__('lba.patients.section_contact'))
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('lba.patients.phone'))
                            ->tel()
                            ->maxLength(32),
                        TextInput::make('email')
                            ->label(__('lba.patients.email'))
                            ->email()
                            ->maxLength(255),
                        Textarea::make('address')
                            ->label(__('lba.patients.address'))
                            ->rows(2)
                            ->columnSpanFull()
                            ->maxLength(255),
                        TextInput::make('city')
                            ->label(__('lba.patients.city'))
                            ->maxLength(255),
                        TextInput::make('region')
                            ->label(__('lba.patients.region'))
                            ->maxLength(255),
                        TextInput::make('country')
                            ->label(__('lba.patients.country'))
                            ->maxLength(2),
                    ])
                    ->columns(2),
                Section::make(__('lba.patients.section_registration'))
                    ->schema([
                        Select::make('registered_branch_id')
                            ->label(__('lba.patients.registration_branch'))
                            ->options(static::accessibleBranchOptions())
                            ->searchable()
                            ->required()
                            ->default(fn () => static::defaultBranchId()),
                        DateTimePicker::make('registered_at')
                            ->label(__('lba.patients.registration_date'))
                            ->default(fn () => now()),
                        Select::make('status')
                            ->label(__('lba.patients.status'))
                            ->options(static::statusOptions())
                            ->default(Patient::STATUS_ACTIVE)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('patient_number')
                    ->label(__('lba.patients.number'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('full_name')
                    ->label(__('lba.patients.full_name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label(__('lba.patients.phone'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('date_of_birth')
                    ->label(__('lba.patients.date_of_birth'))
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('registered_branch_id')
                    ->label(__('lba.patients.registration_branch'))
                    ->formatStateUsing(fn (?string $state): string => static::accessibleBranchOptions()[$state] ?? '—')
                    ->toggleable(),
                TextColumn::make('registered_at')
                    ->label(__('lba.patients.registration_date'))
                    ->date()
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('lba.patients.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.patients.status_{$state}"))
                    ->color(fn (string $state): string => $state === Patient::STATUS_ARCHIVED ? 'gray' : 'success'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.patients.filter_status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('registered_branch_id')
                    ->label(__('lba.patients.filter_branch'))
                    ->options(static::accessibleBranchOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                Action::make('archive')
                    ->label(__('lba.patients.archive'))
                    ->icon(Heroicon::OutlinedArchiveBox)
                    ->color('danger')
                    ->visible(fn (Patient $record): bool => $record->isActive())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.patients.archive_confirm'))
                    ->action(function (Patient $record): void {
                        app(PatientRegistrar::class)->archive($record, static::currentUser());
                    }),
                Action::make('restore')
                    ->label(__('lba.patients.restore'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('success')
                    ->visible(fn (Patient $record): bool => $record->isArchived())
                    ->requiresConfirmation()
                    ->action(function (Patient $record): void {
                        app(PatientRegistrar::class)->restore($record, static::currentUser());
                    }),
                // Phase 8A — bind a patient to a portal account. This is the ONLY
                // path that attaches an identity; it is authorized (a clinic
                // identity holding `patient.profile.manage`), auditable and
                // proof-based (a single-use, expiring invitation; never an
                // email/phone match).
                Action::make('link_portal_account')
                    ->label(__('lba.patients.portal_link'))
                    ->icon(Heroicon::OutlinedKey)
                    ->visible(fn (Patient $record): bool => static::allows(PermissionKey::PATIENT_PROFILE_MANAGE) && ! $record->isLinked())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.patients.portal_link_confirm'))
                    ->action(function (Patient $record): void {
                        try {
                            $code = app(PatientAccountLinker::class)->issueInvitation($record, static::currentUser());
                        } catch (PatientLinkException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->success()
                            ->title(__('lba.patients.portal_invite_issued'))
                            ->body($code)
                            ->persistent()
                            ->send();
                    }),
                Action::make('unlink_portal_account')
                    ->label(__('lba.patients.portal_unlink'))
                    ->icon(Heroicon::OutlinedKey)
                    ->color('warning')
                    ->visible(fn (Patient $record): bool => static::allows(PermissionKey::PATIENT_PROFILE_MANAGE) && $record->isLinked())
                    ->requiresConfirmation()
                    ->modalDescription(__('lba.patients.portal_unlink_confirm'))
                    ->action(function (Patient $record): void {
                        try {
                            app(PatientAccountLinker::class)->unlink($record, static::currentUser());
                        } catch (PatientLinkException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('lba.patients.portal_unlinked'))->send();
                    }),
            ])
            ->defaultSort('full_name')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedUsers)
            ->emptyStateHeading(__('lba.patients.empty_heading'))
            ->emptyStateDescription(__('lba.patients.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        // Patient resolves the verified tenant connection at query time and
        // fails closed without it; the resource therefore never lists another
        // tenant's patients. A patient's history spans every branch of the
        // tenant, so the list is tenant-scoped rather than branch-scoped.
        return parent::getEloquentQuery();
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        $relations = [ConsentsRelationManager::class];

        if (class_exists(Appointment::class)) {
            $relations[] = AppointmentHistoryRelationManager::class;
        }

        return $relations;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListPatients::route('/'),
            'create' => CreatePatient::route('/create'),
            'view' => ViewPatient::route('/{record}'),
            'edit' => EditPatient::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PATIENT_PROFILE_READ);
    }

    public static function canCreate(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PATIENT_PROFILE_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PATIENT_PROFILE_MANAGE);
    }

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::PATIENT_PROFILE_READ);
    }

    public static function canDelete(Model $record): bool
    {
        // Archiving is non-destructive; patients are never deleted.
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
            Patient::STATUS_ACTIVE => __('lba.patients.status_active'),
            Patient::STATUS_ARCHIVED => __('lba.patients.status_archived'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function sexOptions(): array
    {
        return [
            Patient::SEX_MALE => __('lba.patients.sex_male'),
            Patient::SEX_FEMALE => __('lba.patients.sex_female'),
            Patient::SEX_OTHER => __('lba.patients.sex_other'),
            Patient::SEX_UNKNOWN => __('lba.patients.sex_unknown'),
        ];
    }

    protected static function defaultBranchId(): ?string
    {
        $user = static::currentUser();

        if ($user === null) {
            return null;
        }

        return app(ManagesBranchContext::class)->currentBranchId($user);
    }
}
