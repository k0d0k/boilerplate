<?php

namespace Modules\LBA\Http\Middleware;

use App\Models\LbaIngestCredential;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\PanelAccessPolicy;
use App\Platform\Access\PermissionKey;
use App\Platform\FeatureGate\Contracts\EvaluatesModuleAccess;
use App\Platform\FeatureGate\ModuleAccessRequest;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Closure;
use Illuminate\Http\Request;
use Modules\LBA\Ingest\IngestCredentialContext;
use Symfony\Component\HttpFoundation\Response;

/**
 * WS6.B — authenticate a scoped agent ingest request.
 *
 * Resolves the `Authorization: Bearer <secret>` header against the stored
 * SHA-256 hash and fails closed on the first failed condition:
 *
 *  - no / malformed bearer header;
 *  - unknown, revoked or expired credential;
 *  - the bound operator is missing or inactive;
 *  - the operator is no longer an active member of the credential's tenant;
 *  - the operator no longer holds `exam.update` in the bound tenant/branch;
 *  - the FeatureGate no longer allows the `lba` module on the Clinic panel.
 *
 * NOTHING is trusted from client input: the tenant, branch and examination scope
 * come SOLELY from the credential row. The verified tenant database is bound for
 * the whole request and released in `finally`, so tenant data can never leak into
 * the next request.
 *
 * A rejected attempt is recorded as a redacted central audit entry (no clinical
 * payload, no secret).
 */
final class AuthenticateLbaIngest
{
    public function __construct(
        private readonly IngestCredentialContext $context,
        private readonly ResolvesTenantDatabase $databases,
        private readonly AuthorizesTenantMembership $memberships,
        private readonly ChecksPermissions $permissions,
        private readonly EvaluatesModuleAccess $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $raw = $this->bearerToken($request);

        $credential = $raw === null
            ? null
            : LbaIngestCredential::query()->where('token', LbaIngestCredential::hash($raw))->first();

        if ($credential === null || ! $credential->isUsable()) {
            $this->deny($credential, $request, 'invalid credential');

            abort(401);
        }

        $user = User::query()->find($credential->user_id);

        if (! $user instanceof User || ! $user->is_active) {
            $this->deny($credential, $request, 'inactive operator');

            abort(401);
        }

        $tenantId = (string) $credential->tenant_id;
        $branchId = (string) $credential->branch_id;

        // Re-derive eligibility from central + tenant data on EVERY request: a
        // credential is only as good as the membership and module access behind
        // it.
        if (! $this->memberships->isMemberOf($user, $tenantId)) {
            $this->deny($credential, $request, 'membership revoked');

            abort(401);
        }

        try {
            $this->databases->configure($tenantId);

            $context = AccessContext::forTenant($tenantId, $branchId);

            $allowed = $this->gate->allows(new ModuleAccessRequest(
                moduleKey: 'lba',
                panelId: PanelAccessPolicy::PANEL_CLINIC,
                user: $user,
                context: $context,
            )) && $this->permissions->allows($user, PermissionKey::EXAM_UPDATE, $context);

            if (! $allowed) {
                $this->deny($credential, $request, 'module or permission revoked');

                abort(403);
            }

            $this->context->set($credential);

            $request->setUserResolver(static fn (): User => $user);

            return $next($request);
        } finally {
            $this->databases->forget();
        }
    }

    private function bearerToken(Request $request): ?string
    {
        $header = (string) $request->header('Authorization', '');

        if (! preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    private function deny(?LbaIngestCredential $credential, Request $request, string $reason): void
    {
        ModuleAuditLog::query()->create([
            'module_key' => 'lba',
            'action' => 'ingest_request',
            'result' => ModuleAuditLog::RESULT_DENIED,
            'reason' => $reason,
            'scope' => ModuleScope::TENANT,
            'scope_key' => $credential !== null ? (string) $credential->tenant_id : null,
            'actor_id' => $credential?->user_id,
            'metadata' => null,
        ]);
    }
}
