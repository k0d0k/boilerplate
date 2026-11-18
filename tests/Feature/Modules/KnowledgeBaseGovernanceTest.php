<?php

namespace Tests\Feature\Modules;

use App\Filament\Principal\Pages\KnowledgeGovernance;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\RoleKey;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\Models\ModuleInstallation;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\ModuleRegistry\ModuleScope;
use Database\Seeders\RbacSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Filament\Pages\KnowledgeConsole;
use Modules\KnowledgeBase\Models\KnowledgeChunk;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Services\KnowledgeCollectionManager;
use Modules\KnowledgeBase\Services\KnowledgeDocumentManager;
use Modules\KnowledgeBase\Services\KnowledgeIndexer;
use Modules\KnowledgeBase\Services\KnowledgeIngestionService;
use Modules\KnowledgeBase\Services\KnowledgeOperations;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsPrincipalGovernance;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Workstream 9 (WS9.D) — the clinic knowledge console and the Principal
 * platform-knowledge governance surface.
 *
 * Proves the two management surfaces are strictly scope-bound: the clinic
 * console can only ever see and retry the CURRENT tenant's TENANT-scope
 * documents, and the Principal page can only ever see and mutate PLATFORM-scope
 * documents. Access is enforced by the module gate plus the canonical
 * permission — never by UI visibility — and neither surface can read another
 * scope's knowledge or any patient data. Every PDF and identity is synthetic.
 */
class KnowledgeBaseGovernanceTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsPrincipalGovernance, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'knowledge_base';

    private const DISK = 'lba-knowledge';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Storage::fake(self::DISK);

        config([
            'lba.knowledge.enabled' => true,
            'lba.knowledge.embedding.provider' => 'none',
        ]);
    }

    protected function tearDown(): void
    {
        $this->teardownTenantDatabases();

        parent::tearDown();
    }

    // --------------------------------------------------------------- Helpers

    private function member(Tenant $tenant, string $roleKey): User
    {
        $user = User::factory()->role($roleKey)->create();
        $this->giveMembership($user, $tenant, [$roleKey], TenantMembership::STATUS_ACTIVE);

        return $user;
    }

    private function bindTenant(Tenant $tenant): void
    {
        $this->tenantDatabases()->configure((string) $tenant->getKey());
    }

    private function activate(Tenant $tenant): void
    {
        if (! RegisteredModule::query()->where('module_key', self::MODULE)->exists()) {
            RegisteredModule::factory()->forKey(self::MODULE)->enabled()->create();
        }

        ModuleInstallation::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->enabled()
            ->create();

        ModuleEntitlement::factory()
            ->forModule(self::MODULE)
            ->forScope(ModuleScope::TENANT, (string) $tenant->getKey())
            ->granted()
            ->create();
    }

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithOwner(bool $knowledge = true): array
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);

        if ($knowledge) {
            $this->activate($tenant);
        }

        return [$tenant, $owner];
    }

    /**
     * A minimal, real selectable-text PDF — one page per entry, in order.
     *
     * @param  list<string>  $pageTexts
     */
    private function makePdf(array $pageTexts): string
    {
        $objects = [];
        $refs = [];
        $number = 3;

        foreach ($pageTexts as $text) {
            $page = $number;
            $content = $number + 1;
            $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
            $objects[$page] = "<< /Type /Page /Parent 2 0 R /Contents {$content} 0 R >>";
            $objects[$content] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
            $refs[] = "{$page} 0 R";
            $number += 2;
        }

        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $refs).'] /Count '.count($pageTexts).' >>';

        ksort($objects);

        $pdf = "%PDF-1.4\n";

        foreach ($objects as $num => $body) {
            $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
        }

        return $pdf."trailer\n<< /Root 1 0 R >>\n%%EOF";
    }

    /**
     * @param  list<string>  $pageTexts
     */
    private function pdfUpload(array $pageTexts): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ws9gov');
        file_put_contents($path, $this->makePdf($pageTexts));

        return new UploadedFile($path, 'reference.pdf', 'application/pdf', null, true);
    }

    /**
     * @param  list<string>  $pageTexts
     */
    private function publishTenantDocument(Tenant $tenant, User $owner, array $pageTexts, string $title = 'Buku Rujukan'): KnowledgeDocument
    {
        $collection = KnowledgeCollectionManager::createTenantCollection(
            (string) $tenant->getKey(),
            ['key' => 'ref-'.substr(md5($title), 0, 10), 'name' => 'Clinic Reference', 'language' => 'id'],
            $owner,
        );

        $path = tempnam(sys_get_temp_dir(), 'ws9gov');
        file_put_contents($path, $this->makePdf($pageTexts));

        try {
            $document = app(KnowledgeIngestionService::class)->ingest($collection, $path, [
                'title' => $title,
                'author' => 'Synthetic Author',
                'language' => 'id',
                'original_filename' => 'reference.pdf',
            ], $owner);
        } finally {
            @unlink($path);
        }

        $manager = app(KnowledgeDocumentManager::class);
        $manager->verify($document, $owner);
        $manager->publish($document->refresh(), $owner);

        $document = $document->refresh();
        app(KnowledgeIndexer::class)->reindex($document);

        return $document;
    }

    private function chunkCount(KnowledgeDocument $document): int
    {
        return KnowledgeChunk::query()->where('document_id', $document->getKey())->count();
    }

    // ============================================ Clinic knowledge console

    public function test_the_console_requires_an_active_knowledge_module(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner(knowledge: false);

        $this->createBranch($tenant);

        Filament::setCurrentPanel(Filament::getPanel('clinic'));
        $this->actingAs($owner);

        $this->assertFalse(KnowledgeConsole::canAccess());
        $this->get('/clinic/knowledge/console')->assertForbidden();
    }

    public function test_the_console_renders_for_an_entitled_clinic_owner(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();

        $this->createBranch($tenant);

        Filament::setCurrentPanel(Filament::getPanel('clinic'));
        $this->actingAs($owner);

        $this->assertTrue(KnowledgeConsole::canAccess());
        $this->get('/clinic/knowledge/console')->assertOk();
    }

    public function test_a_role_without_knowledge_manage_cannot_open_the_console(): void
    {
        [$tenant] = $this->tenantWithOwner();
        $receptionist = $this->member($tenant, RoleKey::RECEPTIONIST);

        $this->createBranch($tenant);

        Filament::setCurrentPanel(Filament::getPanel('clinic'));
        $this->actingAs($receptionist);

        $this->assertFalse(KnowledgeConsole::canAccess());
        $this->get('/clinic/knowledge/console')->assertForbidden();
    }

    public function test_the_console_probe_returns_document_provenance_only(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $document = $this->publishTenantDocument($tenant, $owner, [
            'Panduan umum tentang kesehatan keluarga.',
            'Malaria adalah penyakit menular yang disebabkan oleh parasit Plasmodium.',
        ]);

        $this->bindTenant($tenant);
        $this->actingAs($owner);

        $console = new KnowledgeConsole;
        $console->query = 'penyebab penyakit malaria';
        $console->runProbe();

        $this->assertTrue($console->probed);
        $this->assertNotEmpty($console->results);

        $row = $console->results[0];

        // Provenance ONLY — the audit never returns patient text or identifiers.
        $this->assertSame(
            ['document_id', 'version', 'page', 'title', 'score', 'url', 'platform'],
            array_keys($row),
        );
        $this->assertSame((string) $document->getKey(), $row['document_id']);
        $this->assertSame(2, $row['page']);
        $this->assertSame('Buku Rujukan', $row['title']);
        $this->assertFalse($row['platform']);
        $this->assertStringContainsString('knowledge/documents/'.$document->getKey().'/download', $row['url']);
    }

    public function test_the_console_never_probes_another_tenants_documents(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithOwner();
        $this->publishTenantDocument($tenantA, $ownerA, [
            'Malaria adalah penyakit menular yang disebabkan oleh parasit Plasmodium.',
        ], 'Klinik A');

        [$tenantB, $ownerB] = $this->tenantWithOwner();
        $this->bindTenant($tenantB);
        $this->actingAs($ownerB);

        $console = new KnowledgeConsole;
        $console->query = 'penyebab penyakit malaria';
        $console->runProbe();

        $this->assertSame([], $console->results);
    }

    public function test_the_console_retries_a_failed_index_build(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $document = $this->publishTenantDocument($tenant, $owner, [
            'Malaria adalah penyakit menular yang disebabkan oleh parasit Plasmodium.',
        ]);

        $this->assertGreaterThan(0, $this->chunkCount($document));

        // Simulate a failed indexing job: the chunks are gone but the document
        // is still published and its pages are still extracted.
        app(KnowledgeIndexer::class)->purge($document);
        $this->assertSame(0, $this->chunkCount($document));

        $this->bindTenant($tenant);
        $this->actingAs($owner);

        $console = new KnowledgeConsole;
        $console->retryIndex((string) $document->getKey());

        $this->assertGreaterThan(0, $this->chunkCount($document));
    }

    public function test_the_console_cannot_retry_another_tenants_document(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithOwner();
        $documentA = $this->publishTenantDocument($tenantA, $ownerA, [
            'Malaria adalah penyakit menular yang disebabkan oleh parasit Plasmodium.',
        ]);

        app(KnowledgeIndexer::class)->purge($documentA);
        $this->assertSame(0, $this->chunkCount($documentA));

        [$tenantB, $ownerB] = $this->tenantWithOwner();
        $this->bindTenant($tenantB);
        $this->actingAs($ownerB);

        $console = new KnowledgeConsole;
        $console->retryIndex((string) $documentA->getKey());

        // The foreign document was never resolved — it is still un-indexed.
        $this->assertSame(0, $this->chunkCount($documentA));
    }

    // ====================================== Principal knowledge governance

    public function test_the_governance_page_requires_platform_knowledge_permission(): void
    {
        $finance = $this->principal(RoleKey::PRINCIPAL_FINANCE);

        Filament::setCurrentPanel(Filament::getPanel('principal'));
        $this->actingAs($finance);

        $this->assertFalse(KnowledgeGovernance::canAccess());
        $this->get('/principal/knowledge-governance')->assertForbidden();
    }

    public function test_a_clinic_identity_cannot_reach_the_governance_page(): void
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);

        Filament::setCurrentPanel(Filament::getPanel('principal'));
        $this->actingAs($owner);

        $this->assertFalse(KnowledgeGovernance::canAccess());
        $this->get('/principal/knowledge-governance')->assertForbidden();
    }

    public function test_an_authorized_principal_can_open_the_governance_page(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));
        $this->actingAs($this->principal());

        $this->assertTrue(KnowledgeGovernance::canAccess());
        $this->get('/principal/knowledge-governance')->assertOk();
    }

    public function test_a_principal_creates_a_platform_scope_collection(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));
        $this->actingAs($this->principal());

        $page = new KnowledgeGovernance;
        $page->collectionName = 'Platform Reference';
        $page->collectionLanguage = 'id';
        $page->createCollection();

        $collection = KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('scope_key', '')
            ->where('name', 'Platform Reference')
            ->firstOrFail();

        $this->assertSame('id', $collection->language);
        $this->assertSame(0, KnowledgeCollection::query()->where('scope', KnowledgeScope::TENANT)->count());
    }

    public function test_a_principal_ingests_publishes_and_reindexes_a_platform_document(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));
        $this->actingAs($this->principal());

        $page = new KnowledgeGovernance;
        $page->collectionName = 'Platform Reference';
        $page->collectionLanguage = 'id';
        $page->createCollection();

        $collection = KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('name', 'Platform Reference')
            ->firstOrFail();

        $page->collectionId = (string) $collection->getKey();
        $page->documentTitle = 'Panduan Platform';
        $page->documentLanguage = 'id';
        $page->pdf = $this->pdfUpload([
            'Panduan umum tentang kesehatan keluarga.',
            'Malaria adalah penyakit menular yang disebabkan oleh parasit Plasmodium.',
        ]);
        $page->uploadDocument();

        $document = KnowledgeDocument::query()
            ->where('scope', KnowledgeScope::PLATFORM)
            ->where('title', 'Panduan Platform')
            ->firstOrFail();

        $this->assertSame(KnowledgeDocument::STATUS_REVIEW, $document->status);

        // The governance page lists its OWN scope's documents.
        $listed = array_column($page->documents(), 'id');
        $this->assertContains((string) $document->getKey(), $listed);

        $page->publishDocument((string) $document->getKey());

        $document->refresh();

        $this->assertSame(KnowledgeDocument::STATUS_PUBLISHED, $document->status);
        $this->assertGreaterThan(0, $this->chunkCount($document));

        // A published platform source is reachable from a tenant's own console,
        // which is the whole point of a platform-scope reference library.
        $tenant = Tenant::factory()->create();
        $hits = app(KnowledgeOperations::class)->probe((string) $tenant->getKey(), 'penyebab penyakit malaria', 'id');
        $this->assertNotEmpty($hits);
        $this->assertTrue((bool) $hits[0]['platform']);
    }

    public function test_revoking_a_platform_document_purges_the_index(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('principal'));
        $this->actingAs($this->principal());

        $collection = KnowledgeCollectionManager::createPlatformCollection([
            'key' => 'platform-rev',
            'name' => 'Revocation Reference',
            'language' => 'id',
        ], $this->principal());

        $path = tempnam(sys_get_temp_dir(), 'ws9gov');
        file_put_contents($path, $this->makePdf(['Malaria adalah penyakit menular.']));

        try {
            $document = app(KnowledgeIngestionService::class)->ingest($collection, $path, [
                'title' => 'Revocable',
                'language' => 'id',
            ], $this->principal());
        } finally {
            @unlink($path);
        }

        $manager = app(KnowledgeDocumentManager::class);
        $manager->verify($document, $this->principal());
        $manager->publish($document->refresh(), $this->principal());
        app(KnowledgeIndexer::class)->reindex($document->refresh());

        $this->assertGreaterThan(0, $this->chunkCount($document));

        $page = new KnowledgeGovernance;
        $page->revokeDocument((string) $document->getKey());

        $this->assertSame(KnowledgeDocument::STATUS_REVOKED, $document->refresh()->status);
        $this->assertSame(0, $this->chunkCount($document));
    }

    public function test_the_platform_page_never_sees_or_touches_tenant_documents(): void
    {
        // A tenant-scope document, published and indexed by its own clinic.
        [$tenant, $owner] = $this->tenantWithOwner();
        $tenantDocument = $this->publishTenantDocument($tenant, $owner, [
            'Malaria adalah penyakit menular yang disebabkan oleh parasit Plasmodium.',
        ], 'Klinik Rahasia');

        Filament::setCurrentPanel(Filament::getPanel('principal'));
        $this->actingAs($this->principal());

        $page = new KnowledgeGovernance;

        // The platform listing must never include another scope's document.
        $listed = array_column($page->documents(), 'id');
        $this->assertNotContains((string) $tenantDocument->getKey(), $listed);

        // A platform action targeting a tenant-scope id is a no-op.
        $page->revokeDocument((string) $tenantDocument->getKey());

        $this->assertSame(KnowledgeDocument::STATUS_PUBLISHED, $tenantDocument->refresh()->status);
        $this->assertGreaterThan(0, $this->chunkCount($tenantDocument));
    }
}
