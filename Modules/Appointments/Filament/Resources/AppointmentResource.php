<?php

namespace Modules\Appointments\Filament\Resources;

use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\FeatureGate\ModuleGate;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Filament\Resources\AppointmentResource\Pages\CreateAppointment;
use Modules\Appointments\Filament\Resources\AppointmentResource\Pages\EditAppointment;
use Modules\Appointments\Filament\Resources\AppointmentResource\Pages\ListAppointments;
use Modules\Appointments\Filament\Resources\AppointmentResource\Pages\ViewAppointment;
use Modules\Appointments\Filament\Resources\AppointmentResource\RelationManagers\StatusHistoryRelationManager;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Services\ConsultationManager;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Services\ExaminationRegistrar;
use Modules\Patients\Models\Patient;
use UnitEnum;

/**
 * Phase 3 — Clinic panel appointment book.
 *
 * Operates on the verified current tenant's isolated database and is further
 * scoped to the branches the acting identity may operate in, so a booking is
 * never visible across tenants and never leaks another branch's schedule.
 *
 * The status lifecycle only moves through {@see AppointmentScheduler}, which
 * validates every transition; bookings are archived via status (cancel), never
 * deleted.
 *
 * @extends resource<Appointment>
 */
class AppointmentResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'appointments';

    protected static ?string $model = Appointment::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 21;

    protected static ?string $recordTitleAttribute = 'id';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.appointments.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.appointments.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.appointments.nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.appointments.section_booking'))
                    ->schema([
                        Select::make('patient_id')
                            ->label(__('lba.appointments.patient'))
                            ->options(fn (): array => static::patientOptions())
                            ->searchable()
                            ->required(),
                        Select::make('branch_id')
                            ->label(__('lba.appointments.branch'))
                            ->options(static::accessibleBranchOptions())
                            ->default(fn (): ?string => static::defaultBranchId())
                            ->required(),
                        Select::make('service_type')
                            ->label(__('lba.appointments.service_type'))
                            ->options(static::serviceOptions())
                            ->default(Appointment::SERVICE_CONSULTATION)
                            ->required(),
                        Select::make('staff_user_id')
                            ->label(__('lba.appointments.staff'))
                            ->options(static::staffOptions())
                            ->searchable()
                            ->placeholder(__('lba.appointments.staff_unassigned')),
                    ])
                    ->columns(2),
                Section::make(__('lba.appointments.section_schedule'))
                    ->schema([
                        DateTimePicker::make('starts_at')
                            ->label(__('lba.appointments.starts_at'))
                            ->seconds(false)
                            ->required(),
                        DateTimePicker::make('ends_at')
                            ->label(__('lba.appointments.ends_at'))
                            ->seconds(false)
                            ->after('starts_at')
                            ->required(),
                    ])
                    ->columns(2),
                Section::make(__('lba.appointments.section_notes'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('lba.appointments.notes'))
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        $staffNames = static::staffOptions();

        return $table
            ->columns([
                TextColumn::make('starts_at')
                    ->label(__('lba.appointments.starts_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('patient.full_name')
                    ->label(__('lba.appointments.patient'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('branch.name')
                    ->label(__('lba.appointments.branch'))
                    ->placeholder('—'),
                TextColumn::make('staff_user_id')
                    ->label(__('lba.appointments.staff'))
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '—' : ($staffNames[$state] ?? $state))
                    ->placeholder('—'),
                TextColumn::make('service_type')
                    ->label(__('lba.appointments.service_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.appointments.service_{$state}")),
                TextColumn::make('status')
                    ->label(__('lba.appointments.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.appointments.status_{$state}"))
                    ->color(fn (string $state): string => Appointment::statusColor($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.appointments.filter_status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('branch_id')
                    ->label(__('lba.appointments.filter_branch'))
                    ->options(static::accessibleBranchOptions()),
                SelectFilter::make('staff_user_id')
                    ->label(__('lba.appointments.filter_staff'))
                    ->options(static::staffOptions())
                    ->searchable(),
                Filter::make('on_date')
                    ->label(__('lba.appointments.filter_date'))
                    ->schema([
                        DatePicker::make('on')
                            ->label(__('lba.appointments.filter_date')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['on'] ?? null),
                        fn (Builder $inner): Builder => $inner->whereDate('starts_at', $data['on']),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                static::statusAction(
                    Appointment::STATUS_CONFIRMED,
                    'lba.appointments.action_confirm',
                    Heroicon::OutlinedCheckCircle,
                    'info',
                ),
                static::statusAction(
                    Appointment::STATUS_CHECKED_IN,
                    'lba.appointments.action_check_in',
                    Heroicon::OutlinedArrowRightEndOnRectangle,
                    'warning',
                ),
                static::statusAction(
                    Appointment::STATUS_COMPLETED,
                    'lba.appointments.action_complete',
                    Heroicon::OutlinedCheckBadge,
                    'success',
                ),
                static::statusAction(
                    Appointment::STATUS_CANCELLED,
                    'lba.appointments.action_cancel',
                    Heroicon::OutlinedXCircle,
                    'danger',
                ),
                static::statusAction(
                    Appointment::STATUS_NO_SHOW,
                    'lba.appointments.action_no_show',
                    Heroicon::OutlinedUserMinus,
                    'danger',
                ),
                // WS7.C/D — the assignment interface: a checked-in appointment
                // is handed into an authorized consultation and/or LBA
                // examination using the existing services (idempotent, so an
                // appointment is never handed in twice).
                static::intakeAction(
                    'intake_consultation',
                    'lba.appointments.action_intake_consultation',
                    Heroicon::OutlinedChatBubbleLeftRight,
                    'info',
                    'consultations',
                    PermissionKey::CONSULTATION_MANAGE,
                    fn (Appointment $record, $actor) => app(ConsultationManager::class)->intakeFromAppointment($record, $actor),
                    'lba.appointments.intake_consultation_done',
                    ConsultationException::class,
                ),
                static::intakeAction(
                    'intake_examination',
                    'lba.appointments.action_intake_examination',
                    Heroicon::OutlinedBeaker,
                    'info',
                    'lba',
                    PermissionKey::EXAM_CREATE,
                    fn (Appointment $record, $actor) => app(ExaminationRegistrar::class)->intakeFromAppointment($record, $actor),
                    'lba.appointments.intake_examination_done',
                    ExaminationException::class,
                ),
            ])
            ->defaultSort('starts_at', 'desc')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedCalendarDays)
            ->emptyStateHeading(__('lba.appointments.empty_heading'))
            ->emptyStateDescription(__('lba.appointments.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        // The verified tenant connection is resolved at query time by the
        // model; the list is additionally restricted to the branches the acting
        // identity may operate in. With no accessible branch nothing is listed.
        $branches = static::accessibleBranchIds();

        return parent::getEloquentQuery()->when(
            $branches !== [],
            fn (Builder $query): Builder => $query->whereIn('branch_id', $branches),
            fn (Builder $query): Builder => $query->whereRaw('1 = 0'),
        );
    }

    /**
     * @return array<class-string>
     */
    public static function getRelations(): array
    {
        return [
            StatusHistoryRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAppointments::route('/'),
            'create' => CreateAppointment::route('/create'),
            'view' => ViewAppointment::route('/{record}'),
            'edit' => EditAppointment::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::BOOKING_READ);
    }

    public static function canCreate(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::BOOKING_MANAGE);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::BOOKING_MANAGE);
    }

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::BOOKING_READ);
    }

    public static function canDelete(Model $record): bool
    {
        // Appointments are cancelled, never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * A validated status-transition action; visible only when the transition is
     * legal and the identity may manage bookings.
     */
    protected static function statusAction(string $to, string $labelKey, Heroicon $icon, string $color): Action
    {
        return Action::make("status_{$to}")
            ->label(__($labelKey))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (Appointment $record): bool => static::moduleActive()
                && static::allows(PermissionKey::BOOKING_MANAGE)
                && $record->canTransitionTo($to))
            ->action(function (Appointment $record) use ($to): void {
                try {
                    app(AppointmentScheduler::class)->transition($record, $to, static::currentUser());

                    Notification::make()
                        ->success()
                        ->title(__('lba.appointments.status_changed'))
                        ->send();
                } catch (AppointmentConflictException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($exception->getMessage())
                        ->send();
                }
            });
    }

    /**
     * WS7.C/D — an idempotent "hand this checked-in appointment into a
     * workflow" action (consultation / LBA examination).
     *
     * Visible only when the appointment is checked in, the target module is
     * active for the partner and the identity holds both the booking-manage and
     * target permissions. The write goes through the module's service, which
     * re-derives authorization (practitioner eligibility, branch scope), so the
     * UI is never the security boundary. Because the service is idempotent, an
     * appointment is never handed in twice.
     *
     * @param  callable(Appointment, ?User): mixed  $operation
     * @param  class-string<\Throwable>  $domainException
     */
    protected static function intakeAction(
        string $name,
        string $labelKey,
        Heroicon $icon,
        string $color,
        string $moduleKey,
        string $permission,
        callable $operation,
        string $successKey,
        string $domainException,
    ): Action {
        return Action::make($name)
            ->label(__($labelKey))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (Appointment $record): bool => static::moduleActive()
                && static::allows(PermissionKey::BOOKING_MANAGE)
                && static::allows($permission)
                && app(ModuleGate::class)->allows($moduleKey, PanelAccessPolicy::PANEL_CLINIC)
                && (string) $record->status === Appointment::STATUS_CHECKED_IN)
            ->action(function (Appointment $record) use ($operation, $successKey, $domainException): void {
                try {
                    $operation($record, static::currentUser());
                } catch (\Throwable $caught) {
                    if (! $caught instanceof $domainException) {
                        throw $caught;
                    }

                    Notification::make()->danger()->title($caught->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title(__($successKey))->send();
            });
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (Appointment::STATUSES as $status) {
            $options[$status] = __("lba.appointments.status_{$status}");
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

    /**
     * Active patients of the verified tenant, for the booking picker.
     *
     * @return array<string, string>
     */
    protected static function patientOptions(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return Patient::query()
            ->active()
            ->orderBy('full_name')
            ->pluck('full_name', 'id')
            ->all();
    }

    /**
     * Active staff members of the verified tenant, for assignment and filters.
     *
     * @return array<string, string>
     */
    protected static function staffOptions(): array
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null) {
            return [];
        }

        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (TenantMembership $membership): array => [
                (string) $membership->user_id => $membership->user?->name ?? (string) $membership->user_id,
            ])
            ->all();
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
