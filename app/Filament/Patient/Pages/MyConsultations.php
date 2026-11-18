<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Models\TenantBranch;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Appointments\Models\Appointment;
use Modules\Commerce\Support\Currency;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Services\ConsultationBooking;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\ConsultationTariffService;
use Modules\Consultations\Support\ConsultationTariff;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerAvailability;
use Throwable;

/**
 * Workstream 2 — "Konsultasi Saya".
 *
 * A patient books a HUMAN expert session (in person or teleconsult) at one
 * ACTIVE branch of the verified tenant and reviews their own history. The write
 * goes through the SAME {@see ConsultationManager} the clinic uses, so
 * tenant-local existence and lifecycle guards apply unchanged. `patient_id` is
 * FORCED to the identity's OWN record — a patient can only ever act on
 * themselves. A not-yet-started session may be cancelled while the clinic's
 * disclosed policy allows it.
 *
 * Lists ONLY the acting identity's own consultations and fails closed: a
 * foreign id is simply never loaded, and the tenant connection guarantees
 * cross-tenant consultations are invisible. Requires `consultation.own`.
 */
class MyConsultations extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'konsultasi-saya';

    protected static ?int $navigationSort = 13;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected string $view = 'filament.patient.pages.my-consultations';

    public ?string $branchId = null;

    public ?string $channel = null;

    public ?string $scheduledAt = null;

    public ?string $reason = null;

    public ?string $practitionerId = null;

    public ?string $slotDate = null;

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::CONSULTATION_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.patient.consultations.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.consultations.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.consultations.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.consultations.subtitle');
    }

    public function mount(): void
    {
        $this->channel ??= Consultation::CHANNEL_IN_PERSON;
        $this->scheduledAt ??= now()->addDay()->startOfHour()->format('Y-m-d H:i');
        $this->slotDate ??= now()->addDay()->toDateString();
    }

    /**
     * Active branches of the verified tenant as id => name options.
     *
     * @return array<string, string>
     */
    public function branchOptions(): array
    {
        try {
            return TenantBranch::query()
                ->active()
                ->orderBy('name')
                ->pluck('name', 'id')
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Channels a patient may book directly. SINA chat is started from its own
     * page, so it is never offered here.
     *
     * @return array<string, string>
     */
    public function channelOptions(): array
    {
        return [
            Consultation::CHANNEL_IN_PERSON => __('lba.consultations.channel_in_person'),
            Consultation::CHANNEL_TELECONSULT => __('lba.consultations.channel_teleconsult'),
        ];
    }

    /**
     * Verified practitioners bookable at the selected branch (id => label).
     * Empty until a branch is chosen, or when the clinic offers no verified
     * practitioner — the patient then keeps the "clinic assigns" fallback.
     *
     * @return array<string, string>
     */
    public function clinicianOptions(): array
    {
        $branch = $this->selectedBranch();

        if ($branch === null) {
            return [];
        }

        try {
            return app(PractitionerAvailability::class)
                ->bookablePractitioners($branch)
                ->mapWithKeys(fn (Practitioner $practitioner): array => [
                    (string) $practitioner->getKey() => trim(implode(' ', array_filter([
                        $practitioner->title,
                        $practitioner->display_name,
                    ]))),
                ])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Bookable slots of the selected practitioner on the selected date, as
     * ISO-8601 start => local time label. Empty when no practitioner/date is
     * chosen or nothing is available.
     *
     * @return array<string, string>
     */
    public function slotOptions(): array
    {
        $branch = $this->selectedBranch();
        $practitioner = $this->selectedPractitioner();

        if ($branch === null || $practitioner === null || blank($this->slotDate)) {
            return [];
        }

        try {
            $date = Carbon::parse((string) $this->slotDate);
            $timezone = $branch->timezone ?: (string) config('app.timezone');

            $options = [];

            foreach (app(PractitionerAvailability::class)
                ->slotsForDate($practitioner, $branch, $date, Appointment::SERVICE_CONSULTATION) as $slot) {
                if (! $slot['available']) {
                    continue;
                }

                $startsAt = Carbon::parse($slot['starts_at'])->setTimezone($timezone);

                $options[$slot['starts_at']] = $startsAt->format('H:i');
            }

            return $options;
        } catch (Throwable) {
            return [];
        }
    }

    private function selectedBranch(): ?TenantBranch
    {
        if (blank($this->branchId)) {
            return null;
        }

        try {
            return TenantBranch::query()
                ->active()
                ->whereKey($this->branchId)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function selectedPractitioner(): ?Practitioner
    {
        if (blank($this->practitionerId)) {
            return null;
        }

        try {
            return Practitioner::query()->whereKey($this->practitionerId)->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Book a human consultation for the acting identity's own patient record.
     */
    public function submit(): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::CONSULTATION_OWN)) {
            abort(403);
        }

        $validated = $this->validate([
            'branchId' => ['required', 'string'],
            'channel' => ['required', 'string', Rule::in([
                Consultation::CHANNEL_IN_PERSON,
                Consultation::CHANNEL_TELECONSULT,
            ])],
            'scheduledAt' => ['required', 'date', 'after:now'],
            'reason' => ['nullable', 'string', 'max:500'],
            'practitionerId' => ['nullable', 'string'],
        ]);

        $branch = $this->selectedBranch();

        if ($branch === null || ! array_key_exists($validated['branchId'], $this->branchOptions())) {
            $this->addError('branchId', __('lba.patient.consultations.errors.branch'));

            return;
        }

        // A chosen practitioner must be one of the bookable options for the
        // branch; a forged id is refused rather than silently downgraded to the
        // "clinic assigns" fallback.
        $practitioner = $this->selectedPractitioner();
        $practitionerId = $validated['practitionerId'] ?? null;

        if (filled($practitionerId)
            && ($practitioner === null || ! array_key_exists((string) $practitioner->getKey(), $this->clinicianOptions()))) {
            $this->addError('practitionerId', __('lba.patient.consultations.errors.practitioner'));

            return;
        }

        try {
            app(ConsultationBooking::class)->book(
                $branch,
                [
                    'patient_id' => $patient->getKey(),
                    'branch_id' => $validated['branchId'],
                    'channel' => $validated['channel'],
                    'type' => Consultation::TYPE_CONSULTATION,
                    'status' => Consultation::STATUS_SCHEDULED,
                    'scheduled_at' => Carbon::parse($validated['scheduledAt']),
                    'reason' => $validated['reason'] ?? null,
                ],
                filled($practitionerId) ? (string) $practitionerId : null,
                auth()->user(),
            );
        } catch (ConsultationException $exception) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.consultations.errors.failed'))
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.patient.consultations.created'))
            ->send();

        $this->reset('reason', 'practitionerId');
        $this->scheduledAt = now()->addDay()->startOfHour()->format('Y-m-d H:i');
    }

    /**
     * Cancel the acting identity's OWN not-yet-started consultation.
     */
    public function cancel(string $consultationId): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::CONSULTATION_OWN)) {
            abort(403);
        }

        $consultation = $this->ownConsultation($consultationId, (string) $patient->getKey());

        if ($consultation === null || ! $this->canCancel($consultation)) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.consultations.errors.not_cancellable'))
                ->send();

            return;
        }

        try {
            app(ConsultationManager::class)->transition(
                $consultation,
                Consultation::STATUS_CANCELLED,
                auth()->user(),
            );
        } catch (ConsultationException $exception) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.consultations.errors.not_cancellable'))
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.patient.consultations.cancelled'))
            ->send();
    }

    /**
     * Whether the patient may cancel this consultation right now: the clinic's
     * disclosed policy allows it, the session has not started and the lifecycle
     * admits the transition.
     */
    public function canCancel(Consultation $consultation): bool
    {
        return $this->cancellableBeforeStart()
            && $consultation->status === Consultation::STATUS_SCHEDULED
            && $consultation->started_at === null
            && $consultation->canTransitionTo(Consultation::STATUS_CANCELLED);
    }

    private function ownConsultation(string $consultationId, string $patientId): ?Consultation
    {
        try {
            return Consultation::query()
                ->whereKey($consultationId)
                ->where('patient_id', $patientId)
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The acting identity's OWN consultations, newest first. Fails closed.
     *
     * @return Collection<int, Consultation>
     */
    public function consultations(): Collection
    {
        $patient = $this->patient();

        if ($patient === null) {
            return collect();
        }

        try {
            return Consultation::query()
                ->with('branch')
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('scheduled_at')
                ->orderByDesc('created_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function statusLabel(string $status): string
    {
        return __("lba.patient.consultations.status.{$status}");
    }

    public function channelLabel(string $channel): string
    {
        return __("lba.consultations.channel_{$channel}");
    }

    /**
     * The configured tariff for this consultation's channel, or null when the
     * clinic has not priced it. Fails closed — never a fabricated price.
     */
    public function tariffFor(Consultation $consultation): ?ConsultationTariff
    {
        try {
            return app(ConsultationTariffService::class)->forConsultation($consultation);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The tariff formatted for display, or null when none is configured.
     */
    public function tariffLabel(Consultation $consultation): ?string
    {
        $tariff = $this->tariffFor($consultation);

        return $tariff === null ? null : Currency::format($tariff->amount, $tariff->currency);
    }

    public function cancellableBeforeStart(): bool
    {
        try {
            return app(ConsultationTariffService::class)->cancellableBeforeStart();
        } catch (Throwable) {
            return false;
        }
    }

    public function refundable(): bool
    {
        try {
            return app(ConsultationTariffService::class)->refundable();
        } catch (Throwable) {
            return false;
        }
    }
}
