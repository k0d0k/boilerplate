<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\PermissionKey;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\Appointments\Exceptions\AppointmentConflictException;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Services\AppointmentScheduler;
use Throwable;

/**
 * Phase 8A — "Janji Saya".
 *
 * Lists ONLY the acting identity's own appointments (filtered by their own
 * patient id) and lets them cancel a cancellable one. The cancellation runs
 * through the same {@see AppointmentScheduler} transition used by the clinic,
 * so the lifecycle rules are enforced identically. Requires `booking.own`.
 */
class MyAppointments extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'janji-saya';

    protected static ?int $navigationSort = 12;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected string $view = 'filament.patient.pages.my-appointments';

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::BOOKING_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.my_appointments');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.appointments.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.appointments.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.appointments.subtitle');
    }

    /**
     * The acting identity's OWN appointments, newest first. Fails closed.
     *
     * @return Collection<int, Appointment>
     */
    public function appointments(): Collection
    {
        $patient = $this->patient();

        if ($patient === null) {
            return collect();
        }

        try {
            return Appointment::query()
                ->with('branch')
                ->where('patient_id', $patient->getKey())
                ->orderByDesc('starts_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    public function canCancel(Appointment $appointment): bool
    {
        return $appointment->canTransitionTo(Appointment::STATUS_CANCELLED);
    }

    public function cancel(string $appointmentId): void
    {
        $patient = $this->requirePatient();

        if (! static::allows(PermissionKey::BOOKING_OWN)) {
            abort(403);
        }

        // Self-only: an appointment that is not the acting identity's own is
        // never loaded, so a foreign id simply resolves to nothing.
        $appointment = Appointment::query()
            ->whereKey($appointmentId)
            ->where('patient_id', $patient->getKey())
            ->first();

        abort_if($appointment === null, 404);

        try {
            app(AppointmentScheduler::class)->transition(
                $appointment,
                Appointment::STATUS_CANCELLED,
                auth()->user(),
                __('lba.patient.appointments.cancelled_note'),
            );
        } catch (AppointmentConflictException) {
            Notification::make()
                ->danger()
                ->title(__('lba.patient.appointments.errors.cancel'))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('lba.patient.appointments.cancelled'))
            ->send();
    }

    public function statusLabel(string $status): string
    {
        return __("lba.patient.appointments.status.{$status}");
    }
}
