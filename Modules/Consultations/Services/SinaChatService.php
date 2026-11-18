<?php

namespace Modules\Consultations\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Support\Str;
use Modules\Consultations\Ai\Dto\SinaChatMessage;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Ai\SinaProviderResolver;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Models\ConsultationAuditLog;
use Modules\Consultations\Models\ConsultationMessage;
use Modules\Consultations\Models\ConsultationSinaTurn;
use Modules\KnowledgeBase\Services\KnowledgeGroundingService;
use Modules\KnowledgeBase\Support\KnowledgeGrounding;
use Modules\Patients\Models\Patient;
use Throwable;

/**
 * Workstream 2 — the SINA AI consultation chat.
 *
 * One patient turn:
 *  1. the global SINA switch, the patient's explicit `ai_assistance` consent and
 *     a live consultation are all required (fail closed);
 *  2. the turn is CLAIMED against its optional client reference (WS9.C): a
 *     retried request that carries the SAME reference replays the reply already
 *     stored for the turn — no second provider call, no second transcript row
 *     and no second credit movement — while a reference reused with DIFFERENT
 *     content is rejected and a parallel duplicate is refused;
 *  3. credits are RESERVED from the patient's SINA wallet (when billing is on)
 *     BEFORE anything else is written, so a turn with insufficient credits never
 *     leaves an orphan transcript row;
 *  4. the patient message is appended to the tenant transcript;
 *  5. when the tenant has the knowledge_base module active, approved knowledge
 *     is retrieved for THIS tenant only and injected as quoted reference DATA
 *     (RAG — never fine-tuning, never instructions);
 *  6. the provider runs the completion;
 *  7. the actual usage is CAPTURED (up to the per-turn cap) and the remainder
 *     RELEASED, then the assistant reply is appended with its token counters
 *     and its VALIDATED source citations.
 *
 * A retrieval OR provider failure RELEASES the reservation and leaves the turn
 * marked `failed`, so a later retry with the SAME reference is processed and
 * billed exactly ONCE. The capture key is constant per turn, so even a retried
 * capture can never double-bill.
 *
 * The transcript and the turn ledger live only in the tenant's isolated clinical
 * database; the central wallet stores integer credits and opaque references only.
 */
final class SinaChatService
{
    use RunsVerifiedTenantTransactions;

    /** How many trailing transcript turns are replayed to the model. */
    private const TRANSCRIPT_WINDOW = 20;

    public function __construct(
        private readonly SinaProviderResolver $resolver,
        private readonly SinaWalletService $wallet,
        private readonly ConsultationManager $manager,
        private readonly SinaConsentService $consent,
        private readonly KnowledgeGroundingService $knowledge,
    ) {}

    /**
     * Run one patient turn and return the assistant reply.
     *
     * @throws ConsultationException|SinaException
     */
    public function sendPatientMessage(
        Consultation $consultation,
        Patient $patient,
        string $content,
        ?User $actor = null,
        ?string $turnReference = null,
    ): ConsultationMessage {
        if (! (bool) config('lba.sina.enabled', false)) {
            throw SinaException::unavailable('disabled');
        }

        $tenantId = app(ResolvesTenantDatabase::class)->current();

        if ($tenantId === null) {
            throw ConsultationException::missingContext();
        }

        $this->assertConsent($patient);

        if (! $consultation->isActive()) {
            throw ConsultationException::notActive();
        }

        $patientId = (string) $patient->getKey();
        $consultationId = (string) $consultation->getKey();
        $actorReference = $actor === null ? null : (string) $actor->getKey();

        // A caller-supplied reference makes a retried turn idempotent: every
        // wallet movement for the turn hangs off the SAME key, so a duplicate
        // request can neither double-charge nor double-refund. Omitting it (the
        // portal path) generates a fresh reference, so every portal turn is a
        // distinct turn.
        $reference = $turnReference !== null && trim($turnReference) !== ''
            ? trim($turnReference)
            : Str::uuid()->toString();

        $turnKey = 'sina:'.$consultationId.':'.$reference;
        $contentHash = hash('sha256', $content);

        // 2. CLAIM the turn. A retried reference returns the stored reply here,
        //    before ANY reservation, transcript row or provider call.
        $turn = $this->claimTurn($consultation, $reference, $contentHash);

        if ($turn->status === ConsultationSinaTurn::STATUS_SUCCEEDED) {
            return $this->replay($turn);
        }

        $billing = (bool) config('lba.sina.billing.enabled', true);
        $cap = max(0, (int) config('lba.sina.billing.reserve_credits', 1));

        // The attempt number scopes the reserve/release keys so a turn that
        // FAILED and was released can re-reserve on retry. The capture key stays
        // constant per turn, so a turn is still only ever billed once.
        $attempt = max(1, (int) $turn->attempts);
        $reserved = false;

        try {
            if ($billing && $cap > 0) {
                $this->wallet->reserve(
                    $tenantId,
                    $patientId,
                    $consultationId,
                    $cap,
                    $turnKey.':reserve:'.$attempt,
                    $actorReference,
                );
                $reserved = true;
            }

            // 3. Append the patient turn — ONCE per reference, even across a
            //    failed attempt and its retry (the message is reused, never
            //    duplicated).
            $patientMessage = $this->patientMessageForTurn($turn, $consultation, $content, $actor);

            // 4. Retrieve APPROVED knowledge for THIS tenant only and package it
            //    as quoted reference DATA. Null when the module is inactive.
            $grounding = $this->knowledge->ground($tenantId, $content);

            $messages = $this->transcript($consultation, $grounding);

            // 5. Run the completion.
            $this->recordProviderCall($turn);

            $completion = $this->resolver->forTenant($tenantId)->chat($messages, [
                'temperature' => (float) config('lba.sina.temperature', 0.3),
                'max_tokens' => (int) config('lba.sina.max_tokens', 512),
            ]);
        } catch (Throwable $exception) {
            // A retrieval or provider failure gives the reserved credits straight
            // back; never leave them stranded. The turn is marked `failed` so the
            // SAME reference can be retried and billed once.
            if ($reserved) {
                $this->wallet->release(
                    $tenantId,
                    $patientId,
                    $consultationId,
                    $cap,
                    $turnKey.':release:'.$attempt,
                    $actorReference,
                );
            }

            $this->failTurn($turn, $exception);

            throw $exception;
        }

        // 6. Charge the ACTUAL usage, capped at the reservation, and release the
        //    unused remainder so the reservation is always fully cleared.
        $credits = $this->creditsFor($completion->totalTokens());

        if ($billing && $cap > 0) {
            $capture = min($credits, $cap);

            if ($capture > 0) {
                $this->wallet->capture(
                    $tenantId,
                    $patientId,
                    $consultationId,
                    $capture,
                    $turnKey.':capture',
                    [
                        'provider' => $completion->provider,
                        'model_name' => $completion->model,
                        'prompt_tokens' => $completion->promptTokens,
                        'completion_tokens' => $completion->completionTokens,
                    ],
                    $actorReference,
                );
            }

            if ($cap > $capture) {
                $this->wallet->release(
                    $tenantId,
                    $patientId,
                    $consultationId,
                    $cap - $capture,
                    $turnKey.':release-remainder:'.$attempt,
                    $actorReference,
                );
            }
        }

        $message = $this->manager->appendMessage($consultation, ConsultationMessage::ROLE_ASSISTANT, $completion->content, [
            'provider' => $completion->provider,
            'model' => $completion->model,
            'prompt_tokens' => $completion->promptTokens,
            'completion_tokens' => $completion->completionTokens,
            'credits' => $credits,
            'metadata' => $grounding === null ? null : ['grounding' => $grounding->toMetadata()],
        ], $actor);

        ConsultationAuditLog::query()->create([
            'consultation_id' => $consultation->getKey(),
            'action' => ConsultationAuditLog::ACTION_SINA_TURN,
            'actor_id' => $actor?->getKey(),
            'changes' => [
                'provider' => $completion->provider,
                'model' => $completion->model,
                'tokens' => $completion->totalTokens(),
                'credits' => $credits,
                'grounded' => $grounding?->grounded ?? false,
                'sources' => $grounding === null ? 0 : count($grounding->sources),
            ],
            'occurred_at' => now(),
        ]);

        $this->succeedTurn($turn, $patientMessage, $message);

        return $message;
    }

    /**
     * Claim a turn against its reference, serialising concurrent duplicates.
     *
     * The consultation row is locked for the duration of the claim so two
     * parallel requests can never both start a provider run: the first commits
     * the `pending` turn and the second observes it. A `succeeded` turn is
     * returned as-is (the caller replays its reply), a `pending` turn is refused
     * as in-progress, a `failed` turn is retried in place with a bumped attempt,
     * and a reference reused with DIFFERENT content is rejected outright.
     */
    private function claimTurn(Consultation $consultation, string $reference, string $contentHash): ConsultationSinaTurn
    {
        return $this->tenantTransaction(function () use ($consultation, $reference, $contentHash): ConsultationSinaTurn {
            // Serialise turns of the SAME consultation.
            Consultation::query()->whereKey($consultation->getKey())->lockForUpdate()->first();

            $existing = ConsultationSinaTurn::query()
                ->where('consultation_id', $consultation->getKey())
                ->where('turn_reference', $reference)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->content_hash, $contentHash)) {
                    throw SinaException::turnConflict();
                }

                if ($existing->status === ConsultationSinaTurn::STATUS_SUCCEEDED) {
                    return $existing;
                }

                if ($existing->status === ConsultationSinaTurn::STATUS_PENDING) {
                    throw SinaException::turnInProgress();
                }

                // A previously FAILED turn is retried in place.
                $existing->forceFill([
                    'status' => ConsultationSinaTurn::STATUS_PENDING,
                    'error_code' => null,
                    'attempts' => (int) $existing->attempts + 1,
                ])->save();

                return $existing;
            }

            return ConsultationSinaTurn::query()->create([
                'consultation_id' => $consultation->getKey(),
                'turn_reference' => $reference,
                'content_hash' => $contentHash,
                'status' => ConsultationSinaTurn::STATUS_PENDING,
                'attempts' => 1,
            ]);
        });
    }

    /**
     * The patient's message for THIS turn, appended at most once.
     *
     * A retry of a failed turn reuses the message the first attempt wrote, so a
     * reference never leaves two identical patient rows in the transcript.
     */
    private function patientMessageForTurn(
        ConsultationSinaTurn $turn,
        Consultation $consultation,
        string $content,
        ?User $actor,
    ): ConsultationMessage {
        if ($turn->patient_message_id !== null) {
            $existing = ConsultationMessage::query()->whereKey($turn->patient_message_id)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $message = $this->manager->appendMessage(
            $consultation,
            ConsultationMessage::ROLE_PATIENT,
            $content,
            [],
            $actor,
        );

        $turn->forceFill(['patient_message_id' => $message->getKey()])->save();

        return $message;
    }

    /**
     * The reply stored for an already-succeeded turn.
     */
    private function replay(ConsultationSinaTurn $turn): ConsultationMessage
    {
        $message = $turn->assistant_message_id === null
            ? null
            : ConsultationMessage::query()->whereKey($turn->assistant_message_id)->first();

        if ($message === null) {
            throw SinaException::turnReplayUnavailable();
        }

        return $message;
    }

    private function recordProviderCall(ConsultationSinaTurn $turn): void
    {
        $turn->forceFill(['provider_calls' => (int) $turn->provider_calls + 1])->save();
    }

    private function succeedTurn(
        ConsultationSinaTurn $turn,
        ConsultationMessage $patientMessage,
        ConsultationMessage $assistantMessage,
    ): void {
        $turn->forceFill([
            'status' => ConsultationSinaTurn::STATUS_SUCCEEDED,
            'patient_message_id' => $patientMessage->getKey(),
            'assistant_message_id' => $assistantMessage->getKey(),
            'error_code' => null,
        ])->save();
    }

    private function failTurn(ConsultationSinaTurn $turn, Throwable $exception): void
    {
        $turn->forceFill([
            'status' => ConsultationSinaTurn::STATUS_FAILED,
            'error_code' => $exception instanceof SinaException
                ? $exception->errorCode()
                : 'provider_unavailable',
        ])->save();
    }

    /**
     * The patient must hold an EXPLICIT, un-withdrawn `ai_assistance` consent.
     */
    private function assertConsent(Patient $patient): void
    {
        if (! $this->consent->hasConsent($patient)) {
            throw ConsultationException::consentRequired();
        }
    }

    /**
     * Build the provider transcript: a non-diagnostic SINA system prompt, the
     * optional knowledge grounding block and the trailing window of the
     * consultation's chat turns.
     *
     * `$grounding` is null when the tenant has no active knowledge base. When it
     * is present the retrieved PDF text is handed over as DELIMITED, quoted
     * reference DATA (never as instructions) together with a citation
     * instruction; with no relevant source the model is told to abstain rather
     * than invent a citation.
     *
     * @return list<SinaChatMessage>
     */
    private function transcript(Consultation $consultation, ?KnowledgeGrounding $grounding = null): array
    {
        $messages = [SinaChatMessage::system(__('lba.patient.sina.system_prompt'))];

        if ($grounding !== null) {
            $messages[] = SinaChatMessage::system(
                $grounding->grounded
                    ? __('lba.patient.sina.grounding_instruction')
                    : __('lba.patient.sina.no_source_instruction'),
            );

            if ($grounding->grounded) {
                $messages[] = SinaChatMessage::system($grounding->context);
            }
        }

        $history = ConsultationMessage::query()
            ->where('consultation_id', $consultation->getKey())
            ->whereIn('role', [ConsultationMessage::ROLE_PATIENT, ConsultationMessage::ROLE_ASSISTANT])
            ->orderByDesc('created_at')
            ->limit(self::TRANSCRIPT_WINDOW)
            ->get()
            ->reverse();

        foreach ($history as $message) {
            $messages[] = new SinaChatMessage(
                $message->role === ConsultationMessage::ROLE_ASSISTANT ? 'assistant' : 'user',
                (string) $message->content,
            );
        }

        return $messages;
    }

    /**
     * Integer credits for a completed turn, from the configured per-1k rate.
     */
    private function creditsFor(int $tokens): int
    {
        $perThousand = max(0, (int) config('lba.sina.billing.credits_per_1k_tokens', 1));

        if ($tokens <= 0 || $perThousand === 0) {
            return 0;
        }

        return intdiv($tokens * $perThousand + 999, 1000);
    }
}
