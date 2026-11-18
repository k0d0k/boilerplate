<?php

namespace Modules\LBA\Console;

use App\Models\Tenant;
use App\Platform\Tenancy\Console\Concerns\FindsTenant;
use Illuminate\Console\Command;
use Modules\LBA\AI\Billing\AiCreditReconciler;

/**
 * Phase 6 — reconcile AI credit reservations against the central ledger.
 *
 * Settles stranded reservations and unsettled completed jobs per tenant. The
 * central ledger is the source of truth, every operation is idempotent, and the
 * command is safe to run repeatedly. `--dry-run` reports without changing
 * anything.
 */
class ReconcileAiCreditsCommand extends Command
{
    use FindsTenant;

    protected $signature = 'lba:ai-credits:reconcile
        {--tenant= : Limit reconciliation to one tenant ULID or slug}
        {--dry-run : Report without changing anything}';

    protected $description = 'Reconcile stranded AI credit reservations and unsettled jobs';

    public function handle(AiCreditReconciler $reconciler): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantArgument = (string) ($this->option('tenant') ?? '');

        if ($tenantArgument !== '') {
            $tenant = $this->findTenant($this, $tenantArgument);

            if ($tenant === null) {
                return self::FAILURE;
            }

            $this->report($reconciler, (string) $tenant->getKey(), $dryRun);

            return self::SUCCESS;
        }

        $tenants = Tenant::query()->pluck('id');

        if ($tenants->isEmpty()) {
            $this->warn('No tenants found.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenantId) {
            $this->report($reconciler, (string) $tenantId, $dryRun);
        }

        return self::SUCCESS;
    }

    private function report(AiCreditReconciler $reconciler, string $tenantId, bool $dryRun): void
    {
        $summary = $reconciler->reconcileTenant($tenantId, $dryRun);

        $prefix = $dryRun ? '[dry-run] ' : '';

        $this->line(sprintf(
            '%stenant [%s]: captured=%d released=%d repaired=%d orphans=%d',
            $prefix,
            $tenantId,
            $summary['captured'],
            $summary['released'],
            $summary['repaired'],
            $summary['orphans'],
        ));
    }
}
