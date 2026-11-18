<?php

namespace Modules\KnowledgeBase\Providers;

use Illuminate\Support\Facades\Route;
use Modules\KnowledgeBase\Console\EvaluateKnowledgeCommand;
use Modules\KnowledgeBase\Http\Controllers\KnowledgeDocumentStreamController;
use Nwidart\Modules\Support\ModuleServiceProvider;

/**
 * Workstream 9 — SINA MEDCloud knowledge base module service provider.
 *
 * The knowledge tables live in the CENTRAL platform database
 * (`database/migrations/`), exactly like the SINA wallet and AI provider
 * config: they hold non-patient reference metadata (titles, hashes, approval
 * status, chunks) and NEVER clinical records. Tenant isolation is enforced on
 * every read by an explicit, server-derived scope/tenant filter — a client can
 * never choose the tenant.
 *
 * Besides the base module wiring (translations `knowledgebase::…`) the provider
 * registers ONE application route: the authenticated, tenant-scoped knowledge
 * PDF streaming endpoint. It is the only way an approved PDF is ever read back
 * — there is no public path, no public disk and no signed URL.
 */
class KnowledgeBaseServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'KnowledgeBase';

    protected string $nameLower = 'knowledgebase';

    public function boot(): void
    {
        parent::boot();

        // The ONLY way an approved knowledge PDF is read back: an authenticated,
        // tenant-scoped private stream. The controller re-derives the caller's
        // epoch-verified tenant/branch scope on every request, so a citation link
        // can never leak another tenant's document.
        Route::middleware(['web', 'auth'])
            ->get('lba/knowledge/documents/{document}/download', [KnowledgeDocumentStreamController::class, 'download'])
            ->name('lba.knowledge.documents.download');

        // WS9.D — the synthetic retrieval/citation evaluation command.
        if ($this->app->runningInConsole()) {
            $this->commands([EvaluateKnowledgeCommand::class]);
        }
    }
}
