<?php

namespace Modules\LBA\Services;

use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PermissionKey;
use App\Platform\Tenancy\Concerns\RunsVerifiedTenantTransactions;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Modules\LBA\Exceptions\ReviewException;
use Modules\LBA\Models\Assessment;
use Modules\LBA\Models\AssessmentEvent;
use Modules\LBA\Models\ReportEvent;
use Modules\LBA\Models\ReportVersion;
use Modules\LBA\Models\Review;
use Modules\Practitioners\Services\PractitionerAssignment;

/**
 * Phase 7B — the human review workflow (Modules/LBA).
 *
 * Only an explicitly acting HUMAN reviewer can move a submitted assessment to a
 * decision. This service is the single chokepoint and enforces, structurally:
 *
 *  - an assessment is reviewable only in `submitted_for_review`;
 *  - by default a reviewer may NOT approve an examination they recorded or
 *    operate (separation of duties, DECISIONS.md LBA-02) — a distinct human
 *    reviewer must sign off;
 *  - approval requires an explicit `approve()` call by a verified identity; no
 *    AI process (or automatic path) can approve a report;
 *  - every decision is appended to `lba_reviews` and the report lifecycle to
 *    `lba_report_events`, so reviewer identity, time and history are preserved.
 *
 * Every clinical/report write happens inside a VERIFIED tenant transaction, so a
 * decision can never land on the central database or another tenant's database.
 */
final class AssessmentReviewer
{
    use RunsVerifiedTenantTransactions;

    public function __construct(
        private readonly ReportGenerator $reports,
        private readonly BrandDirectory $brands,
        private readonly ChecksPermissions $permissions,
        private readonly PractitionerAssignment $assignment,
    ) {}

    /**
     * Return a submitted assessment for changes (unlocks it so it can be
     * corrected and re-submitted). Records a review row and a report event.
     */
    public function returnForChanges(Assessment $assessment, User $reviewer, ?string $notes = null): Assessment
    {
        $this->assertReviewerAuthorized($reviewer, $this->currentTenantId(), PermissionKey::EXAM_REVIEW);
        $this->assertReviewerEligible($reviewer);

        return $this->tenantTransaction(function () use ($assessment, $reviewer, $notes): Assessment {
            $this->assertReviewable($assessment);
            $this->recordReview($assessment, $reviewer, Review::DECISION_RETURNED_FOR_CHANGES, $notes);

            $assessment->forceFill([
                'status' => Assessment::STATUS_RETURNED_FOR_CHANGES,
                'returned_at' => now(),
                'locked_at' => null,
            ])->save();

            $this->recordStatusEvent($assessment, Assessment::STATUS_SUBMITTED_FOR_REVIEW, Assessment::STATUS_RETURNED_FOR_CHANGES, $reviewer, $notes);
            $this->recordReportEvent($assessment, null, ReportEvent::ACTION_RETURNED, $reviewer, $notes);

            return $assessment;
        });
    }

    /**
     * Reject a submitted assessment (terminal). Records a review row.
     */
    public function reject(Assessment $assessment, User $reviewer, ?string $notes = null): Assessment
    {
        $this->assertReviewerAuthorized($reviewer, $this->currentTenantId(), PermissionKey::EXAM_REVIEW);
        $this->assertReviewerEligible($reviewer);

        return $this->tenantTransaction(function () use ($assessment, $reviewer, $notes): Assessment {
            $this->assertReviewable($assessment);
            $this->recordReview($assessment, $reviewer, Review::DECISION_REJECTED, $notes);

            $assessment->forceFill([
                'status' => Assessment::STATUS_REJECTED,
                'reviewed_at' => now(),
                'locked_at' => now(),
            ])->save();

            $this->recordStatusEvent($assessment, Assessment::STATUS_SUBMITTED_FOR_REVIEW, Assessment::STATUS_REJECTED, $reviewer, $notes);
            $this->recordReportEvent($assessment, null, ReportEvent::ACTION_REJECTED, $reviewer, $notes);

            return $assessment;
        });
    }

    /**
     * Explicitly approve a submitted assessment and issue its first immutable
     * report version. This is the ONLY path to a patient-facing report.
     */
    public function approve(
        Assessment $assessment,
        string $tenantId,
        User $reviewer,
        ?string $notes = null,
        ?string $locale = null,
    ): ReportVersion {
        $this->assertReviewerAuthorized($reviewer, $tenantId, PermissionKey::REPORT_APPROVE);
        $this->assertReviewerEligible($reviewer);
        $this->assertBrandPresent($assessment, $tenantId);

        // Retry-safe: a previous attempt may have committed the human approval
        // and then been interrupted BEFORE the PDF was written. That approval
        // stands, so we must NOT approve again (which would fail as "not
        // reviewable") and must NOT overwrite the existing document. If a report
        // already exists it is returned as-is; otherwise generation resumes,
        // appending a new immutable version.
        if ((string) $assessment->status === Assessment::STATUS_REVIEWED) {
            $existing = $assessment->reportVersions()->orderByDesc('version')->first();

            if ($existing !== null) {
                return $existing;
            }

            return $this->reports->generate($assessment->fresh(), $tenantId, $reviewer, $locale);
        }

        $approved = $this->tenantTransaction(function () use ($assessment, $reviewer, $notes): Assessment {
            $this->assertReviewable($assessment);
            $this->assertNotSelfApproval($assessment, $reviewer);

            $this->recordReview($assessment, $reviewer, Review::DECISION_APPROVED, $notes);

            $assessment->forceFill([
                'status' => Assessment::STATUS_REVIEWED,
                'reviewed_at' => now(),
                'locked_at' => now(),
            ])->save();

            $this->recordStatusEvent($assessment, Assessment::STATUS_SUBMITTED_FOR_REVIEW, Assessment::STATUS_REVIEWED, $reviewer, $notes);
            $this->recordReportEvent($assessment, null, ReportEvent::ACTION_APPROVED, $reviewer, $notes);

            return $assessment;
        });

        // Generate the immutable report version AFTER the approval commits, so
        // the PDF storage I/O never runs inside the database transaction.
        return $this->reports->generate($approved->fresh(), $tenantId, $reviewer, $locale);
    }

    /**
     * Generate a NEW immutable report version for an already-approved
     * assessment (used after a revision). Never overwrites an existing version.
     */
    public function generateReport(
        Assessment $assessment,
        string $tenantId,
        User $reviewer,
        ?string $locale = null,
    ): ReportVersion {
        $this->assertReviewerAuthorized($reviewer, $tenantId, PermissionKey::REPORT_APPROVE);
        $this->assertReviewerEligible($reviewer);
        $this->assertBrandPresent($assessment, $tenantId);

        return $this->reports->generate($assessment->fresh(), $tenantId, $reviewer, $locale);
    }

    /**
     * Explicitly RELEASE one immutable report version to the patient portal.
     *
     * Approval and release are separate, deliberate facts. A human approval (or
     * a generated report) NEVER publishes to a patient on its own: an authorized
     * clinic identity holding `report.approve` for the VERIFIED tenant must
     * release THIS exact version. The releasing actor, the time and the version
     * are stored on the version row, and an append-only `released` event is
     * recorded, so the moment a patient-facing document became visible is
     * provable. Releasing an already-released version is a no-op, so a
     * double-submit can never rewrite the original grant.
     */
    public function releaseToPatient(
        ReportVersion $version,
        string $tenantId,
        User $actor,
        ?string $note = null,
    ): ReportVersion {
        $this->assertReviewerAuthorized($actor, $tenantId, PermissionKey::REPORT_APPROVE);
        $this->assertReviewerEligible($actor);

        if ($version->isReleased()) {
            return $version;
        }

        return $this->tenantTransaction(function () use ($version, $actor, $note): ReportVersion {
            $version->forceFill([
                'released_at' => now(),
                'released_by' => (string) $actor->getKey(),
            ])->save();

            ReportEvent::query()->create([
                'assessment_id' => $version->assessment_id,
                'report_version_id' => $version->getKey(),
                'action' => ReportEvent::ACTION_RELEASED,
                'actor_id' => (string) $actor->getKey(),
                'note' => $note,
                'occurred_at' => now(),
            ]);

            return $version;
        });
    }

    /**
     * Append an audit event for a preview / download of a report version.
     */
    public function recordAccess(ReportVersion $version, User $actor, string $action): void
    {
        $this->assertReviewerAuthorized($actor, $this->currentTenantId(), PermissionKey::EXAM_READ);

        $this->appendAccessEvent($version, $actor, $action);
    }

    /**
     * Append the audit event for a PATIENT self-service preview / download.
     *
     * The patient-facing stream controller re-derives the caller's OWN
     * authorization on the server (a published report version whose examination
     * belongs to the acting identity's own patient record, within the verified
     * tenant) before calling this, so no clinic/reviewer permission gate applies
     * — a patient never holds `exam.read`.
     */
    public function recordPatientAccess(ReportVersion $version, User $actor, string $action): void
    {
        $this->appendAccessEvent($version, $actor, $action);
    }

    private function appendAccessEvent(ReportVersion $version, User $actor, string $action): void
    {
        if (! in_array($action, [ReportEvent::ACTION_PREVIEWED, ReportEvent::ACTION_DOWNLOADED], true)) {
            throw ReviewException::invalidDecision($action);
        }

        $this->tenantTransaction(function () use ($version, $actor, $action): void {
            ReportEvent::query()->create([
                'assessment_id' => $version->assessment_id,
                'report_version_id' => $version->getKey(),
                'action' => $action,
                'actor_id' => (string) $actor->getKey(),
                'occurred_at' => now(),
            ]);
        });
    }

    /**
     * Re-derive authorization on the SERVER so a direct call to this service
     * that bypasses the Filament UI (a job, a console command, another service)
     * is rejected exactly like an unauthorised UI action. The actor must hold
     * `$permission` for the VERIFIED tenant context — a role held in another
     * tenant can never authorise the call.
     */
    private function assertReviewerAuthorized(User $reviewer, ?string $tenantId, string $permission): void
    {
        if ($tenantId === null || $tenantId === '') {
            throw ReviewException::missingContext();
        }

        if (! $this->permissions->allows(
            $reviewer,
            $permission,
            AccessContext::forTenant($tenantId),
        )) {
            throw ReviewException::notAuthorized();
        }
    }

    /**
     * WS7.C — a REGISTERED practitioner acting as reviewer must have CURRENT
     * professional standing: verified, not expired and still an active clinical
     * member of the verified tenant. A reviewer who is not on the practitioner
     * roster (a pure reviewer role) keeps the historical permission-only rule,
     * so existing review workflows are never broken.
     *
     * This is an ADDITIONAL gate layered on top of {@see self::assertReviewerAuthorized()}:
     * holding `exam.review` / `report.approve` is necessary but no longer
     * sufficient for a practitioner whose verification has lapsed or been
     * suspended. No AI identity holds a clinical membership, so this reinforces
     * that a machine can never sign or publish a report.
     */
    private function assertReviewerEligible(User $reviewer): void
    {
        if (! $this->assignment->allowsAssignment((string) $reviewer->getKey())) {
            throw ReviewException::reviewerNotEligible();
        }
    }

    /**
     * The tenant currently verified for this connection, or null when no tenant
     * database has been resolved (fails closed).
     */
    private function currentTenantId(): ?string
    {
        return app(ResolvesTenantDatabase::class)->current();
    }

    private function assertReviewable(Assessment $assessment): void
    {
        if ((string) $assessment->status !== Assessment::STATUS_SUBMITTED_FOR_REVIEW) {
            throw ReviewException::notReviewable((string) $assessment->status);
        }
    }

    private function assertBrandPresent(Assessment $assessment, string $tenantId): void
    {
        if ($assessment->brand_id === null || $assessment->brand_id === '') {
            throw ReviewException::brandRequired();
        }

        // Fails closed when the brand is not active or not linked to the tenant.
        $this->brands->resolveConfigurable($tenantId, (string) $assessment->brand_id);
    }

    /**
     * Separation of duties: unless explicitly allowed by configuration, a
     * reviewer may not approve work they recorded or operate.
     */
    private function assertNotSelfApproval(Assessment $assessment, User $reviewer): void
    {
        if ((bool) config('lba.reports.allow_self_approval', false)) {
            return;
        }

        $reviewerId = (string) $reviewer->getKey();

        $recordedBy = array_filter([
            $assessment->created_by !== null ? (string) $assessment->created_by : null,
            $assessment->assessed_by !== null ? (string) $assessment->assessed_by : null,
        ]);

        if (in_array($reviewerId, $recordedBy, true)) {
            throw ReviewException::selfApprovalForbidden();
        }

        $examination = $assessment->examination()->first();
        $operatorId = $examination?->operator_user_id !== null ? (string) $examination->operator_user_id : null;

        if ($operatorId !== null && $operatorId === $reviewerId) {
            throw ReviewException::selfApprovalForbidden();
        }
    }

    private function recordReview(Assessment $assessment, User $reviewer, string $decision, ?string $notes): Review
    {
        return Review::query()->create([
            'assessment_id' => $assessment->getKey(),
            'reviewer_id' => (string) $reviewer->getKey(),
            'reviewer_snapshot' => [
                'id' => (string) $reviewer->getKey(),
                'name' => (string) $reviewer->name,
                'email' => (string) $reviewer->email,
            ],
            'decision' => $decision,
            'notes' => $notes,
            'decided_at' => now(),
        ]);
    }

    private function recordStatusEvent(Assessment $assessment, string $from, string $to, User $reviewer, ?string $notes): void
    {
        AssessmentEvent::query()->create([
            'assessment_id' => $assessment->getKey(),
            'action' => AssessmentEvent::ACTION_STATUS_CHANGED,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => (string) $reviewer->getKey(),
            'note' => $notes,
            'occurred_at' => now(),
        ]);
    }

    private function recordReportEvent(Assessment $assessment, ?string $versionId, string $action, User $reviewer, ?string $notes): void
    {
        ReportEvent::query()->create([
            'assessment_id' => $assessment->getKey(),
            'report_version_id' => $versionId,
            'action' => $action,
            'actor_id' => (string) $reviewer->getKey(),
            'note' => $notes,
            'occurred_at' => now(),
        ]);
    }
}
