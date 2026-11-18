<?php

namespace Modules\KnowledgeBase\Services;

use App\Platform\FeatureGate\Contracts\ResolvesModuleState;
use App\Platform\FeatureGate\ModuleScopeKey;
use App\Platform\ModuleRegistry\Contracts\RegistersModules;
use App\Platform\ModuleRegistry\ModuleScope;
use Modules\KnowledgeBase\Support\KnowledgeGrounding;
use Modules\KnowledgeBase\Support\KnowledgeRetrievalContext;
use Throwable;

/**
 * WS9.C — the SINA RAG gateway.
 *
 * It decides whether the acting tenant has the knowledge_base module ACTIVE at
 * the tenant scope (installed + enabled + healthy, derived from trusted central
 * state — never client input) and, if so, runs a tenant-safe retrieval and
 * packages the hits as quoted reference DATA plus structured citations.
 *
 * It is deliberately FAIL-OPEN toward the answer and fail-CLOSED toward
 * knowledge: a config gap, an inactive module, a blank query or any retrieval
 * failure yields NO grounding (null) rather than an error, so RAG can never
 * break or block a SINA reply. Tenant isolation is enforced by the retriever
 * from the server-derived tenant id, so no request can ground on another
 * tenant's documents.
 */
final class KnowledgeGroundingService
{
    private const MODULE = 'knowledge_base';

    public function __construct(
        private readonly RegistersModules $registry,
        private readonly ResolvesModuleState $state,
        private readonly KnowledgeRetriever $retriever,
    ) {}

    /**
     * Whether the tenant has an active, healthy knowledge_base module.
     */
    public function isActiveFor(string $tenantId): bool
    {
        if ($tenantId === '' || ! (bool) config('lba.knowledge.enabled', true)) {
            return false;
        }

        $manifest = $this->registry->find(self::MODULE);

        if ($manifest === null) {
            return false;
        }

        $state = $this->state->stateFor($manifest, new ModuleScopeKey(ModuleScope::TENANT, $tenantId));

        return $state->installed && $state->enabled && $state->healthy;
    }

    /**
     * Retrieve approved knowledge for ONE tenant and package it as grounding.
     *
     * Returns null when the module is inactive (no RAG at all for this turn);
     * returns a NON-grounded grounding when the module is active but nothing
     * relevant matched, so the caller can surface the abstention disclosure.
     */
    public function ground(string $tenantId, string $query, ?string $language = null): ?KnowledgeGrounding
    {
        if (trim($query) === '' || ! $this->isActiveFor($tenantId)) {
            return null;
        }

        try {
            $hits = $this->retriever->retrieve(
                $query,
                KnowledgeRetrievalContext::forTenant($tenantId, ['language' => $language]),
            );
        } catch (Throwable) {
            // Retrieval must never block a medically-safe SINA reply.
            return KnowledgeGrounding::none();
        }

        return KnowledgeGrounding::fromHits($hits);
    }
}
