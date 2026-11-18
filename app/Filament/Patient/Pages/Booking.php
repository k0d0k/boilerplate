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
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Services\PractitionerAvailability;
use Modules\Practitioners\Services\PractitionerBooking;
use Throwable;

/**
 * Phase 8A — patient self-booking ("Booking Pemeriksaan").
 *
 * A patient books a slot at one ACTIVE branch of the verified tenant. The write
 * goes through the SAME {@see AppointmentScheduler} the clinic uses, so every
 * guard applies unchanged (tenant-local patient/branch existence, a valid time
 * range) and the booking is auditable through the appointment status history.
 *
 * The actor is always the signed-in patient and `patient_id` is FORCED to that
 * identity's OWN patient record — a patient can only ever book for themselves.
 * Requires the `booking.own` self-service permission.
 *
 * WS7.D — when the patient optionally chooses a VERIFIED practitioner, the
 * booking is routed through {@see PractitionerBooking} instead, so the request
 * is additionally serialised (no double-booking) and only a practitioner whose
 * verification, branch authorization and own practice schedule all allow the
 * chosen slot can be reserved. A practitioner is only ever offered when they
 * are bookable at the chosen branch; leaving the choice empty keeps the legacy
 * "clinic assigns" behaviour, and only non-clinical scheduling data is exposed.
 */
class Booking extends Page
{
    use AuthorizesPatientPortal;

    public const MODULE_DURATION_MINUTES = 30;

    /** Services for which a named clinician may be chosen. */
    public const CLINICIAN_SERVICES = [
        Appointment::SERVICE_CONSULTATION,
        Appointment::SERVICE_LBA_EXAM,
    ];

    protected static ?string $slug = 'booking';

    protected static ?int $navigationSort = 11;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected string $view = 'filament.patient.pages.booking';

    public ?string $branchId = null;

    public ?string $startsAt = null;

    public ?string $serviceType = null;

    public ?string $practitionerId = null;

    public ?string $slotDate = null;

    public ?string $notes = null;

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::BOOKING_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.booking');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.booking.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.booking.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.booking.subtitle');
    }

    public function mount(): void
    {
        $this->serviceType ??= Appointment::SERVICE_CONSULTATION;
        $this->startsAt ??= now()->addDay()->startOfHour()->format('Y-m-d H:i');
        $this->slotDate ??= now()->addDay()->toDateString();

        // A branch hint from "Cari Lokasi" is only adopted when it matches an
        // active branch of this tenant; any other value is ignored, so the
        // query parameter can never widen the selectable set.
        $requested = request()->query('branch');

        if (filled($requested) && array_key_exists((string) $requested, $this->branchOptions())) {
            $this->branchId = (string) $requested;
        }
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
     * @return array<string, string>
     */
    public function serviceOptions(): array
    {
        $options = [];

        foreach (Appointment::SERVICES as $service) {
            $options[$service] = __("lba.patient.booking.services.{$service}");
        }

        return $options;
    }

    /**
     * Whether the currently selected service admits a named clinician. Used by
     * the form to reveal the practitioner + slot pickers only where relevant.
     */
    public function requiresClinician(): bool
    {
        return in_array($this->serviceType, self::CLINICIAN_SERVICES, true);
    }

    /**
     * Verified practitioners bookable at the selected branch (id => label).
     * Empty until a branch is chosen or when the clinic offers no verified
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

            foreach (app(PractitionerAvailability::class)->slotsForDate($practitioner, $branch, $date, $this->serviceType) as $slot) {
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

    public function submit(): void
    {
        // Self-only: the booking is always for the acting identity's OWN record.
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::BOOKING_OWN)) {
            abort(403);
        }

        $validated = $this->validate([
            'branchId' => ['required', 'string'],
            'startsAt' => ['required', 'date', 'after:now'],
            'serviceType' => ['required', 'string', 'in:'.implode(',', Appointment::SERVICES)],
            'practitionerId' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $branch = $this->selectedBranch();

        if ($branch === null || ! array_key_exists($validated['branchId'], $this->branchOptions())) {
            $this->addError('branchId', __('lba.patient.booking.errors.branch'));

            return;
        }

        $practitioner = $this->selectedPractitioner();

        // A chosen practitioner must be one of the bookable options for the
        // branch; a forged id is refused rather than silently ignored.
        if (filled($validated['practitionerId'] ?? null)
            && ($practitioner === null || ! array_key_exists((string) $practitioner->getKey(), $this->clinicianOptions()))) {
            $this->addError('practitionerId', __('lba.patient.booking.errors.practitioner'));

            return;
        }

        $startsAt = Carbon::parse($validated['startsAt']);

        try {
            if ($practitioner !== null) {
                app(PractitionerBooking::class)->reserve(
                    $practitioner,
                    $branch,
                    $patient,
                    $startsAt,
                    $validated['serviceType'],
                    notes: $validated['notes'] ?? null,
                    actor: auth()->user(),
                );
            } else {
                app(AppointmentScheduler::class)->create([
                    'patient_id' => $patient->getKey(),
                    'branch_id' => $validated['branchId'],
                    'starts_at' => $startsAt,
                    'ends_at' => $startsAt->copy()->addMinutes(self::MODULE_DURATION_MINUTES),
                    'service_type' => $validated['serviceType'],
                    'status' => Appointment::STATUS_SCHEDULED,
                    'notes' => $validated['notes'] ?? null,
                ], auth()->user());
            }
        } catch (AppointmentConflictException $exception) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.booking.errors.failed'))
                ->body($exception->getMessage())
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.patient.booking.created'))
            ->send();

        $this->redirect(MyAppointments::getUrl(panel: 'patient'));
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
}
