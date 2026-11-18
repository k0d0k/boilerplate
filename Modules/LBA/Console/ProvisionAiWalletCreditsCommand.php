<?php

namespace Modules\LBA\Console;

use App\Platform\AiBilling\AiWalletService;
use App\Platform\AiBilling\Exceptions\AiBillingException;
use App\Platform\Tenancy\Console\Concerns\FindsTenant;
use Illuminate\Console\Command;

/**
 * Phase 6 — provision synthetic AI wallet credits for LOCAL/TESTING only.
 *
 * This is the ONLY way to create credits outside a future payment integration
 * (which is deliberately NOT part of Phase 6). It never runs in production and
 * never seeds balances automatically: an operator must name one tenant, a
 * positive integer amount, a reason, an idempotency key and (optionally) an
 * actor. Every grant writes ONE immutable, audited ledger entry, and re-running
 * the same key is a no-op.
 */
class ProvisionAiWalletCreditsCommand extends Command
{
    use FindsTenant;

    protected $signature = 'lba:ai-wallet:provision
        {tenant : Tenant ULID or slug}
        {amount : Positive integer credit units}
        {--reason= : Why these credits are being provisioned (required)}
        {--key= : Idempotency key (required)}
        {--actor= : Optional actor reference for the audit record}';

    protected $description = 'Provision synthetic AI wallet credits (local/testing only)';

    public function handle(AiWalletService $wallets): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Refusing to provision AI credits outside local/testing environments.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));
        $key = trim((string) $this->option('key'));
        $rawAmount = (string) $this->argument('amount');

        if ($reason === '') {
            $this->error('A --reason is required for the audit record.');

            return self::FAILURE;
        }

        if ($key === '') {
            $this->error('An explicit --key idempotency key is required.');

            return self::FAILURE;
        }

        if (! ctype_digit($rawAmount) || (int) $rawAmount <= 0) {
            $this->error('The amount must be a positive integer number of credits.');

            return self::FAILURE;
        }

        $tenant = $this->findTenant($this, (string) $this->argument('tenant'));

        if ($tenant === null) {
            return self::FAILURE;
        }

        $tenantId = (string) $tenant->getKey();
        $amount = (int) $rawAmount;
        $actor = $this->option('actor');
        $actorReference = is_string($actor) && $actor !== '' ? $actor : 'cli:ai-wallet:provision';

        $idempotencyKey = 'lba-ai:grant:'.$key;

        try {
            $wallets->grant($tenantId, $amount, $reason, $idempotencyKey, $actorReference);
        } catch (AiBillingException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $wallet = $wallets->walletFor($tenantId);

        $this->info("AI wallet for tenant [{$tenantId}] provisioned.");
        $this->line('  available_credits: '.($wallet?->available_credits ?? 0));
        $this->line('  reserved_credits:  '.($wallet?->reserved_credits ?? 0));
        $this->line('  idempotency_key:   '.$idempotencyKey);

        return self::SUCCESS;
    }
}
