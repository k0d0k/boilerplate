<?php

namespace Modules\LBA\Filament\Resources;

use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Appointments\Models\Appointment;
use Modules\LBA\AI\Services\AiAnalysisService;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Filament\Pages\MicroscopeWorkspace;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\CreateExamination;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\EditExamination;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ListAiExaminations;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ListExaminations;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ListHybridExaminations;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ListManualExaminations;
use Modules\LBA\Filament\Resources\ExaminationResource\Pages\ViewExamination;
use Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers\AnalysisJobsRelationManager;
use Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers\AnnotationsRelationManager;
use Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers\EventsRelationManager;
use Modules\LBA\Filament\Resources\ExaminationResource\RelationManagers\ImagesRelationManager;
use Modules\LBA\Models\Examination;
use Modules\LBA\Services\ExaminationWorkflow;
use Modules\Patients\Models\Patient;
use Modules\Patients\Models\PatientConsent;
use UnitEnum;

/**
 * Phase 4 — Clinic panel LBA examination registry.
 *
 * Operates on the verified current tenant's isolated database and is further
 * scoped to the branches the acting identity may operate in, so an examination
 * is never visible across tenants and never leaks another branch's work.
 *
 * Only the manual workflow is functional in Phase 4: `ai` and `hybrid` may be
 * recorded but are clearly marked as not yet available, and no examination can
 * ever be auto-approved or released to a patient (human review is Phase 7).
 *
 * @extends resource<Examination>
 */
class ExaminationResource extends Resource
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'lba';

    protected static ?string $model = Examination::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 23;

    protected static ?string $recordTitleAttribute = 'id';

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function getModelLabel(): string
    {
        return __('lba.exams.single');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lba.exams.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.exams.nav_all');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.exams.nav_group');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('lba.exams.section_subject'))
                    ->schema([
                        Select::make('patient_id')
                            ->label(__('lba.exams.patient'))
                            ->options(fn (): array => static::patientOptions())
                            ->searchable()
                            ->required()
                            ->live(),
                        Select::make('branch_id')
                            ->label(__('lba.exams.branch'))
                            ->options(static::accessibleBranchOptions())
                            ->default(fn (): ?string => static::defaultBranchId())
                            ->required(),
                        Select::make('appointment_id')
                            ->label(__('lba.exams.appointment'))
                            ->options(static::appointmentOptions())
                            ->searchable()
                            ->placeholder(__('lba.exams.appointment_none')),
                    ])
                    ->columns(2),
                Section::make(__('lba.exams.section_details'))
                    ->schema([
                        Select::make('operator_user_id')
                            ->label(__('lba.exams.operator'))
                            ->options(static::staffOptions())
                            ->default(fn (): ?string => static::currentUser()?->getKey())
                            ->searchable()
                            ->required(),
                        Select::make('mode')
                            ->label(__('lba.exams.mode'))
                            ->options(static::modeOptions())
                            ->default(Examination::MODE_MANUAL)
                            ->required()
                            ->helperText(__('lba.exams.mode_hint')),
                        DateTimePicker::make('captured_at')
                            ->label(__('lba.exams.captured_at'))
                            ->seconds(false)
                            ->default(fn () => now()),
                        TextInput::make('instrument')
                            ->label(__('lba.exams.instrument'))
                            ->maxLength(120),
                        TextInput::make('magnification')
                            ->label(__('lba.exams.magnification'))
                            ->maxLength(60),
                    ])
                    ->columns(2),
                Section::make(__('lba.exams.section_consent'))
                    ->description(__('lba.exams.consent_notice'))
                    ->schema([
                        // Consent is an EXPLICIT affirmative action: the toggle
                        // has deliberately NO default, so an operator must
                        // actively record (or explicitly select) the specific
                        // examination image-processing consent. Until then the
                        // examination is saved as a draft and image processing
                        // stays refused.
                        Toggle::make('record_consent')
                            ->label(__('lba.exams.record_consent'))
                            ->default(false)
                            ->helperText(__('lba.exams.record_consent_hint')),
                        Select::make('consent_id')
                            ->label(__('lba.exams.consent_reference'))
                            ->options(fn (Get $get): array => static::consentOptions($get('patient_id')))
                            ->placeholder(__('lba.exams.consent_none'))
                            ->visible(fn (Get $get): bool => ! static::truthy($get('record_consent'))),
                    ])
                    ->columns(2),
                Section::make(__('lba.exams.section_notes'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('lba.exams.notes'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('captured_at')
                    ->label(__('lba.exams.captured_at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('patient.full_name')
                    ->label(__('lba.exams.patient'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('id')
                    ->label(__('lba.exams.id'))
                    ->searchable()
                    ->copyable()
                    ->limit(12),
                TextColumn::make('mode')
                    ->label(__('lba.exams.mode'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.exams.mode_{$state}"))
                    ->color(fn (string $state): string => Examination::modeColor($state)),
                TextColumn::make('status')
                    ->label(__('lba.exams.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __("lba.exams.status_{$state}"))
                    ->color(fn (string $state): string => Examination::statusColor($state)),
                TextColumn::make('images_count')
                    ->label(__('lba.exams.image_count'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('branch.name')
                    ->label(__('lba.exams.branch'))
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('lba.exams.filter_status'))
                    ->options(static::statusOptions()),
                SelectFilter::make('mode')
                    ->label(__('lba.exams.filter_mode'))
                    ->options(static::modeOptions()),
                SelectFilter::make('branch_id')
                    ->label(__('lba.exams.filter_branch'))
                    ->options(static::accessibleBranchOptions()),
                Filter::make('captured_on')
                    ->label(__('lba.exams.filter_date'))
                    ->schema([
                        DatePicker::make('on')->label(__('lba.exams.filter_date')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query->when(
                        filled($data['on'] ?? null),
                        fn (Builder $inner): Builder => $inner->whereDate('captured_at', $data['on']),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->visible(fn (Examination $record): bool => static::canEdit($record)),
                Action::make('workspace')
                    ->label(__('lba.exams.open_workspace'))
                    ->icon(Heroicon::OutlinedViewfinderCircle)
                    ->color('primary')
                    ->url(fn (Examination $record): string => MicroscopeWorkspace::getUrl(['examination' => $record]))
                    ->visible(fn (Examination $record): bool => static::canEdit($record)),
                static::workflowAction(
                    Examination::STATUS_REVIEW_REQUIRED,
                    'lba.exams.action_submit_review',
                    Heroicon::OutlinedPaperAirplane,
                    'success',
                ),
                static::workflowAction(
                    Examination::STATUS_CANCELLED,
                    'lba.exams.action_cancel',
                    Heroicon::OutlinedXCircle,
                    'danger',
                ),
            ])
            ->defaultSort('captured_at', 'desc')
            ->striped()
            ->emptyStateIcon(Heroicon::OutlinedBeaker)
            ->emptyStateHeading(__('lba.exams.empty_heading'))
            ->emptyStateDescription(__('lba.exams.empty_description'));
    }

    public static function getEloquentQuery(): Builder
    {
        $branches = static::accessibleBranchIds();

        return parent::getEloquentQuery()
            ->withCount('images')
            ->when(
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
            ImagesRelationManager::class,
            AnalysisJobsRelationManager::class,
            AnnotationsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListExaminations::route('/'),
            'manual' => ListManualExaminations::route('/manual'),
            'ai' => ListAiExaminations::route('/ai'),
            'hybrid' => ListHybridExaminations::route('/hybrid'),
            'create' => CreateExamination::route('/create'),
            'view' => ViewExamination::route('/{record}'),
            'edit' => EditExamination::route('/{record}/edit'),
        ];
    }

    public static function canViewAny(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_READ);
    }

    public static function canCreate(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_CREATE);
    }

    public static function canView(Model $record): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_READ);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::EXAM_UPDATE)
            && $record instanceof Examination
            && $record->isEditable();
    }

    public static function canDelete(Model $record): bool
    {
        // Examinations are cancelled, never deleted.
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * Whether the acting identity may MOVE this examination to `$to`.
     *
     * This is the server-side gate reused by the TailAdmin case detail page; it
     * deliberately does NOT require the record to be editable, because a
     * cancelled/returned case may still need to be withdrawn. UI visibility is
     * never the authorization boundary.
     */
    public static function canTransition(Model $record, string $to): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::EXAM_UPDATE)
            && $record instanceof Examination
            && $record->canTransitionTo($to);
    }

    /**
     * Whether the acting identity may RECORD an assessment for an examination
     * (module gate plus `exam.update` under the verified tenant/branch context),
     * independent of whether the examination itself is still editable.
     *
     * Reused by the TailAdmin assessment page; UI visibility is never the
     * authorization boundary.
     */
    public static function canRecord(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_UPDATE);
    }

    /**
     * Whether the acting identity may REVIEW a submitted assessment (module gate
     * plus `exam.review` under the verified tenant/branch context).
     */
    public static function canReview(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::EXAM_REVIEW);
    }

    /**
     * Whether the acting identity may APPROVE and RELEASE a patient-facing
     * report (module gate plus `report.approve` under the verified tenant/branch
     * context). The reviewer service re-derives the same fact and additionally
     * enforces separation of duties.
     */
    public static function canApproveReport(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::REPORT_APPROVE);
    }

    /**
     * Whether the acting identity may START an AI vision analysis for this
     * examination. This is the server-side gate; UI visibility is not the
     * authorization boundary.
     */
    public static function canStartAiAnalysis(Model $record): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::EXAM_AI_START)
            && $record instanceof Examination
            && $record->isAiCapable()
            && $record->isEditable()
            && $record->hasGrantedImageProcessingConsent()
            && app(AiAnalysisService::class)->aiEnabled();
    }

    /**
     * Whether the acting identity may retry a FAILED analysis job. Retrying is
     * an explicit, idempotent, attempt-limited domain action.
     */
    public static function canRetryAiAnalysis(Model $record): bool
    {
        return static::moduleActive()
            && static::allows(PermissionKey::EXAM_AI_START)
            && $record instanceof Examination
            && app(AiAnalysisService::class)->aiEnabled();
    }

    /**
     * The acting identity, or null when unauthenticated / inactive.
     */
    public static function currentActor(): ?User
    {
        return static::currentUser();
    }

    /**
     * A validated workflow-transition action; visible only when the transition
     * is legal and the identity may update examinations.
     */
    protected static function workflowAction(string $to, string $labelKey, Heroicon $icon, string $color): Action
    {
        return Action::make("workflow_{$to}")
            ->label(__($labelKey))
            ->icon($icon)
            ->color($color)
            ->requiresConfirmation()
            ->visible(fn (Examination $record): bool => static::canTransition($record, $to))
            ->action(function (Examination $record) use ($to): void {
                try {
                    app(ExaminationWorkflow::class)->transition($record, $to, static::currentUser());

                    Notification::make()
                        ->success()
                        ->title(__('lba.exams.status_changed'))
                        ->send();
                } catch (ExaminationException $exception) {
                    Notification::make()
                        ->danger()
                        ->title($exception->getMessage())
                        ->send();
                }
            });
    }

    /**
     * @return array<string, string>
     */
    protected static function statusOptions(): array
    {
        $options = [];

        foreach (Examination::STATUSES as $status) {
            $options[$status] = __("lba.exams.status_{$status}");
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function modeOptions(): array
    {
        $options = [];

        foreach (Examination::MODES as $mode) {
            $options[$mode] = __("lba.exams.mode_{$mode}");
        }

        return $options;
    }

    /**
     * Active patients of the verified tenant, for the subject picker.
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
     * Appointments of the verified tenant, for the optional link.
     *
     * @return array<string, string>
     */
    protected static function appointmentOptions(): array
    {
        if (app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return Appointment::query()
            ->with('patient')
            ->orderByDesc('starts_at')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Appointment $appointment): array => [
                (string) $appointment->getKey() => trim(sprintf(
                    '%s · %s',
                    $appointment->patient?->full_name ?? '—',
                    $appointment->starts_at?->format('Y-m-d H:i') ?? '',
                )),
            ])
            ->all();
    }

    /**
     * The patient's currently granted examination image-processing consents.
     *
     * @return array<string, string>
     */
    protected static function consentOptions(?string $patientId): array
    {
        if (blank($patientId) || app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return PatientConsent::query()
            ->where('patient_id', $patientId)
            ->where('consent_type', PatientConsent::TYPE_EXAMINATION_IMAGE_PROCESSING)
            ->where('status', PatientConsent::STATUS_GRANTED)
            ->whereNull('withdrawn_at')
            ->orderByDesc('recorded_at')
            ->get()
            ->mapWithKeys(fn (PatientConsent $consent): array => [
                (string) $consent->getKey() => sprintf(
                    '%s · %s',
                    __('lba.exams.consent_reference'),
                    $consent->recorded_at?->format('Y-m-d') ?? '',
                ),
            ])
            ->all();
    }

    /**
     * Active staff members of the verified tenant.
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

    protected static function truthy(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
