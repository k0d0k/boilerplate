<?php

namespace Modules\LBA\Services;

use App\Models\User;
use App\Platform\LbaAssessment\Models\LbaIndicatorCatalogVersion;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use Modules\LBA\Exceptions\AssessmentException;
use Modules\LBA\Models\Annotation;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentEvent;
use Modules\LBA\Models\AssessmentEvidenceLink;
use Modules\LBA\Models\AssessmentItem;
use Modules\LBA\Models\Detection;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;

/**
 * Phase 7A — digital LBA observation assessment recording (Modules/LBA).
 *
 * ONE common data model serves Manual, AI and Hybrid: `openFor()` snapshots the
 * examination's mode and the catalog, then every item/evidence/status change is
 * written through this recorder. Every clinical write happens inside a VERIFIED
 * tenant transaction ({@see RunsVerifiedTenantTransactions}) so a partial write
 * can never survive a failure and a clinical row can never land on the central
 * database. Only the catalog version is read from the central database and no
 * central row ever receives a patient finding.
 *
 * Human control is enforced structurally: an assessment may be submitted for
 * review but never approved or published here (Phase 7B), an AI detection can
 * only ever be linked as AI-provenance evidence (never promoted to a human
 * finding or a final colour), and a submitted/locked assessment is read-only.
 */
final class AssessmentRecorder
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly AssessmentCatalog $catalog,
        private readonly BrandDirectory $brands,
    ) {}

    /**
     * The existing assessment of an examination, or null. This NEVER creates an
     * assessment and NEVER provisions the catalog, so a read-only view can load
     * what exists without any clinical write.
     */
    public function findFor(Examination $examination): ?Assessment
    {
        return Assessment::query()
            ->where('examination_id', $examination->getKey())
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * The single assessment of an examination, created on first use with the
     * examination mode and the catalog snapshot. Idempotent: reopening the form
     * returns the existing assessment.
     */
    public function openFor(Examination $examination, ?User $actor = null): Assessment
    {
        if ($examination->isCancelled()) {
            throw AssessmentException::examinationNotAssessable((string) $examination->status);
        }

        $existing = $this->findFor($examination);

        if ($existing !== null) {
            return $existing;
        }

        // A central, non-patient write: provision the reference catalog before
        // opening the tenant transaction so the two connections never mix.
        $version = $this->catalog->activeVersionOrProvision();

        return $this->tenantTransaction(function () use ($examination, $actor, $version): Assessment {
            $assessment = Assessment::query()->create([
                'examination_id' => $examination->getKey(),
                'catalog_version' => $version->key,
                'catalog_version_id' => (string) $version->getKey(),
                'mode' => $examination->mode,
                'status' => Assessment::STATUS_DRAFT,
                'program_phase' => Assessment::PROGRAM_NOT_SPECIFIED,
                'created_by' => $actor?->getKey(),
                'last_saved_at' => now(),
            ]);

            $this->snapshotItems($assessment, $version);

            $this->recordEvent($assessment, AssessmentEvent::ACTION_CREATED, $actor, [
                'to_status' => Assessment::STATUS_DRAFT,
                'note' => 'Assessment opened.',
            ]);

            return $assessment;
        });
    }

    /**
     * Record one indicator's category. A single explicit value is stored (never
     * three booleans) and an unassessed item is never turned green implicitly.
     */
    public function setCategory(Assessment $assessment, string $indicatorKey, string $category, ?User $actor = null): AssessmentItem
    {
        if (! in_array($category, AssessmentItem::CATEGORIES, true)) {
            throw AssessmentException::invalidCategory($category);
        }

        $this->assertEditable($assessment);

        return $this->tenantTransaction(function () use ($assessment, $indicatorKey, $category, $actor): AssessmentItem {
            $item = $this->item($assessment, $indicatorKey);
            $from = (string) $item->category;

            $item->forceFill([
                'category' => $category,
                'assessor_id' => $actor?->getKey(),
                'assessed_at' => now(),
                'revision' => ((int) $item->revision) + 1,
                'provenance' => AssessmentItem::PROVENANCE_HUMAN,
            ])->save();

            $assessment->forceFill(['last_saved_at' => now()])->save();

            $this->recordEvent($assessment, AssessmentEvent::ACTION_INDICATOR_SET, $actor, [
                'indicator_key' => $indicatorKey,
                'note' => "{$from} → {$category}",
            ]);

            return $item;
        });
    }

    /**
     * Update one indicator's observation notes (a note is not a category).
     */
    public function updateNotes(Assessment $assessment, string $indicatorKey, ?string $notes, ?User $actor = null): AssessmentItem
    {
        $this->assertEditable($assessment);

        return $this->tenantTransaction(function () use ($assessment, $indicatorKey, $notes, $actor): AssessmentItem {
            $item = $this->item($assessment, $indicatorKey);

            $item->forceFill([
                'notes' => $notes,
                'assessor_id' => $actor?->getKey(),
                'revision' => ((int) $item->revision) + 1,
            ])->save();

            $assessment->forceFill(['last_saved_at' => now()])->save();

            $this->recordEvent($assessment, AssessmentEvent::ACTION_NOTES_UPDATED, $actor, [
                'indicator_key' => $indicatorKey,
            ]);

            return $item;
        });
    }

    /**
     * Set the program phase (a program descriptor, never a medical
     * interpretation) — kept entirely separate from the assessment categories.
     */
    public function setProgramPhase(Assessment $assessment, string $phase, ?User $actor = null): Assessment
    {
        if (! in_array($phase, Assessment::PROGRAM_PHASES, true)) {
            throw AssessmentException::invalidProgramPhase($phase);
        }

        $this->assertEditable($assessment);

        return $this->tenantTransaction(function () use ($assessment, $phase, $actor): Assessment {
            $assessment->forceFill([
                'program_phase' => $phase,
                'last_saved_at' => now(),
            ])->save();

            $this->recordEvent($assessment, AssessmentEvent::ACTION_PROGRAM_PHASE_SET, $actor, [
                'note' => $phase,
            ]);

            return $assessment;
        });
    }

    /**
     * Select the report brand. Only an ACTIVE brand legitimately linked to the
     * verified tenant is accepted — never inferred from locale.
     */
    public function selectBrand(Assessment $assessment, string $tenantId, string $brandId, ?User $actor = null): Assessment
    {
        $this->assertEditable($assessment);

        $brand = $this->brands->resolveSelectable($tenantId, $brandId);

        return $this->tenantTransaction(function () use ($assessment, $brand, $actor): Assessment {
            $assessment->forceFill([
                'brand_id' => (string) $brand->getKey(),
                'brand_snapshot' => $this->brands->snapshot($brand),
                'last_saved_at' => now(),
            ])->save();

            $this->recordEvent($assessment, AssessmentEvent::ACTION_BRAND_SELECTED, $actor, [
                'note' => (string) $brand->name,
            ]);

            return $assessment;
        });
    }

    /**
     * Attach evidence to an indicator. The evidence must belong to the SAME
     * examination (and therefore tenant); an AI detection is always stored with
     * AI provenance and is never promoted to a human finding.
     */
    public function attachEvidence(
        Assessment $assessment,
        string $indicatorKey,
        string $evidenceType,
        string $evidenceId,
        ?User $actor = null,
        ?string $note = null,
    ): AssessmentEvidenceLink {
        $this->assertEditable($assessment);

        if (! in_array($evidenceType, AssessmentEvidenceLink::TYPES, true)) {
            throw AssessmentException::invalidEvidenceType($evidenceType);
        }

        $this->assertEvidenceOnExamination($assessment, $evidenceType, $evidenceId);
        $this->item($assessment, $indicatorKey);

        $provenance = $evidenceType === AssessmentEvidenceLink::TYPE_DETECTION
            ? AssessmentEvidenceLink::PROVENANCE_AI
            : AssessmentEvidenceLink::PROVENANCE_HUMAN;

        return $this->tenantTransaction(function () use ($assessment, $indicatorKey, $evidenceType, $evidenceId, $provenance, $actor, $note): AssessmentEvidenceLink {
            $link = AssessmentEvidenceLink::query()->firstOrCreate(
                [
                    'assessment_id' => $assessment->getKey(),
                    'indicator_key' => $indicatorKey,
                    'evidence_type' => $evidenceType,
                    'evidence_id' => $evidenceId,
                ],
                [
                    'provenance' => $provenance,
                    'note' => $note,
                    'linked_by' => $actor?->getKey(),
                ],
            );

            $assessment->forceFill(['last_saved_at' => now()])->save();

            $this->recordEvent($assessment, AssessmentEvent::ACTION_EVIDENCE_LINKED, $actor, [
                'indicator_key' => $indicatorKey,
                'note' => "{$evidenceType}:{$evidenceId} ({$provenance})",
            ]);

            return $link;
        });
    }

    public function detachEvidence(Assessment $assessment, AssessmentEvidenceLink $link, ?User $actor = null): void
    {
        $this->assertEditable($assessment);

        if ((string) $link->assessment_id !== (string) $assessment->getKey()) {
            throw AssessmentException::foreignEvidence();
        }

        $this->tenantTransaction(function () use ($assessment, $link, $actor): void {
            $this->recordEvent($assessment, AssessmentEvent::ACTION_EVIDENCE_UNLINKED, $actor, [
                'indicator_key' => (string) $link->indicator_key,
                'note' => "{$link->evidence_type}:{$link->evidence_id}",
            ]);

            $link->delete();
        });
    }

    /**
     * Stamp a draft save with the acting identity and time.
     */
    public function saveDraft(Assessment $assessment, ?User $actor = null): Assessment
    {
        $this->assertEditable($assessment);

        return $this->tenantTransaction(function () use ($assessment, $actor): Assessment {
            $assessment->forceFill([
                'last_saved_at' => now(),
                'created_by' => $assessment->created_by ?: $actor?->getKey(),
            ])->save();

            $this->recordEvent($assessment, AssessmentEvent::ACTION_DRAFT_SAVED, $actor);

            return $assessment;
        });
    }

    /**
     * Persist the assessment SUMMARY NOTES inside the SAME verified tenant
     * transaction that stamps the draft, so a partial write can never survive a
     * failure. The notes are written through this recorder (never from the page)
     * and the audit trail is preserved: a changed summary appends a
     * summary-notes event and every save appends a draft-saved event.
     */
    public function saveSummaryNotes(Assessment $assessment, ?string $notes, ?User $actor = null): Assessment
    {
        $this->assertEditable($assessment);

        return $this->tenantTransaction(function () use ($assessment, $notes, $actor): Assessment {
            $previous = $assessment->notes !== null ? (string) $assessment->notes : null;
            $changed = $notes !== $previous;

            $assessment->forceFill([
                'notes' => $notes,
                'last_saved_at' => now(),
                'created_by' => $assessment->created_by ?: $actor?->getKey(),
            ])->save();

            if ($changed) {
                $this->recordEvent($assessment, AssessmentEvent::ACTION_SUMMARY_NOTES_UPDATED, $actor, [
                    'note' => 'Assessment summary notes updated.',
                ]);
            }

            $this->recordEvent($assessment, AssessmentEvent::ACTION_DRAFT_SAVED, $actor);

            return $assessment;
        });
    }

    /**
     * Submit for human review. This is the ONLY forward move Phase 7A performs:
     * it locks the assessment but never approves or publishes a report.
     */
    public function submitForReview(Assessment $assessment, ?User $actor = null): Assessment
    {
        return $this->transitionTo($assessment, Assessment::STATUS_SUBMITTED_FOR_REVIEW, $actor, [
            'submitted_at' => now(),
            'locked_at' => now(),
            'assessed_by' => $actor?->getKey(),
        ]);
    }

    /**
     * Return a submitted assessment for changes (unlocks it). Kept explicit so
     * the transition is auditable; the review UI itself is Phase 7B.
     */
    public function returnForChanges(Assessment $assessment, ?User $actor = null, ?string $note = null): Assessment
    {
        return $this->transitionTo($assessment, Assessment::STATUS_RETURNED_FOR_CHANGES, $actor, [
            'returned_at' => now(),
            'locked_at' => null,
        ], $note);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function transitionTo(Assessment $assessment, string $to, ?User $actor, array $attributes = [], ?string $note = null): Assessment
    {
        return $this->tenantTransaction(function () use ($assessment, $to, $actor, $attributes, $note): Assessment {
            $from = (string) $assessment->status;

            if ($from !== $to && ! $assessment->canTransitionTo($to)) {
                throw AssessmentException::invalidTransition($from, $to);
            }

            $assessment->forceFill($attributes + ['status' => $to])->save();

            $this->recordEvent($assessment, AssessmentEvent::ACTION_STATUS_CHANGED, $actor, [
                'from_status' => $from,
                'to_status' => $to,
                'note' => $note,
            ]);

            return $assessment;
        });
    }

    /**
     * Snapshot every active catalog definition onto the assessment, so a later
     * catalog change cannot alter what was assessed.
     *
     * The terminology VALIDATION status and its approval provenance are
     * snapshotted alongside the labels: a definition only becomes a
     * patient-facing finding when it carried an explicit approved status AND a
     * trustworthy approval (approver + time) at snapshot time. A legacy
     * reference-only definition carries neither, so its item can never be
     * promoted to a finding by a later catalog edit.
     */
    private function snapshotItems(Assessment $assessment, LbaIndicatorCatalogVersion $version): void
    {
        foreach ($this->catalog->definitions($version) as $definition) {
            $approved = $definition->hasApprovedTerminology();
            $provenance = $definition->approvalProvenance();

            AssessmentItem::query()->create([
                'assessment_id' => $assessment->getKey(),
                'indicator_key' => $definition->key,
                'catalog_version' => $version->key,
                'source_label' => $definition->source_label,
                'observation_label' => $definition->observation_label,
                'terminology_status' => $definition->validation_status,
                'terminology_approved_by' => $approved ? $provenance['by'] : null,
                'terminology_approved_at' => $approved ? $provenance['at'] : null,
                'source_column' => $definition->source_column,
                'position' => (int) $definition->position,
                'category' => AssessmentItem::CATEGORY_UNASSESSED,
                'revision' => 1,
                'provenance' => AssessmentItem::PROVENANCE_HUMAN,
            ]);
        }
    }

    private function item(Assessment $assessment, string $indicatorKey): AssessmentItem
    {
        $item = AssessmentItem::query()
            ->where('assessment_id', $assessment->getKey())
            ->where('indicator_key', $indicatorKey)
            ->first();

        if ($item === null) {
            throw AssessmentException::unknownIndicator($indicatorKey);
        }

        return $item;
    }

    private function assertEditable(Assessment $assessment): void
    {
        if ($assessment->isLocked() || $assessment->status === Assessment::STATUS_SUBMITTED_FOR_REVIEW || $assessment->status === Assessment::STATUS_REVIEWED) {
            throw AssessmentException::locked();
        }

        if (! $assessment->isEditable()) {
            throw AssessmentException::notEditable((string) $assessment->status);
        }
    }

    /**
     * Fail closed unless the evidence row belongs to the SAME examination,
     * which — because every examination is tenant-local — also proves the same
     * tenant. Foreign-tenant or foreign-examination evidence is denied.
     */
    private function assertEvidenceOnExamination(Assessment $assessment, string $type, string $id): void
    {
        $examinationId = (string) $assessment->examination_id;

        $belongs = match ($type) {
            AssessmentEvidenceLink::TYPE_IMAGE => ExaminationImage::query()
                ->whereKey($id)->where('examination_id', $examinationId)->exists(),
            AssessmentEvidenceLink::TYPE_ANNOTATION => Annotation::query()
                ->whereKey($id)->where('examination_id', $examinationId)->exists(),
            AssessmentEvidenceLink::TYPE_DETECTION => Detection::query()
                ->whereKey($id)->where('examination_id', $examinationId)->exists(),
            default => throw AssessmentException::invalidEvidenceType($type),
        };

        if (! $belongs) {
            throw AssessmentException::foreignEvidence();
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function recordEvent(Assessment $assessment, string $action, ?User $actor, array $extra = []): void
    {
        AssessmentEvent::query()->create([
            'assessment_id' => $assessment->getKey(),
            'action' => $action,
            'from_status' => $extra['from_status'] ?? null,
            'to_status' => $extra['to_status'] ?? null,
            'indicator_key' => $extra['indicator_key'] ?? null,
            'actor_id' => $actor?->getKey(),
            'note' => $extra['note'] ?? null,
            'occurred_at' => now(),
        ]);
    }
}
