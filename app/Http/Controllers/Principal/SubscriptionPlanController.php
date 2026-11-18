<?php

namespace App\Http\Controllers\Principal;

use App\Filament\Principal\Concerns\AuthorizesPrincipalResources;
use App\Filament\Principal\Resources\SubscriptionPlanResource;
use App\Http\Controllers\Controller;
use App\Platform\Access\PermissionKey;
use App\Platform\ModuleRegistry\Models\RegisteredModule;
use App\Platform\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Commerce\Support\Currency;

/**
 * Phase 5 — Principal "Paket Langganan" (subscription plan catalogue) TailAdmin
 * surface.
 *
 * Mirrors the interim Filament {@see SubscriptionPlanResource}
 * but renders inside the TailAdmin shell. A plan is a COMMERCIAL licence
 * template only — price, billing interval, trial length, seat limit and the
 * installed modules it licenses. It is never a tenant and holds no clinical
 * data. Plans are ARCHIVED, never deleted, so a historical licence keeps
 * resolving its plan identity.
 *
 * Authorization is re-derived on the SERVER for every read and every write:
 * viewing requires `platform.plan.manage` OR `platform.subscription.view`;
 * changing a plan requires `platform.plan.manage`.
 */
final class SubscriptionPlanController extends Controller
{
    use AuthorizesPrincipalResources;

    /** @var list<string> */
    private const STATUSES = [SubscriptionPlan::STATUS_ACTIVE, SubscriptionPlan::STATUS_ARCHIVED];

    public function index(Request $request): View
    {
        $this->authorizeView();

        $status = $request->string('status')->toString();
        $search = $request->string('search')->toString();

        $plans = SubscriptionPlan::query()
            ->withCount('subscriptions')
            ->when(in_array($status, self::STATUSES, true), static fn ($query) => $query->where('status', $status))
            ->when($search !== '', static function ($query) use ($search): void {
                $query->where(static function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->get();

        return view('principal.subscriptions.index', [
            'plans' => $plans,
            'status' => $status,
            'search' => $search,
            'canManage' => self::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE),
        ]);
    }

    public function create(): View
    {
        $this->authorizeManage();

        return view('principal.subscriptions.form', [
            'plan' => new SubscriptionPlan([
                'status' => SubscriptionPlan::STATUS_ACTIVE,
                'currency' => Currency::IDR,
                'billing_interval' => SubscriptionPlan::INTERVAL_MONTHLY,
                'trial_days' => 0,
            ]),
            'moduleOptions' => $this->moduleOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManage();

        SubscriptionPlan::query()->create($this->validated($request));

        return to_route('principal.tailadmin.subscriptions.index')
            ->with('success', __('lba.subscriptions.saved'));
    }

    public function edit(SubscriptionPlan $plan): View
    {
        $this->authorizeManage();

        return view('principal.subscriptions.form', [
            'plan' => $plan,
            'moduleOptions' => $this->moduleOptions(),
        ]);
    }

    public function update(Request $request, SubscriptionPlan $plan): RedirectResponse
    {
        $this->authorizeManage();

        $plan->update($this->validated($request, $plan));

        return to_route('principal.tailadmin.subscriptions.index')
            ->with('success', __('lba.subscriptions.saved'));
    }

    public function archive(SubscriptionPlan $plan): RedirectResponse
    {
        $this->authorizeManage();

        $plan->update(['status' => SubscriptionPlan::STATUS_ARCHIVED]);

        return to_route('principal.tailadmin.subscriptions.index')
            ->with('success', __('lba.subscriptions.archived'));
    }

    public function reactivate(SubscriptionPlan $plan): RedirectResponse
    {
        $this->authorizeManage();

        $plan->update(['status' => SubscriptionPlan::STATUS_ACTIVE]);

        return to_route('principal.tailadmin.subscriptions.index')
            ->with('success', __('lba.subscriptions.reactivated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SubscriptionPlan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('subscription_plans', 'code')->ignore($plan?->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(self::STATUSES)],
            'description' => ['nullable', 'string', 'max:255'],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', Rule::in(Currency::SUPPORTED)],
            'billing_interval' => ['required', Rule::in([SubscriptionPlan::INTERVAL_MONTHLY, SubscriptionPlan::INTERVAL_YEARLY])],
            'trial_days' => ['required', 'integer', 'min:0'],
            'seat_limit' => ['nullable', 'integer', 'min:1'],
            'module_keys' => ['nullable', 'array'],
            'module_keys.*' => ['string', Rule::in(array_keys($this->moduleOptions()))],
        ]);

        $data['module_keys'] = array_values(array_filter((array) ($data['module_keys'] ?? []), 'is_string'));
        $data['seat_limit'] = isset($data['seat_limit']) && $data['seat_limit'] !== '' ? (int) $data['seat_limit'] : null;

        return $data;
    }

    /**
     * Installed, trusted modules that a plan may license, keyed by module key.
     *
     * @return array<string, string>
     */
    private function moduleOptions(): array
    {
        return RegisteredModule::query()
            ->where('is_installed', true)
            ->orderBy('name')
            ->pluck('name', 'module_key')
            ->all();
    }

    private function authorizeView(): void
    {
        abort_unless(
            self::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE)
                || self::principalCan(PermissionKey::PLATFORM_SUBSCRIPTION_VIEW),
            403,
        );
    }

    private function authorizeManage(): void
    {
        abort_unless(self::principalCan(PermissionKey::PLATFORM_PLAN_MANAGE), 403);
    }
}
