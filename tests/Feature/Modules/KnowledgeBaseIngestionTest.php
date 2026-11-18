<?php

namespace Tests\Feature\Modules;

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
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Exceptions\KnowledgeBaseException;
use Modules\KnowledgeBase\Models\KnowledgeCollection;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeDocumentEvent;
use Modules\KnowledgeBase\Models\KnowledgeDocumentVersion;
use Modules\KnowledgeBase\Models\KnowledgeExtractedPage;
use Modules\KnowledgeBase\Services\KnowledgeAccessPolicy;
use Modules\KnowledgeBase\Services\KnowledgeCollectionManager;
use Modules\KnowledgeBase\Services\KnowledgeDocumentManager;
use Modules\KnowledgeBase\Services\KnowledgeIngestionService;
use Modules\KnowledgeBase\Support\KnowledgeScope;
use Tests\Support\BuildsAccess;
use Tests\Support\BuildsBranches;
use Tests\Support\BuildsTenantDatabases;
use Tests\TestCase;

/**
 * Workstream 9 — WS9.A knowledge registry + secure PDF intake.
 *
 * Every PDF and identity here is synthetic. The tests cover: selectable-text
 * intake with REAL page numbers, checksum de-duplication by scope, clear
 * rejection of scanned/non-PDF files, the human-publish gate (nothing is
 * retrievable before a human publishes), tenant isolation of collections,
 * documents and the private stream, and that the binary is only ever held on
 * the private disk.
 */
class KnowledgeBaseIngestionTest extends TestCase
{
    use BuildsAccess, BuildsBranches, BuildsTenantDatabases, RefreshDatabase;

    private const MODULE = 'knowledge_base';

    private const DISK = 'lba-knowledge';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->bootTenantDatabases();
        Storage::fake(self::DISK);
        Filament::setCurrentPanel(Filament::getPanel('clinic'));
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

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function tenantWithOwner(): array
    {
        $tenant = Tenant::factory()->create();
        $owner = $this->member($tenant, RoleKey::TENANT_OWNER);
        $this->provisionTenantDatabase($tenant);
        $this->bindTenant($tenant);
        $this->activate($tenant);

        return [$tenant, $owner];
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

    private function collection(Tenant $tenant, User $owner, string $name = 'Klinik Reference'): KnowledgeCollection
    {
        return KnowledgeCollectionManager::createTenantCollection(
            (string) $tenant->getKey(),
            ['key' => 'klinik-ref', 'name' => $name, 'language' => 'id'],
            $owner,
        );
    }

    /**
     * Build a minimal, real selectable-text PDF. Each entry in `$pageTexts`
     * becomes one page, written in order, so the extractor must recover the
     * ACTUAL page numbers.
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

    private function tempFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'ws9kb');
        file_put_contents($path, $bytes);

        return $path;
    }

    private function ingest(KnowledgeCollection $collection, string $pdf, ?User $actor, string $title = 'Buku Rujukan'): KnowledgeDocument
    {
        $path = $this->tempFile($pdf);

        try {
            return app(KnowledgeIngestionService::class)->ingest($collection, $path, [
                'title' => $title,
                'author' => 'Synthetic Author',
                'publication' => 'Synthetic Press',
                'license' => 'CC0',
                'language' => 'id',
                'original_filename' => 'reference.pdf',
            ], $actor);
        } finally {
            @unlink($path);
        }
    }

    // ------------------------------------------------------- Intake & pages

    public function test_a_selectable_text_pdf_is_ingested_with_real_page_numbers(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $collection = $this->collection($tenant, $owner);

        $document = $this->ingest($collection, $this->makePdf(['Halaman satu.', 'Halaman dua.']), $owner);

        $this->assertSame(KnowledgeDocument::STATUS_REVIEW, $document->status);
        $this->assertSame((string) $owner->getKey(), (string) $document->uploaded_by);
        $this->assertSame(1, $document->current_version);

        $version = $document->currentVersionRecord();
        $this->assertNotNull($version);
        $this->assertTrue($version->isExtracted());
        $this->assertSame(2, $version->page_count);
        $this->assertSame(64, strlen($version->sha256));

        // Real page numbers: page 1 holds the first page's text, page 2 the
        // second — never offset or invented.
        $this->assertSame(
            [1, 2],
            KnowledgeExtractedPage::query()
                ->where('document_version_id', $version->getKey())
                ->orderBy('page_number')
                ->pluck('page_number')
                ->all(),
        );
        $this->assertStringContainsString('Halaman satu.', (string) KnowledgeExtractedPage::query()
            ->where('document_version_id', $version->getKey())->where('page_number', 1)->value('content'));
        $this->assertStringContainsString('Halaman dua.', (string) KnowledgeExtractedPage::query()
            ->where('document_version_id', $version->getKey())->where('page_number', 2)->value('content'));

        // The binary lives ONLY on the private disk, under an opaque path.
        $this->assertTrue(Storage::disk(self::DISK)->exists($version->storage_path));
        $this->assertStringStartsWith('knowledge/', $version->storage_path);

        // The full lifecycle is audited.
        $this->assertSame(1, KnowledgeDocumentEvent::query()
            ->where('document_id', $document->getKey())
            ->where('action', KnowledgeDocumentEvent::ACTION_UPLOADED)
            ->count());
        $this->assertSame(1, KnowledgeDocumentEvent::query()
            ->where('document_id', $document->getKey())
            ->where('action', KnowledgeDocumentEvent::ACTION_EXTRACTED)
            ->count());
    }

    public function test_the_same_pdf_in_the_same_scope_is_deduplicated_by_checksum(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $collection = $this->collection($tenant, $owner);
        $pdf = $this->makePdf(['Kandungan sama.']);

        $first = $this->ingest($collection, $pdf, $owner, 'First');
        $second = $this->ingest($collection, $pdf, $owner, 'Second');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, KnowledgeDocument::query()->count());
        $this->assertSame(1, KnowledgeDocumentVersion::query()->count());
    }

    public function test_a_scanned_pdf_without_selectable_text_is_rejected(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $collection = $this->collection($tenant, $owner);

        try {
            $this->ingest($collection, $this->makePdf(['']), $owner);
            $this->fail('A scanned/image-only PDF must be rejected, never silently accepted.');
        } catch (KnowledgeBaseException $exception) {
            $this->assertSame('not_extractable', $exception->errorCode());
        }

        // The rejection is traceable: the version failed and the document is
        // revoked, so a partial import can never become an approved source.
        $version = KnowledgeDocumentVersion::query()->firstOrFail();
        $this->assertSame(KnowledgeDocumentVersion::EXTRACTION_FAILED, $version->extraction_status);

        $document = KnowledgeDocument::query()->firstOrFail();
        $this->assertSame(KnowledgeDocument::STATUS_REVOKED, $document->status);
        $this->assertSame(1, KnowledgeDocumentEvent::query()
            ->where('document_id', $document->getKey())
            ->where('action', KnowledgeDocumentEvent::ACTION_EXTRACTION_FAILED)
            ->count());
    }

    public function test_a_non_pdf_file_is_rejected(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $collection = $this->collection($tenant, $owner);

        try {
            $this->ingest($collection, 'this is plainly not a pdf', $owner);
            $this->fail('A non-PDF upload must be rejected.');
        } catch (KnowledgeBaseException $exception) {
            $this->assertSame('unsupported_file_type', $exception->errorCode());
        }

        $this->assertSame(0, KnowledgeDocument::query()->count());
    }

    // ----------------------------------------------------- Publish gate

    public function test_nothing_is_retrievable_until_a_human_publishes_it(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $collection = $this->collection($tenant, $owner);
        $document = $this->ingest($collection, $this->makePdf(['Fakta perubatan.']), $owner);

        $policy = app(KnowledgeAccessPolicy::class);

        $this->assertSame(KnowledgeDocument::STATUS_REVIEW, $document->status);
        $this->assertFalse($policy->canRead($owner, $document), 'A review-status document must not be readable.');

        app(KnowledgeDocumentManager::class)->verify($document, $owner);
        app(KnowledgeDocumentManager::class)->publish($document, $owner);
        $document->refresh();

        $this->assertSame(KnowledgeDocument::STATUS_PUBLISHED, $document->status);
        $this->assertSame((string) $owner->getKey(), (string) $document->approved_by);
        $this->assertNotNull($document->approved_at);
        $this->assertNotNull($document->published_at);
        $this->assertTrue($policy->canRead($owner, $document));

        // Revocation removes it from retrieval again.
        app(KnowledgeDocumentManager::class)->revoke($document, $owner, 'superseded');
        $this->assertFalse($policy->canRead($owner, $document->refresh()));
    }

    public function test_an_invalid_lifecycle_transition_is_rejected(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $collection = $this->collection($tenant, $owner);
        $document = $this->ingest($collection, $this->makePdf(['Fakta.']), $owner);

        // review -> archived is not a legal transition.
        try {
            app(KnowledgeDocumentManager::class)->transition($document, KnowledgeDocument::STATUS_ARCHIVED, $owner);
            $this->fail('An illegal transition must be rejected.');
        } catch (KnowledgeBaseException $exception) {
            $this->assertSame('invalid_transition', $exception->errorCode());
        }
    }

    // ----------------------------------------------------- Tenant isolation

    public function test_a_tenant_scoped_collection_is_only_visible_to_its_owner(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithOwner();
        $this->collection($tenantA, $ownerA, 'Tenant A Ref');

        [$tenantB, $ownerB] = $this->tenantWithOwner();
        $this->collection($tenantB, $ownerB, 'Tenant B Ref');

        // Both collections exist in the shared registry.
        $this->assertSame(2, KnowledgeCollection::query()->count());

        // Tenant A can only ever see its own collection.
        $this->bindTenant($tenantA);
        $visible = KnowledgeCollection::query()
            ->where('scope', KnowledgeScope::TENANT)
            ->where('scope_key', (string) $tenantA->getKey())
            ->pluck('name')
            ->all();
        $this->assertSame(['Tenant A Ref'], $visible);
    }

    public function test_cross_tenant_document_access_fails_closed(): void
    {
        [$tenantA, $ownerA] = $this->tenantWithOwner();
        $collectionA = $this->collection($tenantA, $ownerA);
        $documentA = $this->ingest($collectionA, $this->makePdf(['Rahasia Tenant A.']), $ownerA);
        app(KnowledgeDocumentManager::class)->publish($documentA, $ownerA);

        [$tenantB, $ownerB] = $this->tenantWithOwner();

        // The policy refuses tenant B outright.
        $this->assertFalse(app(KnowledgeAccessPolicy::class)->canRead($ownerB, $documentA));

        // The private stream authorizes on the SERVER and leaks nothing: a
        // different tenant's id resolves to 404, not the document.
        $response = $this->actingAs($ownerB)
            ->get(route('lba.knowledge.documents.download', ['document' => $documentA->getKey()]));

        $response->assertNotFound();
    }

    public function test_the_private_stream_serves_a_published_document_to_its_tenant(): void
    {
        [$tenant, $owner] = $this->tenantWithOwner();
        $collection = $this->collection($tenant, $owner);
        $document = $this->ingest($collection, $this->makePdf(['Boleh dibaca.']), $owner);
        app(KnowledgeDocumentManager::class)->publish($document, $owner);

        $response = $this->actingAs($owner)
            ->get(route('lba.knowledge.documents.download', ['document' => $document->getKey()]));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
