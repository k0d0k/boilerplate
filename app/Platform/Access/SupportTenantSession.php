<?php

namespace App\Platform\Access;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\ModuleRegistry\Models\ModuleAuditLog;
use App\Platform\ModuleRegistry\ModuleScope;
use Carbon\CarbonImmutable;
use Illuminate\Session\Store;

/**
 * WS9.E — bounded, auditable "open tenant" support context.
 *
 * A platform Super Admin opens a tenant to review its OPERATIONAL posture
 * WITHOUT ever impersonating a tenant identity: they keep their own principal
 * identity and are granted a single, time-boxed, read-only support context.
 *
 * Guarantees enforced on every read (never by UI visibility):
 *  - the context is bound to the actor who opened it — another identity sharing
 *    the session can never reuse it;
 *  - it EXPIRES after {@see DEFAULT_TTL_MINUTES} and is dropped the moment it
 *    lapses;
 *  - it is revoked immediately when the actor loses
 *    `platform.tenant.manage` (permission revocation);
 *  - it is scoped to exactly ONE tenant that must still exist and be active —
 *    cross-tenant access is impossible;
 *  - it is NOT a tenant membership and grants NO clinical permission; the
 *    operational view it unlocks is composed from central registry data only,
 *    so no medical record, LBA image or private tenant document is reachable.
 *
 * Opening and closing are appended to the central, redacted audit trail.
 */
final class SupportTenantSession
{
    /** Session key holding the bounded support context state. */
    public const SESSION_KEY = 'lba.support_tenant_context';

    public const MODULE_KEY = 'platform.tenancy';

    public const ACTION_OPENED = 'support_tenant_opened';

    public const ACTION_CLOSED = 'support_tenant_closed';

    public const DEFAULT_TTL_MINUTES = 60;

    public function __construct(private readonly AuthorizesPrincipal $principal) {}

    /**
     * Open a bounded support context for one ACTIVE tenant.
     *
     * Fails closed (and audits the denial) when the actor does not hold
     * `platform.tenant.manage`, or the tenant is unknown or not active.
     */
    public function open(User $actor, string $tenantId, ?int $ttlMinutes = null): bool
    {
        $ttl = $ttlMinutes ?? self::DEFAULT_TTL_MINUTES;

        if (! $actor->is_active || ! $this->principal->can($actor, PermissionKey::PLATFORM_TENANT_MANAGE)) {
            $this->audit($actor, self::ACTION_OPENED, ModuleAuditLog::RESULT_DENIED, 'permission_denied', $tenantId);

            return false;
        }

        $tenant = Tenant::query()
            ->whereKey($tenantId)
            ->where('status', Tenant::STATUS_ACTIVE)
            ->first();

        if ($tenant === null) {
            $this->audit($actor, self::ACTION_OPENED, ModuleAuditLog::RESULT_DENIED, 'tenant_not_active', $tenantId);

            return false;
        }

        $now = CarbonImmutable::now();

        // Exactly one tenant: opening another replaces any previous context.
        $this->session()->put(self::SESSION_KEY, [
            'tenant_id' => (string) $tenant->getKey(),
            'actor_id' => (string) $actor->getKey(),
            'opened_at' => $now->toIso8601String(),
            'expires_at' => $now->addMinutes($ttl)->toIso8601String(),
        ]);

        $this->audit($actor, self::ACTION_OPENED, ModuleAuditLog::RESULT_ALLOWED, null, (string) $tenant->getKey());

        return true;
    }

    public function isActive(?User $actor): bool
    {
        return $this->currentTenantId($actor) !== null;
    }

    /**
     * The verified support tenant id, or null. Revalidates actor binding,
     * permission, expiry and tenant status on every call; a context that is no
     * longer usable is forgotten so it can never be reused.
     */
    public function currentTenantId(?User $actor): ?string
    {
        $state = $this->state();

        if ($state === null) {
            return null;
        }

        if (! $this->isUsable($actor, $state)) {
            $this->forget();

            return null;
        }

        return $state['tenant_id'];
    }

    public function currentTenant(?User $actor): ?Tenant
    {
        $tenantId = $this->currentTenantId($actor);

        return $tenantId === null
            ? null
            : Tenant::query()->with('database')->find($tenantId);
    }

    public function expiresAt(?User $actor): ?CarbonImmutable
    {
        $state = $this->state();

        if ($state === null || ! $this->isUsable($actor, $state)) {
            return null;
        }

        return CarbonImmutable::parse($state['expires_at']);
    }

    /**
     * Close the support context and return to the Principal. Audited when the
     * acting identity is the one that opened it.
     */
    public function close(?User $actor): void
    {
        $state = $this->state();

        $this->forget();

        if ($state !== null && $actor !== null && (string) $actor->getKey() === $state['actor_id']) {
            $this->audit($actor, self::ACTION_CLOSED, ModuleAuditLog::RESULT_ALLOWED, null, $state['tenant_id']);
        }
    }

    /**
     * @param  array{tenant_id: string, actor_id: string, opened_at: string, expires_at: string}  $state
     */
    private function isUsable(?User $actor, array $state): bool
    {
        if ($actor === null || ! $actor->is_active) {
            return false;
        }

        // Actor binding: only the identity that opened it may use it.
        if ((string) $actor->getKey() !== $state['actor_id']) {
            return false;
        }

        // Permission revocation ends the support context immediately.
        if (! $this->principal->can($actor, PermissionKey::PLATFORM_TENANT_MANAGE)) {
            return false;
        }

        // Time box.
        if (CarbonImmutable::now()->greaterThanOrEqualTo(CarbonImmutable::parse($state['expires_at']))) {
            return false;
        }

        // The tenant must still exist and be active (no cross-tenant reuse).
        return Tenant::query()
            ->whereKey($state['tenant_id'])
            ->where('status', Tenant::STATUS_ACTIVE)
            ->exists();
    }

    /**
     * @return array{tenant_id: string, actor_id: string, opened_at: string, expires_at: string}|null
     */
    private function state(): ?array
    {
        $value = $this->session()->get(self::SESSION_KEY);

        if (! is_array($value)) {
            return null;
        }

        $state = [];

        foreach (['tenant_id', 'actor_id', 'opened_at', 'expires_at'] as $key) {
            if (! isset($value[$key]) || ! is_string($value[$key]) || $value[$key] === '') {
                return null;
            }

            $state[$key] = $value[$key];
        }

        return $state;
    }

    private function forget(): void
    {
        $this->session()->forget(self::SESSION_KEY);
    }

    private function session(): Store
    {
        return app('session')->driver();
    }

    private function audit(User $actor, string $action, string $result, ?string $reason, ?string $tenantId): void
    {
        ModuleAuditLog::query()->create([
            'module_key' => self::MODULE_KEY,
            'action' => $action,
            'result' => $result,
            'reason' => $reason,
            'scope' => ModuleScope::PLATFORM,
            'scope_key' => $tenantId,
            'actor_id' => $actor->getKey(),
            'metadata' => [],
        ]);
    }
}
