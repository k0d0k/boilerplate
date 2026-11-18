<?php

namespace Modules\LBA\Http\Controllers;

use App\Models\LbaIngestCredential;
use App\Models\User;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Http\Middleware\AuthenticateLbaIngest;
use Modules\LBA\Ingest\IngestCredentialContext;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ExaminationImage;
use Modules\LBA\Services\MicroscopyImageStore;

/**
 * WS6.B / WS6.1 — scoped agent image ingestion.
 *
 * A manufacturer-software export / watched-folder agent POSTs ONE still image
 * here. Every authority decision is derived from the credential resolved by the
 * {@see AuthenticateLbaIngest} middleware — the
 * request body can only supply the image bytes:
 *
 *  - the tenant database is already bound to the credential's tenant;
 *  - the examination is the credential's selected examination (never a
 *    client-supplied id);
 *  - the credential's branch must still match the examination's branch;
 *  - the image runs through the SAME validated, private, immutable intake path
 *    as a manual import ({@see MicroscopyImageStore}), so MIME / size /
 *    dimension / consent / editability gates all still apply.
 *
 * WS6.1 release hardening — ORDER OF OPERATIONS:
 *
 *  1. resolve the credential's examination and re-assert the branch;
 *  2. validate the FILE (real MIME, size, decodability, dimensions) — a
 *     malformed or oversized payload is refused BEFORE any checksum is taken,
 *     so nothing unbounded is ever buffered;
 *  3. re-run the store's admission gates (editability, processable mode,
 *     granted image-processing consent);
 *  4. only THEN look up a checksum duplicate.
 *
 * A duplicate is therefore never an authorization or consent bypass: a repeat
 * upload to a non-editable examination, or one whose image-processing consent
 * has been withdrawn, is refused exactly like a first upload. The minimum
 * non-clinical metadata is returned; no patient data is revealed.
 *
 * Duplicate policy (deterministic + race-safe): two uploads of identical
 * content to the SAME examination are idempotent — the existing immutable
 * original is returned with `200` and NO new row or object is created. An
 * original is never overwritten.
 */
final class IngestController
{
    public function __construct(
        private readonly IngestCredentialContext $context,
        private readonly MicroscopyImageStore $store,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $credential = $this->context->credential();

        if (! $credential instanceof LbaIngestCredential) {
            abort(401);
        }

        $file = $request->file('image') ?? $request->file('file');

        if (! $file instanceof UploadedFile) {
            return $this->error('invalid_request', 'A single [image] file is required.', 422);
        }

        $examination = Examination::query()
            ->whereKey((string) $credential->examination_id)
            ->first();

        // The examination may have been removed, or — if a credential were ever
        // forged across scope — it is not addressable in this tenant database.
        if (! $examination instanceof Examination) {
            return $this->error('not_found', 'The examination is no longer available.', 404);
        }

        if ((string) $examination->branch_id !== (string) $credential->branch_id) {
            return $this->error('not_found', 'The examination is no longer available.', 404);
        }

        // (2) Validate the file by its real content first — before any checksum
        // or duplicate lookup — so an unsupported/incomplete/oversized payload
        // is refused without storing or buffering it.
        try {
            $this->store->validate($file);
        } catch (ExaminationException $exception) {
            $this->audit($credential, ModuleAuditLog::RESULT_DENIED, 'invalid file', $examination);

            return $this->error('rejected', $exception->getMessage(), 422);
        }

        // (3) The SAME admission gates the store enforces, re-checked here so a
        // duplicate can never bypass editability, mode or current consent.
        try {
            $this->store->assertAcceptsImages($examination);
        } catch (ExaminationException $exception) {
            $this->audit($credential, ModuleAuditLog::RESULT_DENIED, 'not accepting images', $examination);

            return $this->error('rejected', $exception->getMessage(), 422);
        }

        $operator = User::query()->find($credential->user_id);

        // (4) Race-safe, idempotent intake: the duplicate lookup happens inside
        // the store under a row lock, and a lost race is absorbed by the
        // additive unique index rather than creating a second record.
        try {
            $result = $this->store->storeIdempotent(
                $examination,
                $file,
                $operator instanceof User ? $operator : null,
                [
                    'source' => ExaminationImage::SOURCE_AGENT_IMPORT,
                    'instrument_label' => (string) $credential->name,
                ],
            );
        } catch (ExaminationException $exception) {
            $this->audit($credential, ModuleAuditLog::RESULT_DENIED, 'store rejected', $examination);

            return $this->error('rejected', $exception->getMessage(), 422);
        }

        if (! $result['duplicate']) {
            LbaIngestCredential::query()
                ->whereKey($credential->getKey())
                ->update(['last_used_at' => now()]);
        }

        $this->audit(
            $credential,
            ModuleAuditLog::RESULT_ALLOWED,
            $result['duplicate'] ? 'duplicate' : null,
            $examination,
        );

        return $this->payload($result['image'], $result['duplicate']);
    }

    private function payload(ExaminationImage $image, bool $duplicate): JsonResponse
    {
        return response()->json([
            'data' => [
                'id' => (string) $image->getKey(),
                'examination_id' => (string) $image->examination_id,
                'checksum' => (string) $image->checksum,
                'mime_type' => (string) $image->mime_type,
                'width' => $image->width,
                'height' => $image->height,
                'size_bytes' => $image->size_bytes,
                'duplicate' => $duplicate,
            ],
        ], $duplicate ? 200 : 201);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    private function audit(
        LbaIngestCredential $credential,
        string $result,
        ?string $reason,
        Examination $examination,
    ): void {
        ModuleAuditLog::query()->create([
            'module_key' => 'lba',
            'action' => 'ingest_request',
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::TENANT,
            'scope_key' => (string) $credential->tenant_id,
            'actor_id' => $credential->user_id,
            'metadata' => ['examination_id' => (string) $examination->getKey()],
        ]);
    }
}
