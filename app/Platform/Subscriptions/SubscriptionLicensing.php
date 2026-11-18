<?php

namespace App\Platform\Subscriptions;

use App\Models\Tenant;
use App\Models\User;
use App\Platform\FeatureGate\Providers\DatabaseModuleSubscriptions;
use App\Platform\ModuleRegistry\Models\ModuleEntitlement;
use App\Platform\ModuleRegistry\ModuleAdministration;
use App\Platform\ModuleRegistry\ModuleScope;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use App\Platform\Subscriptions\Models\TenantSubscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * WS4 — tenant licensing service (central).
 *
 * The single write path behind subscription plans and per-tenant licences. It
 * reuses the EXISTING {@see ModuleAdministration} to install and entitle each
 * licensed module (so the same registry, dependency checks and audit trail the
 * FeatureGate reads are honoured), then marks those entitlements as
 * subscription-backed with the subscription's opaque reference.
 *
 * Nothing here weakens the gate: a licensed module still needs an active
 * subscription, verified server side by {@see DatabaseModuleSubscriptions}.
 */
final class SubscriptionLicensing
{
    public function __construct(
        private readonly ModuleAdministration $administration,
    ) {}

    /**
     * Start a subscription for a tenant against a plan, licensing its modules.
     */
    public function subscribe(
        Tenant $tenant,
        SubscriptionPlan $plan,
        int $seats = 1,
        ?User $actor = null,
    ): TenantSubscription {
        $start = Carbon::now();
        $trialDays = (int) $plan->trial_days;
        $trialEndsAt = $trialDays > 0 ? $start->copy()->addDays($trialDays) : null;

        $subscription = TenantSubscription::query()->create([
            'tenant_id' => $tenant->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => $trialEndsAt !== null
                ? TenantSubscription::STATUS_TRIALING
                : TenantSubscription::STATUS_ACTIVE,
            'seats' => max(1, $seats),
            'starts_at' => $start,
            'trial_ends_at' => $trialEndsAt,
            'current_period_start' => $start,
            'current_period_end' => $this->periodEnd($start, $plan->billing_interval),
            'metadata' => ['licensed_at' => $start->toIso8601String()],
        ]);

        $this->licenseModules($subscription, $actor);

        return $subscription;
    }

    /**
     * (Re)activate a subscription and start a fresh billing period.
     */
    public function activate(TenantSubscription $subscription, ?User $actor = null): void
    {
        $plan = $subscription->plan;
        $interval = $plan instanceof SubscriptionPlan ? $plan->billing_interval : SubscriptionPlan::INTERVAL_MONTHLY;

        $subscription->update([
            'status' => TenantSubscription::STATUS_ACTIVE,
            'current_period_start' => Carbon::now(),
            'current_period_end' => $this->periodEnd(Carbon::now(), $interval),
            'cancelled_at' => null,
            'ended_at' => null,
        ]);

        $this->licenseModules($subscription->refresh(), $actor);
    }

    /**
     * Cancel a subscription. The gate fails closed immediately because the
     * subscription is no longer active — no entitlement is destroyed.
     */
    public function cancel(TenantSubscription $subscription): void
    {
        $subscription->update([
            'status' => TenantSubscription::STATUS_CANCELLED,
            'cancelled_at' => Carbon::now(),
            'ended_at' => Carbon::now(),
        ]);
    }

    /**
     * Install + entitle every module the licensed plan covers, marking each
     * entitlement subscription-backed. Modules whose dependency graph is
     * unsatisfied are skipped (and audited) by {@see ModuleAdministration}.
     */
    public function licenseModules(TenantSubscription $subscription, ?User $actor = null): void
    {
        $plan = $subscription->plan;

        if (! $plan instanceof SubscriptionPlan) {
            return;
        }

        foreach ($plan->moduleKeys() as $moduleKey) {
            // The wildcard licenses every installed module; it enumerates no
            // installation of its own, and the verifier already honours it.
            if ($moduleKey === SubscriptionPlan::MODULE_WILDCARD) {
                continue;
            }

            if (! $this->administration->enable($moduleKey, (string) $subscription->tenant_id, $actor)) {
                continue;
            }

            ModuleEntitlement::query()
                ->where('module_key', $moduleKey)
                ->where('scope', ModuleScope::TENANT)
                ->where('scope_key', $subscription->tenant_id)
                ->update([
                    'subscription_required' => true,
                    'subscription_reference' => $subscription->reference,
                ]);
        }
    }

    private function periodEnd(CarbonInterface $start, string $interval): CarbonInterface
    {
        return match ($interval) {
            SubscriptionPlan::INTERVAL_YEARLY => $start->copy()->addYear(),
            default => $start->copy()->addMonth(),
        };
    }
}
