<?php

namespace App\Platform\Api;

use App\Models\PatientApiToken;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Phase 8B — patient API token issuance and revocation.
 *
 * A raw secret is generated cryptographically and returned to the caller ONCE;
 * only its SHA-256 hash is persisted. Each token is bound to exactly one clinic
 * (tenant), so the isolation boundary is the token itself.
 */
final class PatientApiTokenIssuer
{
    public function __construct(private readonly PatientApiContext $context) {}

    /**
     * Issue a token for one account scoped to one clinic.
     *
     * @param  list<string>  $abilities
     * @return array{token: PatientApiToken, plain: string}
     */
    public function issue(User $user, string $tenantId, string $name = 'mobile', array $abilities = []): array
    {
        $plain = Str::random(64);

        $token = PatientApiToken::query()->create([
            'user_id' => $user->getKey(),
            'tenant_id' => $tenantId,
            'name' => $name,
            'token' => PatientApiToken::hash($plain),
            'abilities' => $abilities,
            'expires_at' => now()->addDays((int) config('lba.patient_api.token_ttl_days', 30)),
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    /**
     * Revoke the token behind the current request (logout).
     */
    public function revokeCurrent(): bool
    {
        $token = $this->context->token();

        if ($token === null) {
            return false;
        }

        $token->forceFill(['revoked_at' => now()])->save();

        return true;
    }
}
