<?php

namespace Modules\LBA\Services;

use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentItem;

/**
 * Phase 7B — the clinical-terminology GATE (Modules/LBA).
 *
 * A non-empty `observation_label` is NOT, on its own, evidence of medical
 * validation — terminology only becomes a patient-facing finding when it
 * carries an EXPLICIT approved status AND a trustworthy approval provenance
 * (who approved it, and when). Until then the indicator is REFERENCE-ONLY.
 *
 * The 30 legacy disease-oriented labels shipped with the paper form carry no
 * reviewed observation label, no approved status and no approval provenance,
 * and are therefore all reference-only. This gate is the single chokepoint that
 * decides what may appear in a patient-facing clinical report:
 *
 *  - {@see clinicalFindings()} returns ONLY terminology that is explicitly
 *    approved and supported for its intended observation use, with HUMAN
 *    provenance. An AI detection is never a clinical finding.
 *  - {@see neutralObservations()} turns everything else into a NEUTRAL
 *    observation record: it never carries the legacy disease-oriented label and
 *    never asserts a disease conclusion. Its category is presented strictly as
 *    an internal review priority, never as a validated risk level.
 *
 * The gate is read-only and pure: it never mutates an assessment and holds no
 * state, so a report can be re-rendered deterministically.
 */
final class ClinicalTerminologyGate
{
    /**
     * True only when an item's terminology may be shown as a patient-facing
     * clinical finding: it must carry explicitly approved terminology (status +
     * approval provenance + reviewed label) AND human provenance.
     */
    public function isPatientFacingFinding(AssessmentItem $item): bool
    {
        return $item->isValidated()
            && (string) $item->provenance !== AssessmentItem::PROVENANCE_AI;
    }

    /**
     * The reviewed, patient-facing clinical findings of an assessment.
     *
     * @return list<array{indicator_key: string, label: string, category: string, notes: ?string}>
     */
    public function clinicalFindings(Assessment $assessment): array
    {
        $findings = [];

        foreach ($assessment->items()->get() as $item) {
            if (! $this->isPatientFacingFinding($item)) {
                continue;
            }

            $findings[] = [
                'indicator_key' => (string) $item->indicator_key,
                'label' => $item->displayLabel(),
                'category' => (string) $item->category,
                'notes' => $item->notes !== null ? (string) $item->notes : null,
            ];
        }

        return $findings;
    }

    /**
     * Neutral observation records for terminology that is NOT validated.
     *
     * A neutral record deliberately carries NO legacy disease-oriented label and
     * NO disease conclusion: only the internal review priority and a status, so
     * a patient-facing document can never imply a diagnosis from unreviewed
     * terminology.
     *
     * @return list<array{position: int, category: string, status: string}>
     */
    public function neutralObservations(Assessment $assessment): array
    {
        $records = [];

        foreach ($assessment->items()->get() as $item) {
            if ($this->isPatientFacingFinding($item)) {
                continue;
            }

            $records[] = [
                'position' => (int) $item->position,
                'category' => (string) $item->category,
                'status' => (string) $item->category === AssessmentItem::CATEGORY_UNASSESSED
                    ? 'unassessed'
                    : 'reference_only',
            ];
        }

        return $records;
    }

    /**
     * True when ANY of the assessment's terminology is reviewed and supported
     * for patient-facing use.
     */
    public function hasReviewedTerminology(Assessment $assessment): bool
    {
        foreach ($assessment->items()->get() as $item) {
            if ($this->isPatientFacingFinding($item)) {
                return true;
            }
        }

        return false;
    }

    public function pendingTerminologyCount(Assessment $assessment): int
    {
        return count($this->neutralObservations($assessment));
    }

    /**
     * Internal review-priority tallies over the (unvalidated) observations.
     * These are NEVER patient-facing validated risk levels.
     *
     * @return array{green: int, yellow: int, red: int, unassessed: int}
     */
    public function neutralCategoryCounts(Assessment $assessment): array
    {
        $counts = [
            AssessmentItem::CATEGORY_GREEN => 0,
            AssessmentItem::CATEGORY_YELLOW => 0,
            AssessmentItem::CATEGORY_RED => 0,
            AssessmentItem::CATEGORY_UNASSESSED => 0,
        ];

        foreach ($this->neutralObservations($assessment) as $record) {
            if (isset($counts[$record['category']])) {
                $counts[$record['category']]++;
            }
        }

        return $counts;
    }
}
