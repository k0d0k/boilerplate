<?php

namespace App\Platform\AiBilling;

use App\Platform\AiBilling\Exceptions\AiBillingException;
use App\Platform\AiBilling\Models\AiUsageRecord;
use App\Platform\AiBilling\Models\TenantAiWallet;
use App\Platform\AiBilling\Models\TenantAiWalletLedgerEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 — the central AI credit wallet engine.
 *
 * All accounting happens in the CENTRAL database inside a single transaction
 * with a row-level lock on the wallet, so a concurrent reservation can never
 * overspend. Every movement writes ONE immutable ledger entry whose
 * `idempotency_key` is UNIQUE — re-running a reserve/capture/release for the
 * same job/action is a no-op, so a duplicated callback or retry can never
 * double-charge or double-refund.
 *
 * Credits are INTEGER units only; no floating-point arithmetic is ever used.
 * Nothing here reads or writes tenant clinical data — only the opaque job
 * reference and integer accounting.
 */
final class AiWalletService
{
    private function connection(): string
    {
        return (string) config('tenancy.central_connection');
    }

    public function walletFor(string $tenantId): ?TenantAiWallet
    {
        return TenantAiWallet::query()->where('tenant_id', $tenantId)->first();
    }

    /**
     * Return the tenant's wallet, creating an empty one when absent.
     */
    public function ensureWallet(string $tenantId, string $currency = 'ai_credit'): TenantAiWallet
    {
        return TenantAiWallet::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'available_credits' => 0,
                'reserved_credits' => 0,
                'lifetime_consumed_credits' => 0,
                'status' => TenantAiWallet::STATUS_ACTIVE,
                'currency' => $currency,
            ],
        );
    }

    /**
     * Grant credits (a top-up or a controlled test balance), idempotently.
     *
     * @throws AiBillingException
     */
    public function grant(
        string $tenantId,
        int $units,
        string $reason,
        string $idempotencyKey,
        ?string $actorReference = null,
    ): TenantAiWalletLedgerEntry {
        if ($units <= 0) {
            throw AiBillingException::invalidAmount($units);
        }

        // A grant is the only operation allowed to bring a wallet into
        // existence, so a first top-up never fails with "wallet missing".
        $this->ensureWallet($tenantId);

        return $this->post(
            $tenantId,
            action: TenantAiWalletLedgerEntry::ACTION_GRANT,
            idempotencyKey: $idempotencyKey,
            jobReference: null,
            actorReference: $actorReference,
            reason: $reason,
            mutate: function (TenantAiWallet $wallet) use ($units): void {
                $wallet->available_credits = (int) $wallet->available_credits + $units;
            },
            units: $units,
        );
    }

    /**
     * Move credits available→reserved ONCE. Never overspends.
     *
     * @throws AiBillingException
     */
    public function reserve(
        string $tenantId,
        string $jobReference,
        int $units,
        string $idempotencyKey,
        ?string $actorReference = null,
    ): TenantAiWalletLedgerEntry {
        if ($units <= 0) {
            throw AiBillingException::invalidAmount($units);
        }

        return $this->post(
            $tenantId,
            action: TenantAiWalletLedgerEntry::ACTION_RESERVE,
            idempotencyKey: $idempotencyKey,
            jobReference: $jobReference,
            actorReference: $actorReference,
            reason: null,
            mutate: function (TenantAiWallet $wallet) use ($units): void {
                if ((int) $wallet->available_credits < $units) {
                    throw AiBillingException::insufficientCredits($units, (int) $wallet->available_credits);
                }

                $wallet->available_credits = (int) $wallet->available_credits - $units;
                $wallet->reserved_credits = (int) $wallet->reserved_credits + $units;
            },
            units: $units,
        );
    }

    /**
     * Capture a reservation ONCE and record one immutable usage entry.
     *
     * @param  array{provider?: string, model_name?: ?string, model_version?: ?string, pricing_rule_ref?: ?string, unit_price_credits?: int}  $usage
     *
     * @throws AiBillingException
     */
    public function capture(
        string $tenantId,
        string $jobReference,
        int $units,
        string $idempotencyKey,
        array $usage = [],
        ?string $actorReference = null,
    ): TenantAiWalletLedgerEntry {
        if ($units <= 0) {
            throw AiBillingException::invalidAmount($units);
        }

        return $this->post(
            $tenantId,
            action: TenantAiWalletLedgerEntry::ACTION_CAPTURE,
            idempotencyKey: $idempotencyKey,
            jobReference: $jobReference,
            actorReference: $actorReference,
            reason: null,
            mutate: function (TenantAiWallet $wallet) use ($units): void {
                $wallet->reserved_credits = max(0, (int) $wallet->reserved_credits - $units);
                $wallet->lifetime_consumed_credits = (int) $wallet->lifetime_consumed_credits + $units;
            },
            units: $units,
            after: function (TenantAiWallet $wallet) use ($tenantId, $jobReference, $units, $idempotencyKey, $usage): void {
                AiUsageRecord::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'tenant_id' => $tenantId,
                        'wallet_id' => $wallet->getKey(),
                        'job_reference' => $jobReference,
                        'provider' => (string) ($usage['provider'] ?? 'unknown'),
                        'model_name' => $usage['model_name'] ?? null,
                        'model_version' => $usage['model_version'] ?? null,
                        'pricing_rule_ref' => $usage['pricing_rule_ref'] ?? null,
                        'unit_price_credits' => (int) ($usage['unit_price_credits'] ?? $units),
                        'credit_units' => $units,
                        'recorded_at' => now(),
                    ],
                );
            },
        );
    }

    /**
     * Release a reservation ONCE, restoring reserved→available.
     *
     * @throws AiBillingException
     */
    public function release(
        string $tenantId,
        string $jobReference,
        int $units,
        string $idempotencyKey,
        ?string $actorReference = null,
    ): TenantAiWalletLedgerEntry {
        if ($units <= 0) {
            throw AiBillingException::invalidAmount($units);
        }

        return $this->post(
            $tenantId,
            action: TenantAiWalletLedgerEntry::ACTION_RELEASE,
            idempotencyKey: $idempotencyKey,
            jobReference: $jobReference,
            actorReference: $actorReference,
            reason: null,
            mutate: function (TenantAiWallet $wallet) use ($units): void {
                $refund = min($units, (int) $wallet->reserved_credits);
                $wallet->reserved_credits = (int) $wallet->reserved_credits - $refund;
                $wallet->available_credits = (int) $wallet->available_credits + $refund;
            },
            units: $units,
        );
    }

    /**
     * All ledger entries for one job reference in a tenant (reconciliation).
     *
     * @return Collection<int, TenantAiWalletLedgerEntry>
     */
    public function ledgerEntriesForJob(string $tenantId, string $jobReference): Collection
    {
        return TenantAiWalletLedgerEntry::query()
            ->where('tenant_id', $tenantId)
            ->where('job_reference', $jobReference)
            ->get();
    }

    public function hasLedgerEntry(string $idempotencyKey): bool
    {
        return TenantAiWalletLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    /**
     * @param  callable(TenantAiWallet): void  $mutate
     * @param  callable(TenantAiWallet): void|null  $after
     *
     * @throws AiBillingException
     */
    private function post(
        string $tenantId,
        string $action,
        string $idempotencyKey,
        ?string $jobReference,
        ?string $actorReference,
        ?string $reason,
        callable $mutate,
        int $units,
        ?callable $after = null,
    ): TenantAiWalletLedgerEntry {
        return DB::connection($this->connection())->transaction(function () use (
            $tenantId,
            $action,
            $idempotencyKey,
            $jobReference,
            $actorReference,
            $reason,
            $mutate,
            $units,
            $after,
        ): TenantAiWalletLedgerEntry {
            // Idempotency: the unique key makes a repeat a no-op.
            $existing = TenantAiWalletLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $wallet = TenantAiWallet::query()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw AiBillingException::walletMissing($tenantId);
            }

            if (! $wallet->isActive()) {
                throw AiBillingException::walletMissing($tenantId);
            }

            $mutate($wallet);
            $wallet->save();

            $entry = TenantAiWalletLedgerEntry::query()->create([
                'tenant_id' => $tenantId,
                'wallet_id' => $wallet->getKey(),
                'action' => $action,
                'credit_units' => $units,
                'available_after' => (int) $wallet->available_credits,
                'reserved_after' => (int) $wallet->reserved_credits,
                'job_reference' => $jobReference,
                'idempotency_key' => $idempotencyKey,
                'actor_reference' => $actorReference,
                'reason' => $reason,
            ]);

            if ($after !== null) {
                $after($wallet);
            }

            return $entry;
        });
    }
}
