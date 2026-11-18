<?php

namespace Modules\LBA\Ingest;

use App\Models\LbaIngestCredential;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use App\Platform\FeatureGate\ModuleAccessRequest;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Illuminate\Support\Str;
use Modules\LBA\Exceptions\ExaminationException;
use Modules\LBA\Models\Examination;

/**
 * WS6.B — issue and revoke scoped agent ingestion credentials.
 *
 * A credential is SHORT LIVED, bound to one tenant/branch/operator and a single
 * SELECTED EDITABLE examination, and only its hash is persisted. Issuance
 * re-runs the same gates the image store enforces (module access, `exam.update`,
 * editability, processable mode, granted image-processing consent) so a
 * credential is never minted for an examination that could not accept an image
 * anyway. Both issue and revoke append a redacted central audit record.
 */
final class IngestCredentialIssuer
{
    public function __construct(
        private readonly ChecksPermissions $permissions,
        private readonly EvaluatesModuleAccess $gate,
    ) {}

    /**
     * @return array{credential: LbaIngestCredential, plain: string}
     */
    public function issue(
        User $operator,
        string $tenantId,
        Examination $examination,
        ?string $deviceLabel = null,
    ): array {
        $branchId = (string) $examination->branch_id;
        $context = AccessContext::forTenant($tenantId, $branchId);

        $allowed = $this->gate->allows(new ModuleAccessRequest(
            moduleKey: 'lba',
            panelId: PanelAccessPolicy::PANEL_CLINIC,
            user: $operator,
            context: $context,
        )) && $this->permissions->allows($operator, PermissionKey::EXAM_UPDATE, $context);

        if (! $allowed) {
            $this->audit('ingest_credential_issue', ModuleAuditLog::RESULT_DENIED, 'unauthorized', $tenantId, $operator);

            throw ExaminationException::ingestNotAuthorized();
        }

        $this->assertExaminationAcceptsImages($examination);

        $plain = Str::random(64);

        $credential = LbaIngestCredential::query()->create([
            'user_id' => $operator->getKey(),
            'tenant_id' => $tenantId,
            'examination_id' => (string) $examination->getKey(),
            'branch_id' => $branchId,
            'name' => $deviceLabel,
            'token' => LbaIngestCredential::hash($plain),
            'abilities' => [LbaIngestCredential::ABILITY_INGEST],
            'expires_at' => now()->addMinutes((int) config('lba.ingest.credential_ttl_minutes', 60)),
        ]);

        $this->audit('ingest_credential_issue', ModuleAuditLog::RESULT_ALLOWED, null, $tenantId, $operator, [
            'credential_id' => (string) $credential->getKey(),
            'examination_id' => (string) $examination->getKey(),
        ]);

        return ['credential' => $credential, 'plain' => $plain];
    }

    /**
     * Revoke a credential. Idempotent: a cancelled/again-revoked credential is
     * left revoked and the audit still records the intent.
     */
    public function revoke(LbaIngestCredential $credential, ?User $actor = null): void
    {
        if (! $credential->isRevoked()) {
            $credential->forceFill(['revoked_at' => now()])->save();
        }

        $this->audit(
            'ingest_credential_revoke',
            ModuleAuditLog::RESULT_ALLOWED,
            null,
            (string) $credential->tenant_id,
            $actor,
            ['credential_id' => (string) $credential->getKey()],
        );
    }

    private function assertExaminationAcceptsImages(Examination $examination): void
    {
        if (! $examination->isEditable()) {
            throw ExaminationException::notEditable((string) $examination->status);
        }

        if (! in_array($examination->mode, Examination::PROCESSABLE_MODES, true)) {
            throw ExaminationException::modeNotProcessable((string) $examination->mode);
        }

        if (! $examination->hasGrantedImageProcessingConsent()) {
            throw ExaminationException::consentRequired();
        }
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function audit(
        string $action,
        string $result,
        ?string $reason,
        string $tenantId,
        ?User $actor,
        ?array $metadata = null,
    ): void {
        ModuleAuditLog::query()->create([
            'module_key' => 'lba',
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::TENANT,
            'scope_key' => $tenantId,
            'actor_id' => $actor?->getKey(),
            'metadata' => $metadata,
        ]);
    }
}
