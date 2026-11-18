<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\Patients\Exceptions\PatientLinkException;
use Modules\Patients\Services\PatientAccountLinker;

/**
 * Phase 8A — "Tautkan Akun Klinik".
 *
 * The patient-side half of the clinic-issued invitation flow. A clinic identity
 * holding `patient.profile.manage` issues a single-use, expiring proof for ONE
 * patient record; the patient presents that proof here to bind their own
 * account.
 *
 * This page NEVER binds by email or phone — only a matching, unexpired proof
 * binds, and the write (with its audit event) is performed by
 * {@see PatientAccountLinker} against the verified tenant database. A patient
 * with no verified clinic context cannot reach it.
 */
class LinkAccount extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'tautkan-akun';

    protected static ?int $navigationSort = 15;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected string $view = 'filament.patient.pages.link-account';

    public ?string $invitationCode = null;

    public static function canAccess(): bool
    {
        return static::hasPatientContext();
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.patient.link.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.link.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.link.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.link.subtitle');
    }

    /**
     * True once the acting identity already has a bound patient record.
     */
    public function isLinked(): bool
    {
        return $this->patient()?->isLinked() ?? false;
    }

    public function link(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            abort(403);
        }

        $validated = $this->validate(
            ['invitationCode' => ['required', 'string', 'max:255']],
            attributes: ['invitationCode' => __('lba.patient.link.code')],
        );

        try {
            app(PatientAccountLinker::class)->redeemInvitation($user, (string) $validated['invitationCode']);
        } catch (PatientLinkException $exception) {
            $this->addError('invitationCode', $exception->getMessage());

            return;
        }

        $this->invitationCode = null;

        Notification::make()->success()->title(__('lba.patient.link.linked'))->send();

        $this->redirect(Dashboard::getUrl(panel: 'patient'));
    }
}
