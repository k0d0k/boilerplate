<?php

namespace App\Http\Controllers\Api\Patient;

use App\Http\Controllers\Api\Patient\Concerns\PresentsPatientApi;
use App\Platform\Access\PermissionKey;
use App\Platform\Api\Support\ApiResponse;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\SinaChatService;
use Modules\Consultations\Services\SinaConsentService;
use Modules\Consultations\Services\SinaWalletService;
use Modules\Patients\Models\Patient;

/**
 * Workstream 2 — patient SINA consultation chat over the REST API.
 *
 * Reuses the SAME services the Patient panel uses, so the surfaces can never
 * drift apart:
 *  - {@see SinaConsentService} records/reads the explicit `ai_assistance`
 *    consent (a turn is impossible without it);
 *  - {@see ConsultationManager} creates and transitions the session;
 *  - {@see SinaChatService} runs the turn and does the per-turn credit
 *    accounting;
 *  - {@see SinaWalletService} exposes the patient's own credit balance and usage.
 *
 * Every endpoint is scoped to the token's bound clinic AND the acting identity's
 * OWN patient record. The wallet is CENTRAL and keyed by that same patient id.
 */
final class SinaChatController extends PatientApiController
{
    use PresentsPatientApi;

    /**
     * The current session, consent state, transcript and credit balance.
     */
    public function show(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();
        $session = $this->activeSession($patient);

        return ApiResponse::ok([
            'consent' => $this->consentService()->hasConsent($patient),
            'fee' => $this->sinaFeeArray(),
            'session' => $session === null ? null : $this->consultationArray($session),
            'messages' => $session === null
                ? []
                : ConsultationMessage::query()
                    ->where('consultation_id', $session->getKey())
                    ->orderBy('created_at')
                    ->get()
                    ->map(fn (ConsultationMessage $message) => $this->consultationMessageArray($message))
                    ->all(),
            'wallet' => $this->sinaWalletArray($this->walletService()->walletFor($this->auth->tenantId(), (string) $patient->getKey())),
        ]);
    }

    /**
     * Record the patient's explicit AI-assistance consent.
     */
    public function consent(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();

        $consent = $this->consentService()->grant($patient, $this->auth->user(), 'patient_api');

        return ApiResponse::ok([
            'consent' => true,
            'consent_id' => $consent->getKey(),
        ], [], 201);
    }

    /**
     * Start (or return the existing) SINA chat session. Consent is required.
     */
    public function start(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();

        $existing = $this->activeSession($patient);

        if ($existing !== null) {
            return ApiResponse::ok($this->consultationArray($existing));
        }

        if (! $this->consentService()->hasConsent($patient)) {
            return ApiResponse::error('consent_required', 'Patient consent for AI consultation assistance is required.', 409);
        }

        $branchId = $patient->registered_branch_id
            ?: TenantBranch::query()->active()->orderBy('name')->value('id');

        if ($branchId === null) {
            return ApiResponse::error('no_branch', 'No active branch is available for this clinic.', 409);
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
            ], $this->auth->user());

            $manager->transition($consultation, Consultation::STATUS_IN_PROGRESS, $this->auth->user());
        } catch (ConsultationException $exception) {
            return ApiResponse::error($exception->errorCode(), $exception->getMessage(), 409);
        }

        return ApiResponse::ok($this->consultationArray($consultation->fresh()->load('branch')), [], 201);
    }

    /**
     * Run one patient turn through the SINA chat service.
     */
    public function send(Request $request): JsonResponse
    {
        $this->auth->authorize(PermissionKey::CONSULTATION_OWN);
        $patient = $this->auth->patient();
        $session = $this->activeSession($patient);

        if ($session === null) {
            return ApiResponse::error('no_active_session', 'Start a SINA consultation before sending a message.', 409);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:2000'],
            // Optional idempotency key: a client that retries after a dropped
            // response sends the SAME reference, so the per-turn wallet
            // movements collapse to a single charge instead of double-billing.
            'turn_reference' => ['nullable', 'string', 'max:80'],
        ]);

        try {
            $reply = app(SinaChatService::class)->sendPatientMessage(
                $session,
                $patient,
                $validated['content'],
                $this->auth->user(),
                $validated['turn_reference'] ?? null,
            );
        } catch (ConsultationException $exception) {
            return ApiResponse::error($exception->errorCode(), $exception->getMessage(), 409);
        } catch (SinaException $exception) {
            return ApiResponse::error($exception->errorCode(), $exception->getMessage(), $this->sinaStatus($exception->errorCode()));
        }

        return ApiResponse::ok([
            'session' => $this->consultationArray($session->fresh()->load('branch')),
            'reply' => $this->consultationMessageArray($reply),
            'wallet' => $this->sinaWalletArray($this->walletService()->walletFor($this->auth->tenantId(), (string) $patient->getKey())),
        ]);
    }

    /**
     * The acting patient's OWN active SINA session, if any.
     */
    private function activeSession(Patient $patient): ?Consultation
    {
        return Consultation::query()
            ->with('branch')
            ->where('patient_id', $patient->getKey())
            ->where('channel', Consultation::CHANNEL_SINA_CHAT)
            ->whereIn('status', Consultation::ACTIVE_STATUSES)
            ->latest('created_at')
            ->first();
    }

    private function consentService(): SinaConsentService
    {
        return app(SinaConsentService::class);
    }

    private function walletService(): SinaWalletService
    {
        return app(SinaWalletService::class);
    }

    /**
     * Map a stable SINA error code to an HTTP status.
     */
    private function sinaStatus(string $code): int
    {
        return match ($code) {
            'insufficient_credits', 'wallet_missing' => 402,
            'provider_unavailable', 'provider_not_configured' => 503,
            'turn_in_progress', 'turn_reference_conflict', 'turn_replay_unavailable' => 409,
            default => 422,
        };
    }
}
