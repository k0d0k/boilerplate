<?php

namespace Modules\KnowledgeBase\Exceptions;

use RuntimeException;

/**
 * WS9 — a knowledge base rule was violated.
 *
 * Every code is stable so callers (the Filament panel, the ingestion queue, the
 * retrieval service) can map it to a safe outcome without leaking internals.
 */
final class KnowledgeBaseException extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public static function missingContext(): self
    {
        return new self('missing_context', 'No verified tenant context is active.');
    }

    public static function unknownCollection(): self
    {
        return new self('unknown_collection', 'The selected knowledge collection does not belong to this scope.');
    }

    public static function unauthorizedScope(): self
    {
        return new self('unauthorized_scope', 'The acting identity may not manage knowledge in this scope.');
    }

    public static function unsupportedFileType(): self
    {
        return new self('unsupported_file_type', 'Only selectable-text PDF documents may be ingested.');
    }

    public static function fileTooLarge(): self
    {
        return new self('file_too_large', 'The PDF exceeds the maximum allowed size.');
    }

    public static function tooManyPages(): self
    {
        return new self('too_many_pages', 'The PDF exceeds the maximum allowed page count.');
    }

    public static function notExtractable(): self
    {
        return new self('not_extractable', 'No selectable text could be extracted from this PDF. Scanned or image-only files are not supported yet.');
    }

    public static function invalidTransition(string $from, string $to): self
    {
        return new self('invalid_transition', "A document in status [{$from}] cannot move to [{$to}].");
    }

    public static function duplicateHash(): self
    {
        return new self('duplicate_hash', 'An identical document already exists in this scope.');
    }

    public static function unknownVersion(): self
    {
        return new self('unknown_version', 'The document version could not be resolved.');
    }

    public static function notPublished(): self
    {
        return new self('not_published', 'The document is not published and cannot be retrieved.');
    }

    public static function retrievalUnavailable(): self
    {
        return new self('retrieval_unavailable', 'The retrieval service is not available for this clinic.');
    }

    public static function embeddingFailed(string $provider, string $reason = ''): self
    {
        $detail = $reason === '' ? '' : " ({$reason})";

        return new self('embedding_failed', "The {$provider} embedding provider failed{$detail}.");
    }
}
