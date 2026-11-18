<?php

namespace Modules\KnowledgeBase\Filament\Pages;

use App\Platform\Access\PermissionKey;
use App\Platform\Filament\Concerns\AuthorizesModuleResources;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Modules\KnowledgeBase\Evaluation\KnowledgeEvaluationSuite;
use Modules\KnowledgeBase\Filament\Resources\KnowledgeCollectionResource;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Services\KnowledgeOperations;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use UnitEnum;

/**
 * WS9.D — the Clinic-panel knowledge console.
 *
 * An operator-facing diagnostic surface over the clinic's OWN knowledge scope:
 *
 *  - run a retrieval AUDIT for an operator-typed query and see which approved
 *    sources (title + page + score) it resolves to — NEVER any patient text;
 *  - try the locale example questions the evaluation harness uses;
 *  - inspect the indexing status of the clinic's documents and RETRY a failed
 *    index build;
 *  - open a returned citation through the private, authenticated PDF stream to
 *    verify the source page.
 *
 * Everything is re-derived server-side: the tenant is the verified
 * {@see ResolvesTenantDatabase} context and access requires the module to be
 * active AND the acting identity to hold `knowledge.manage`. UI visibility is
 * never the authorization boundary.
 */
class KnowledgeConsole extends Page
{
    use AuthorizesModuleResources;

    public const MODULE_KEY = 'knowledge_base';

    protected static ?string $slug = 'knowledge/console';

    protected static ?int $navigationSort = 32;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected string $view = 'filament.knowledge.pages.knowledge-console';

    /** Operator-typed retrieval query (never a patient message). */
    public string $query = '';

    public string $language = '';

    /**
     * The sanitized retrieval audit rows.
     *
     * @var list<array{document_id: string, version: int, page: int, title: string, score: float, url: string, platform: bool}>
     */
    public array $results = [];

    public bool $probed = false;

    protected static function moduleKey(): string
    {
        return self::MODULE_KEY;
    }

    public static function canAccess(): bool
    {
        return static::moduleActive() && static::allows(PermissionKey::KNOWLEDGE_MANAGE);
    }

    public static function getNavigationLabel(): string
    {
        return __('lba.knowledge.console_nav');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('lba.nav.clinic.knowledge');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lba.knowledge.console_title');
    }

    public function getHeading(): string|Htmlable|null
    {
        return __('lba.knowledge.console_title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('lba.knowledge.console_subtitle');
    }

    /**
     * The verified current tenant, or null when none is bound.
     */
    public function tenantId(): ?string
    {
        $tenantId = app(ResolvesTenantDatabase::class)->current();

        return $tenantId === '' ? null : $tenantId;
    }

    /**
     * @return array<string, string>
     */
    public function languageOptions(): array
    {
        return KnowledgeCollectionResource::languageOptions();
    }

    /**
     * Example questions from the synthetic evaluation suite, in the active
     * locale — so an operator can verify retrieval without inventing a query.
     *
     * @return list<string>
     */
    public function exampleQuestions(): array
    {
        $locale = app()->getLocale();

        return KnowledgeEvaluationSuite::exampleQuestions(
            in_array($locale, KnowledgeEvaluationSuite::LOCALES, true) ? $locale : 'id',
        );
    }

    public function useExample(string $question): void
    {
        $this->query = $question;
    }

    public function runProbe(): void
    {
        $this->validate([
            'query' => ['required', 'string', 'min:2', 'max:500'],
        ]);

        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            $this->results = [];
            $this->probed = true;

            return;
        }

        $this->results = app(KnowledgeOperations::class)->probe(
            $tenantId,
            $this->query,
            $this->language !== '' ? $this->language : null,
        );

        $this->probed = true;
    }

    /**
     * Indexing status of the clinic's OWN documents (most recent first).
     *
     * @return list<array{id: string, title: string, status: string, version: int, page_count: int, chunks: int, embedded: int, failed: int, retryable: bool}>
     */
    public function documents(): array
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            return [];
        }

        $operations = app(KnowledgeOperations::class);

        return KnowledgeDocument::query()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', $tenantId)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(static function (KnowledgeDocument $document) use ($operations): array {
                $status = $operations->status($document);

                return [
                    'id' => (string) $document->getKey(),
                    'title' => (string) $document->title,
                    'status' => __("lba.knowledge.status_{$document->status}"),
                    'version' => (int) ($status['version'] ?? 0),
                    'page_count' => $status['page_count'],
                    'chunks' => $status['chunks'],
                    'embedded' => $status['embedded'],
                    'failed' => $status['failed'],
                    'retryable' => $status['extracted'],
                ];
            })
            ->all();
    }

    /**
     * Retry the idempotent index build for one of the clinic's OWN documents.
     */
    public function retryIndex(string $documentId): void
    {
        $tenantId = $this->tenantId();

        if ($tenantId === null) {
            Notification::make()->danger()->title(__('lba.knowledge.error_not_authorized'))->send();

            return;
        }

        $document = KnowledgeDocument::query()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', $tenantId)
            ->whereKey($documentId)
            ->first();

        if (! $document instanceof KnowledgeDocument) {
            Notification::make()->danger()->title(__('lba.knowledge.error_not_authorized'))->send();

            return;
        }

        $chunks = app(KnowledgeOperations::class)->reindex($document);

        Notification::make()
            ->success()
            ->title(__('lba.knowledge.console_retry_done', ['count' => $chunks]))
            ->send();
    }
}
