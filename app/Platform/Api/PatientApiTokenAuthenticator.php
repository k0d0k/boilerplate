<?php

namespace App\Platform\Api;

use App\Models\PatientApiToken;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesTenantMembership;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Phase 8B — bearer-token authentication for the patient REST API.
 *
 * Resolves the `Authorization: Bearer <secret>` header against the stored
 * SHA-256 hash and fails closed on the first failed condition:
 *
 *  - no / malformed bearer header;
 *  - unknown token hash, revoked token or expired token;
 *  - the bound account is missing or suspended;
 *  - the token's clinic is no longer an ACTIVE membership of that account (a
 *    revoked membership, or a suspended/deleted tenant, invalidates the token
 *    immediately — the same eligibility rule the session context enforces).
 *
 * Nothing is trusted from client input: the clinic scope comes solely from the
 * token row. The resolved token is recorded in the scoped {@see PatientApiContext}
 * for the tenant-binding middleware.
 */
final class PatientApiTokenAuthenticator
{
    public function __construct(
        private readonly PatientApiContext $context,
        private readonly AuthorizesTenantMembership $memberships,
    ) {}

    public function retrieve(Request $request): ?Authenticatable
    {
        $raw = $this->bearerToken($request);

        if ($raw === null) {
            return null;
        }

        $token = PatientApiToken::query()
            ->where('token', PatientApiToken::hash($raw))
            ->first();

        if ($token === null || ! $token->isUsable()) {
            return null;
        }

        $user = User::query()->find($token->user_id);

        if (! $user instanceof User || ! $user->is_active) {
            return null;
        }

        // Re-derive eligibility from central data on EVERY request: a token is
        // only as good as the membership behind it.
        if (! $this->memberships->isMemberOf($user, (string) $token->tenant_id)) {
            return null;
        }

        $this->context->set($token);

        PatientApiToken::query()
            ->whereKey($token->getKey())
            ->update(['last_used_at' => now()]);

        return $user;
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
}
