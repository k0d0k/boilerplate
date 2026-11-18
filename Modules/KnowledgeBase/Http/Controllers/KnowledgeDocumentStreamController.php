<?php

namespace Modules\KnowledgeBase\Http\Controllers;

use App\Models\User;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\FeatureGate\ModuleGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\KnowledgeBase\Models\KnowledgeDocument;
use Modules\KnowledgeBase\Models\KnowledgeDocumentVersion;
use Modules\KnowledgeBase\Services\KnowledgeAccessPolicy;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WS9.A — authenticated, tenant-scoped private streaming of an approved
 * knowledge PDF.
 *
 * An approved source PDF is a private document: it lives on the private
 * `lba-knowledge` disk and is only ever read back through this endpoint. There
 * is no public path, no public disk and no signed URL — a citation link is
 * ALWAYS re-authorized on each request. Access is decided entirely on the
 * SERVER:
 *
 *  - a signed-in, active identity is required;
 *  - the `knowledge_base` module must be active in the Clinic panel;
 *  - the document must be PUBLISHED (an unapproved upload can never leak);
 *  - {@see KnowledgeAccessPolicy} re-derives the tenant from trusted services,
 *    so a tenant-scope document is readable ONLY by its owning tenant and a
 *    cross-tenant id fails closed (404, never 403 — ids are not probeable);
 *  - the stored object must still exist.
 */
final class KnowledgeDocumentStreamController
{
    public function __construct(
        private readonly ModuleGate $moduleGate,
        private readonly KnowledgeAccessPolicy $access,
    ) {}

    public function download(Request $request, string $document): StreamedResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_active, 403);

        abort_unless(
            $this->moduleGate->allows('knowledge_base', PanelAccessPolicy::PANEL_CLINIC),
            403,
        );

        /** @var KnowledgeDocument|null $record */
        $record = KnowledgeDocument::query()->whereKey($document)->first();

        abort_if($record === null, 404);

        abort_unless($this->access->canRead($user, $record), 404);

        /** @var KnowledgeDocumentVersion|null $version */
        $version = $record->currentVersionRecord();

        abort_if($version === null || ! $version->isExtracted(), 404);

        $disk = Storage::disk((string) $version->storage_disk);

        abort_unless($disk->exists((string) $version->storage_path), 404);

        $stream = $disk->readStream((string) $version->storage_path);

        abort_if($stream === false || $stream === null, 404);

        $filename = $version->original_filename !== null && $version->original_filename !== ''
            ? $version->original_filename
            : $record->title.'.pdf';

        return response()->stream(function () use ($stream): void {
            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $filename).'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
