<?php

namespace Modules\Consultations\Services;

use App\Platform\AiBilling\AiWalletService;
use Illuminate\Support\Facades\DB;
use Modules\Consultations\Ai\Exceptions\SinaException;
use Modules\Consultations\Models\SinaUsageRecord;
use Modules\Consultations\Models\SinaWallet;
use Modules\Consultations\Models\SinaWalletLedgerEntry;

/**
 * Workstream 2 — the SINA consultation credit wallet engine.
 *
 * Mirrors the central AI billing discipline ({@see AiWalletService}):
 * all accounting runs in the CENTRAL database inside ONE transaction with a
 * row-level lock, so a concurrent reservation can never overspend. Every
 * movement writes ONE immutable ledger entry whose `idempotency_key` is UNIQUE,
 * so a duplicated callback or retry can never double-charge or double-refund.
 *
 * This engine is deliberately separate from the tenant AI top-up wallet: the
 * two never share credits.
 */
final class SinaWalletService
{
    private function connection(): string
    {
        return (string) config('tenancy.central_connection');
    }

    public function walletFor(string $tenantId, string $patientId): ?SinaWallet
    {
        return SinaWallet::query()
            ->where('tenant_id', $tenantId)
            ->where('patient_id', $patientId)
            ->first();
    }

    /**
     * The patient's available balance (0 when they have no wallet yet).
     */
    public function balanceFor(string $tenantId, string $patientId): int
    {
        return (int) ($this->walletFor($tenantId, $patientId)?->balance_credits ?? 0);
    }

    public function ensureWallet(string $tenantId, string $patientId, ?string $currency = null): SinaWallet
    {
        return SinaWallet::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'patient_id' => $patientId],
            [
                'balance_credits' => 0,
                'reserved_credits' => 0,
                'lifetime_consumed_credits' => 0,
                'status' => SinaWallet::STATUS_ACTIVE,
                'currency' => $currency ?? (string) config('lba.sina.billing.currency', 'sina_credit'),
            ],
        );
    }

    /**
     * Grant credits (a purchase or a controlled top-up), idempotently.
     */
    public function grant(
        string $tenantId,
        string $patientId,
        int $units,
        string $reason,
        string $idempotencyKey,
        ?string $actorReference = null,
    ): SinaWalletLedgerEntry {
        if ($units <= 0) {
            throw SinaException::invalidAmount($units);
        }

        $this->ensureWallet($tenantId, $patientId);

        return $this->post(
            tenantId: $tenantId,
            patientId: $patientId,
            action: SinaWalletLedgerEntry::ACTION_GRANT,
            idempotencyKey: $idempotencyKey,
            consultationId: null,
            actorReference: $actorReference,
            reason: $reason,
            mutate: function (SinaWallet $wallet) use ($units): void {
                $wallet->balance_credits = (int) $wallet->balance_credits + $units;
            },
            units: $units,
        );
    }

    /**
     * Move credits balance→reserved ONCE. Never overspends.
     */
    public function reserve(
        string $tenantId,
        string $patientId,
        string $consultationId,
        int $units,
        string $idempotencyKey,
        ?string $actorReference = null,
    ): SinaWalletLedgerEntry {
        if ($units <= 0) {
            throw SinaException::invalidAmount($units);
        }

        return $this->post(
            tenantId: $tenantId,
            patientId: $patientId,
            action: SinaWalletLedgerEntry::ACTION_RESERVE,
            idempotencyKey: $idempotencyKey,
            consultationId: $consultationId,
            actorReference: $actorReference,
            reason: null,
            mutate: function (SinaWallet $wallet) use ($units): void {
                if ((int) $wallet->balance_credits < $units) {
                    throw SinaException::insufficientCredits($units, (int) $wallet->balance_credits);
                }

                $wallet->balance_credits = (int) $wallet->balance_credits - $units;
                $wallet->reserved_credits = (int) $wallet->reserved_credits + $units;
            },
            units: $units,
        );
    }

    /**
     * Capture a reservation ONCE and record one immutable usage entry.
     *
     * @param  array{provider?: string, model_name?: ?string, prompt_tokens?: int, completion_tokens?: int}  $usage
     */
    public function capture(
        string $tenantId,
        string $patientId,
        string $consultationId,
        int $units,
        string $idempotencyKey,
        array $usage = [],
        ?string $actorReference = null,
    ): SinaWalletLedgerEntry {
        if ($units <= 0) {
            throw SinaException::invalidAmount($units);
        }

        return $this->post(
            tenantId: $tenantId,
            patientId: $patientId,
            action: SinaWalletLedgerEntry::ACTION_CAPTURE,
            idempotencyKey: $idempotencyKey,
            consultationId: $consultationId,
            actorReference: $actorReference,
            reason: null,
            mutate: function (SinaWallet $wallet) use ($units): void {
                $wallet->reserved_credits = max(0, (int) $wallet->reserved_credits - $units);
                $wallet->lifetime_consumed_credits = (int) $wallet->lifetime_consumed_credits + $units;
            },
            units: $units,
            after: function (SinaWallet $wallet) use ($tenantId, $patientId, $consultationId, $units, $idempotencyKey, $usage): void {
                SinaUsageRecord::query()->firstOrCreate(
                    ['idempotency_key' => $idempotencyKey],
                    [
                        'tenant_id' => $tenantId,
                        'wallet_id' => $wallet->getKey(),
                        'patient_id' => $patientId,
                        'consultation_id' => $consultationId,
                        'provider' => (string) ($usage['provider'] ?? 'unknown'),
                        'model_name' => $usage['model_name'] ?? null,
                        'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                        'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                        'credit_units' => $units,
                        'recorded_at' => now(),
                    ],
                );
            },
        );
    }

    /**
     * Release a reservation ONCE, restoring reserved→balance.
     */
    public function release(
        string $tenantId,
        string $patientId,
        string $consultationId,
        int $units,
        string $idempotencyKey,
        ?string $actorReference = null,
    ): SinaWalletLedgerEntry {
        if ($units <= 0) {
            throw SinaException::invalidAmount($units);
        }

        return $this->post(
            tenantId: $tenantId,
            patientId: $patientId,
            action: SinaWalletLedgerEntry::ACTION_RELEASE,
            idempotencyKey: $idempotencyKey,
            consultationId: $consultationId,
            actorReference: $actorReference,
            reason: null,
            mutate: function (SinaWallet $wallet) use ($units): void {
                $refund = min($units, (int) $wallet->reserved_credits);
                $wallet->reserved_credits = (int) $wallet->reserved_credits - $refund;
                $wallet->balance_credits = (int) $wallet->balance_credits + $refund;
            },
            units: $units,
        );
    }

    public function hasLedgerEntry(string $idempotencyKey): bool
    {
        return SinaWalletLedgerEntry::query()->where('idempotency_key', $idempotencyKey)->exists();
    }

    /**
     * @param  callable(SinaWallet): void  $mutate
     * @param  callable(SinaWallet): void|null  $after
     */
    private function post(
        string $tenantId,
        string $patientId,
        string $action,
        string $idempotencyKey,
        ?string $consultationId,
        ?string $actorReference,
        ?string $reason,
        callable $mutate,
        int $units,
        ?callable $after = null,
    ): SinaWalletLedgerEntry {
        return DB::connection($this->connection())->transaction(function () use (
            $tenantId,
            $patientId,
            $action,
            $idempotencyKey,
            $consultationId,
            $actorReference,
            $reason,
            $mutate,
            $units,
            $after,
        ): SinaWalletLedgerEntry {
            // Idempotency: the unique key makes a repeat a no-op.
            $existing = SinaWalletLedgerEntry::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $wallet = SinaWallet::query()
                ->where('tenant_id', $tenantId)
                ->where('patient_id', $patientId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                throw SinaException::walletMissing($tenantId, $patientId);
            }

            if (! $wallet->isActive()) {
                throw SinaException::walletMissing($tenantId, $patientId);
            }

            $mutate($wallet);
            $wallet->save();

            $entry = SinaWalletLedgerEntry::query()->create([
                'tenant_id' => $tenantId,
                'wallet_id' => $wallet->getKey(),
                'patient_id' => $patientId,
                'action' => $action,
                'credit_units' => $units,
                'balance_after' => (int) $wallet->balance_credits,
                'reserved_after' => (int) $wallet->reserved_credits,
                'consultation_id' => $consultationId,
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
