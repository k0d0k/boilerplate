<?php

namespace Modules\LBA\Services;

use App\Models\User;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationEvent;

/**
 * Phase 4 — the LBA examination workflow.
 *
 * Owns every state change and appends an append-only event for it. Transitions
 * are validated against {@see Examination::TRANSITIONS}; there is deliberately
 * NO path to `approved` or `published`, so a Phase 4 examination can never be
 * auto-approved or released to a patient (human review + immutable reporting
 * are Phase 7).
 */
final class ExaminationWorkflow
{
    use RunsVerifiedTenantTransactions;

    public function transition(
        Examination $examination,
        string $to,
        ?User $actor = null,
        ?string $note = null,
    ): Examination {
        return $this->tenantTransaction(function () use ($examination, $to, $actor, $note): Examination {
            $from = (string) $examination->status;

            if ($from === $to) {
                return $examination;
            }

            if (! $examination->canTransitionTo($to)) {
                throw ExaminationException::invalidTransition($from, $to);
            }

            $examination->forceFill(['status' => $to])->save();

            $this->recordEvent($examination, $from, $to, $actor, $note);

            return $examination;
        });
    }

    /**
     * Append the creation event for a freshly created examination.
     */
    public function recordInitial(Examination $examination, ?User $actor = null): void
    {
        $this->recordEvent($examination, null, (string) $examination->status, $actor, null);
    }

    public function recordEvent(
        Examination $examination,
        ?string $from,
        string $to,
        ?User $actor = null,
        ?string $note = null,
    ): void {
        ExaminationEvent::query()->create([
            'examination_id' => $examination->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->getKey(),
            'note' => $note,
            'occurred_at' => now(),
        ]);
    }
}
