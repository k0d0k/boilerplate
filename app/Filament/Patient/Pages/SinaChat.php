<?php

namespace App\Filament\Patient\Pages;

use App\Filament\Patient\Pages\Concerns\AuthorizesPatientPortal;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Models\TenantBranch;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\ConsultationTariffService;
use Modules\Consultations\Services\SinaChatService;
use Modules\Consultations\Services\SinaConsentService;
use Modules\Consultations\Services\SinaWalletService;
use Throwable;

/**
 * Workstream 2 — patient SINA consultation chat.
 *
 * A patient runs their own SINA chat session. Three server-derived conditions
 * gate it, all fail-closed: a verified patient context, the `consultation.own`
 * permission, and an explicitly recorded `ai_assistance` consent. The chat
 * itself is delegated to {@see SinaChatService}, which enforces the consent,
 * the active consultation and the per-turn credit accounting.
 */
class SinaChat extends Page
{
    use AuthorizesPatientPortal;

    protected static ?string $slug = 'sina';

    protected static ?int $navigationSort = 14;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected string $view = 'filament.patient.pages.sina-chat';

    public string $draft = '';

    public static function canAccess(): bool
    {
        return static::hasPatientContext() && static::allows(PermissionKey::CONSULTATION_OWN);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.nav.patient.consultation_sina');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('lba.patient.nav_group');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.patient.sina.title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.patient.sina.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.patient.sina.subtitle');
    }

    /**
     * The acting identity's OWN active SINA session, if any. Fails closed.
     */
    public function session(): ?Consultation
    {
        $patient = $this->patient();

        if ($patient === null) {
            return null;
        }

        try {
            return Consultation::query()
                ->where('patient_id', $patient->getKey())
                ->where('channel', Consultation::CHANNEL_SINA_CHAT)
                ->whereIn('status', Consultation::ACTIVE_STATUSES)
                ->latest('created_at')
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return Collection<int, ConsultationMessage>
     */
    public function messages(): Collection
    {
        $session = $this->session();

        if ($session === null) {
            return collect();
        }

        try {
            return ConsultationMessage::query()
                ->where('consultation_id', $session->getKey())
                ->orderBy('created_at')
                ->get();
        } catch (Throwable) {
            return collect();
        }
    }

    /**
     * The VALIDATED source citations attached to a knowledge-grounded reply.
     *
     * @return list<array{document_id: string, version: int, page: int, title: string, published_on: ?string, url: string}>
     */
    public function citationsFor(ConsultationMessage $message): array
    {
        $sources = data_get($message->metadata, 'grounding.sources', []);

        return is_array($sources) ? array_values(array_filter($sources, 'is_array')) : [];
    }

    /**
     * Whether RAG grounding metadata is present for this message (active module).
     */
    public function hasGrounding(ConsultationMessage $message): bool
    {
        return data_get($message->metadata, 'grounding') !== null;
    }

    public function hasConsent(): bool
    {
        $patient = $this->patient();

        if ($patient === null) {
            return false;
        }

        try {
            return app(SinaConsentService::class)->hasConsent($patient);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether SINA turns are metered against the patient's own credit wallet.
     */
    public function billingEnabled(): bool
    {
        try {
            return app(ConsultationTariffService::class)->sinaBillingEnabled();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The credits reserved around one turn — the transparent configured fee unit
     * the patient is shown BEFORE anything is charged.
     */
    public function creditsPerTurn(): int
    {
        try {
            return app(ConsultationTariffService::class)->sinaCreditsPerTurn();
        } catch (Throwable) {
            return 0;
        }
    }

    public function creditsPerThousandTokens(): int
    {
        try {
            return app(ConsultationTariffService::class)->sinaCreditsPerThousandTokens();
        } catch (Throwable) {
            return 0;
        }
    }

    public function creditCurrency(): string
    {
        try {
            return app(ConsultationTariffService::class)->sinaCreditCurrency();
        } catch (Throwable) {
            return 'sina_credit';
        }
    }

    /**
     * The acting patient's OWN available credit balance (0 when unknown).
     */
    public function balanceCredits(): int
    {
        $patient = $this->patient();
        $user = auth()->user();

        if ($patient === null || $user === null || ! $this->billingEnabled()) {
            return 0;
        }

        try {
            $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

            if ($tenantId === null) {
                return 0;
            }

            return app(SinaWalletService::class)->balanceFor($tenantId, (string) $patient->getKey());
        } catch (Throwable) {
            return 0;
        }
    }

    public function grantConsent(): void
    {
        if (! static::allows(PermissionKey::CONSULTATION_OWN)) {
            abort(403);
        }

        $patient = $this->requirePatient();

        app(SinaConsentService::class)->grant($patient, auth()->user(), 'patient_sina_chat');

        Notification::make()->success()->title(__('lba.patient.sina.consent_granted'))->send();
    }

    public function startSession(): void
    {
        if (! static::allows(PermissionKey::CONSULTATION_OWN)) {
            abort(403);
        }

        $patient = $this->requirePatient();

        if (! $this->hasConsent()) {
            Notification::make()->warning()->title(__('lba.patient.sina.consent_required'))->send();

            return;
        }

        $branchId = $patient->registered_branch_id ?: $this->defaultBranchId();

        if ($branchId === null) {
            Notification::make()->danger()->title(__('lba.patient.sina.error_generic'))->send();

            return;
        }

        try {
            $manager = app(ConsultationManager::class);

            $consultation = $manager->create([
                'patient_id' => $patient->getKey(),
                'branch_id' => $branchId,
                'channel' => Consultation::CHANNEL_SINA_CHAT,
                'type' => Consultation::TYPE_CONSULTATION,
                'status' => Consultation::STATUS_SCHEDULED,
                'scheduled_at' => now(),
            ], auth()->user());

            $manager->transition($consultation, Consultation::STATUS_IN_PROGRESS, auth()->user());
        } catch (ConsultationException) {
            Notification::make()->danger()->title(__('lba.patient.sina.error_generic'))->send();

            return;
        }

        Notification::make()->success()->title(__('lba.patient.sina.start_session'))->send();
    }

    public function send(): void
    {
        if (! static::allows(PermissionKey::CONSULTATION_OWN)) {
            abort(403);
        }

        $patient = $this->requirePatient();
        $session = $this->session();

        if ($session === null) {
            Notification::make()->danger()->title(__('lba.patient.sina.error_generic'))->send();

            return;
        }

        $this->validate([
            'draft' => ['required', 'string', 'max:2000'],
        ]);

        try {
            app(SinaChatService::class)->sendPatientMessage($session, $patient, $this->draft, auth()->user());
        } catch (ConsultationException $exception) {
            Notification::make()
                ->danger()
                ->title($exception->errorCode() === 'consent_required'
                    ? __('lba.patient.sina.consent_required')
                    : __('lba.patient.sina.error_generic'))
                ->send();

            return;
        } catch (SinaException $exception) {
            Notification::make()->danger()->title($this->sinaErrorMessage($exception))->send();

            return;
        }

        $this->draft = '';
    }

    private function sinaErrorMessage(SinaException $exception): string
    {
        return match ($exception->errorCode()) {
            'insufficient_credits', 'wallet_missing' => __('lba.patient.sina.error_insufficient'),
            'provider_unavailable', 'provider_not_configured' => __('lba.patient.sina.error_unavailable'),
            default => __('lba.patient.sina.error_generic'),
        };
    }

    private function defaultBranchId(): ?string
    {
        try {
            return TenantBranch::query()->active()->orderBy('name')->value('id');
        } catch (Throwable) {
            return null;
        }
    }
}
