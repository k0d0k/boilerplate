<?php

namespace Modules\KnowledgeBase\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeDocumentEvent;

/**
 * WS9.A — the ONLY write path for a knowledge document's review lifecycle.
 *
 * Every transition is validated against {@see KnowledgeDocument::TRANSITIONS}
 * and recorded as an append-only {@see KnowledgeDocumentEvent}. CRITICALLY:
 *
 *  - a document reaches `published` — the ONLY status the retrieval engine will
 *    surface — solely through {@see publish()}, which REQUIRES an authorised
 *    human actor. No autonomous process can publish, and RAG content can never
 *    be treated as an approved source on its own.
 *  - archiving and revocation are non-destructive: they only flip status and
 *    record the event, so the audit trail survives.
 */
final class KnowledgeDocumentManager
{
    /**
     * uploaded -> processing, at the start of text extraction.
     */
    public function beginProcessing(KnowledgeDocument $document, ?User $actor = null): KnowledgeDocument
    {
        return $this->transition($document, KnowledgeDocument::STATUS_PROCESSING, $actor);
    }

    /**
     * processing -> review, once selectable text has been extracted successfully.
     */
    public function markReadyForReview(KnowledgeDocument $document, ?User $actor = null): KnowledgeDocument
    {
        return $this->transition($document, KnowledgeDocument::STATUS_REVIEW, $actor);
    }

    /**
     * processing -> revoked, when the PDF carried no selectable text. The
     * failure is recorded as an audit event so the rejection is traceable.
     */
    public function markExtractionFailed(KnowledgeDocument $document, string $error, ?User $actor = null): KnowledgeDocument
    {
        $this->recordEvent($document, KnowledgeDocumentEvent::ACTION_EXTRACTION_FAILED, $document->status, KnowledgeDocument::STATUS_REVOKED, $actor, [
            'error' => mb_substr($error, 0, 500),
        ]);

        return $this->transition($document, KnowledgeDocument::STATUS_REVOKED, $actor);
    }

    /**
     * Record a human verification of the document while it is in review.
     */
    public function verify(KnowledgeDocument $document, User $actor): KnowledgeDocument
    {
        if ($document->status !== KnowledgeDocument::STATUS_REVIEW) {
            throw KnowledgeBaseException::invalidTransition($document->status, KnowledgeDocument::STATUS_REVIEW);
        }

        $document->forceFill(['verified_by' => $actor->getKey()])->save();

        $this->recordEvent($document, KnowledgeDocumentEvent::ACTION_VERIFIED, $document->status, $document->status, $actor);

        return $document->refresh();
    }

    /**
     * review -> published. Requires an authenticated human approver; a machine
     * or an autonomous pipeline can never publish a source.
     */
    public function publish(KnowledgeDocument $document, User $actor): KnowledgeDocument
    {
        $document->forceFill([
            'approved_by' => $actor->getKey(),
            'approved_at' => now(),
            'published_at' => now(),
        ])->save();

        return $this->transition($document, KnowledgeDocument::STATUS_PUBLISHED, $actor);
    }

    public function archive(KnowledgeDocument $document, User $actor, ?string $reason = null): KnowledgeDocument
    {
        $document->forceFill(['archived_at' => now()])->save();

        return $this->transition($document, KnowledgeDocument::STATUS_ARCHIVED, $actor, ['reason' => $reason]);
    }

    /**
     * Move a document to the terminal `revoked` state from any non-terminal
     * status. The indexed chunks are purged by the caller (WS9.B) so a revoked
     * source can never be retrieved.
     */
    public function revoke(KnowledgeDocument $document, ?User $actor, ?string $reason = null): KnowledgeDocument
    {
        $document->forceFill(['revoked_at' => now()])->save();

        return $this->transition($document, KnowledgeDocument::STATUS_REVOKED, $actor, ['reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        KnowledgeDocument $document,
        string $to,
        ?User $actor = null,
        array $metadata = [],
    ): KnowledgeDocument {
        $from = $document->status;

        if ($from === $to) {
            return $document;
        }

        if (! $document->canTransitionTo($to)) {
            throw KnowledgeBaseException::invalidTransition($from, $to);
        }

        DB::connection($document->getConnectionName())->transaction(function () use ($document, $to, $from, $actor, $metadata): void {
            $document->forceFill(['status' => $to])->save();

            $this->recordEvent(
                $document,
                $this->actionFor($to),
                $from,
                $to,
                $actor,
                $metadata,
            );
        });

        return $document->refresh();
    }

    private function actionFor(string $status): string
    {
        return match ($status) {
            KnowledgeDocument::STATUS_PUBLISHED => KnowledgeDocumentEvent::ACTION_PUBLISHED,
            KnowledgeDocument::STATUS_ARCHIVED => KnowledgeDocumentEvent::ACTION_ARCHIVED,
            KnowledgeDocument::STATUS_REVOKED => KnowledgeDocumentEvent::ACTION_REVOKED,
            default => KnowledgeDocumentEvent::ACTION_SUBMITTED,
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordEvent(
        KnowledgeDocument $document,
        string $action,
        ?string $from,
        ?string $to,
        ?User $actor,
        array $metadata = [],
    ): void {
        KnowledgeDocumentEvent::query()->create([
            'document_id' => $document->getKey(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'actor_id' => $actor?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'occurred_at' => now(),
        ]);
    }
}
