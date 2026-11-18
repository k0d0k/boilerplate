<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Principal\SaveTenantRequest;
use App\Models\Brand;
use App\Models\Tenant;
use App\Platform\Access\SupportTenantSession;
use App\Platform\Onboarding\ClinicSetupResult;
use App\Platform\Onboarding\ClinicSetupService;
use App\Platform\Subscriptions\Models\TenantSubscription;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use App\Platform\Tenancy\Manual\ManualTenantDatabaseConfigurator;
use App\Platform\Tenancy\Models\TenantDatabase;
use App\Platform\Tenancy\Models\TenantDatabaseCredential;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

final class TenantController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', Rule::in(SaveTenantRequest::STATUSES)],
        ]);

        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';

        $tenants = Tenant::query()->with('database')->withCount('brands')
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('slug', "%{$search}%"),
            ))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->orderBy('name')->orderBy('id')->paginate(15)->appends($filters);

        return view('principal.tenants.index', compact('tenants', 'search', 'status'));
    }

    public function create(): View
    {
        return $this->form(new Tenant(['status' => Tenant::STATUS_ACTIVE]));
    }

    /**
     * Create a partner and, in the same transaction, its Brand link and — when
     * the operator chose "Database Manual" — the recorded database target and
     * credential (status Menunggu). Nothing is created, dropped, migrated or
     * even contacted here: the database is only PROVEN later by "Uji Koneksi"
     * and only MIGRATED by "Setup Klinik".
     */
    public function store(SaveTenantRequest $request, ManualTenantDatabaseConfigurator $configurator): RedirectResponse
    {
        $data = $request->validated();

        $tenant = DB::transaction(function () use ($request, $data, $configurator): Tenant {
            $tenant = Tenant::query()->create(Arr::only($data, ['name', 'slug', 'status']));

            $this->syncBrand($tenant, $data['brand_id'] ?? null, (bool) ($data['is_primary_brand'] ?? false));

            if ($request->usesManualDatabase()) {
                $configurator->configure(
                    $tenant,
                    (string) $data['db_name'],
                    $data['db_username'] ?? null,
                    $data['db_password'] ?? null,
                );
            }

            return $tenant;
        }, 3);

        return to_route('principal.tailadmin.tenants.edit', $tenant)
            ->with('success', __('principal.tenants.saved'));
    }

    public function edit(Tenant $tenant): View
    {
        return $this->form($tenant);
    }

    /**
     * Update the partner, its Brand link and — when a MANUAL target is submitted
     * — its database configuration. The mismatch guard in
     * {@see ManualTenantDatabaseConfigurator} refuses to re-point a partner whose
     * database is already provisioned or migrated, so a live schema is never
     * silently abandoned.
     */
    public function update(
        SaveTenantRequest $request,
        Tenant $tenant,
        ManualTenantDatabaseConfigurator $configurator,
    ): RedirectResponse {
        $data = $request->validated();

        DB::transaction(function () use ($request, $tenant, $data, $configurator): void {
            $tenant->update(Arr::only($data, ['name', 'slug', 'status']));

            $this->syncBrand($tenant, $data['brand_id'] ?? null, (bool) ($data['is_primary_brand'] ?? false));

            if ($request->usesManualDatabase()) {
                $configurator->configure(
                    $tenant,
                    (string) $data['db_name'],
                    $data['db_username'] ?? null,
                    $data['db_password'] ?? null,
                );
            }
        }, 3);

        return to_route('principal.tailadmin.tenants.index')
            ->with('success', __('principal.tenants.saved'));
    }

    public function setup(Request $request, Tenant $tenant, ClinicSetupService $setup): View
    {
        $admin = $request->validate([
            'admin_email' => ['nullable', 'email', 'max:255'],
            'admin_name' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $setup->setup(
            $tenant,
            $admin['admin_email'] ?? '',
            $admin['admin_name'] ?? '',
            ClinicSetupService::DEFAULT_MODULES,
            $request->user(),
        );

        return $this->form($tenant, $result);
    }

    /**
     * Provision (or safely retry) ONLY the isolated tenant database.
     *
     * This is the server-side action behind the Partner detail screen's
     * "Set Up Database" / "Retry" buttons. It delegates to
     * {@see ClinicSetupService::provisionDatabase()}, which shares the SAME
     * per-tenant lock as the full setup so the two can never interleave, never
     * re-creates or drops a database and always converges the missing step.
     */
    public function provision(Request $request, Tenant $tenant, ClinicSetupService $setup): View
    {
        $result = $setup->provisionDatabase($tenant, $request->user());

        return $this->form($tenant, null, $result);
    }

    public function open(Request $request, Tenant $tenant, SupportTenantSession $support): RedirectResponse
    {
        abort_unless($support->open($request->user(), (string) $tenant->getKey()), 403);

        return to_route('filament.principal.pages.tenant-support');
    }

    /**
     * Remove a partner (v3.3). Gated to the Principal Super Admin by
     * {@see TenantPolicy::delete()} through the route.
     *
     * Removal is a SOFT delete: the central row is flagged removed, so the
     * partner's access stops at once (every membership resolves through an
     * ACTIVE tenant) while an account that also belongs to another partner keeps
     * working there. Its central child rows — and above all its
     * `tenant_databases` registry row — survive, which keeps the physical
     * database name CLAIMED so a retained clinical schema can never be re-opened
     * by a newly onboarded partner.
     *
     * This action NEVER runs `DROP DATABASE` and never empties a cPanel schema:
     * the clinical data is left exactly where it is. Reusing the name and slug
     * therefore has to go through the operator's staging reset procedure, which
     * purges the retained row deliberately.
     *
     * A partner still holding a LIVE subscription is refused — the operator must
     * cancel or end it first — so a running commercial obligation is never
     * discarded silently. The reason names the dependency and the step that
     * clears it.
     */
    public function destroy(Tenant $tenant): RedirectResponse
    {
        $live = $tenant->subscriptions()
            ->whereNull('cancelled_at')
            ->whereNull('ended_at')
            ->whereIn('status', [TenantSubscription::STATUS_ACTIVE, TenantSubscription::STATUS_TRIALING])
            ->count();

        if ($live > 0) {
            return to_route('principal.tailadmin.tenants.index')
                ->withErrors(['delete' => __('principal.delete.tenant_blocked_subscription', [
                    'name' => $tenant->name,
                    'count' => $live,
                ])]);
        }

        $name = $tenant->name;
        $tenant->delete();

        return to_route('principal.tailadmin.tenants.index')
            ->with('success', __('principal.delete.tenant_deleted', ['name' => $name]));
    }

    /**
     * Link a Brand to the partner through the EXISTING Brand–Partner relation,
     * optionally marking it primary. A blank selection leaves the current links
     * untouched — detaching is a separate, deliberate action on the relations
     * screen.
     */
    private function syncBrand(Tenant $tenant, ?string $brandId, bool $primary): void
    {
        $brandId = trim((string) $brandId);

        if ($brandId === '') {
            return;
        }

        $tenant->brands()->syncWithoutDetaching([$brandId]);

        if (! $primary) {
            return;
        }

        $tenant->brands()->newPivotStatement()->where('tenant_id', $tenant->getKey())
            ->where('is_primary', true)->update(['is_primary' => false]);
        $tenant->brands()->updateExistingPivot($brandId, ['is_primary' => true]);
    }

    private function form(
        Tenant $tenant,
        ?ClinicSetupResult $result = null,
        ?ClinicSetupResult $provisionResult = null,
    ): View {
        $brands = Brand::query()->orderBy('name')->pluck('name', 'id')->all();

        $primaryBrandId = '';
        $brandIsPrimary = false;

        if ($tenant->exists) {
            $primaryBrand = $tenant->brands()->orderByDesc('tenant_brands.is_primary')->first();

            if ($primaryBrand !== null) {
                $primaryBrandId = (string) $primaryBrand->getKey();
                $brandIsPrimary = (bool) $primaryBrand->pivot->is_primary;
            }
        }

        $databaseRecord = $tenant->exists
            ? TenantDatabase::query()->where('tenant_id', $tenant->getKey())->first()
            : null;

        $credential = $tenant->exists
            ? TenantDatabaseCredential::query()->where('tenant_id', $tenant->getKey())->first()
            : null;

        return view('principal.tenants.form', [
            'tenant' => $tenant,
            'setupResult' => $result,
            'provisionResult' => $provisionResult,
            'database' => $tenant->exists ? $this->databaseState($tenant) : null,
            'brands' => $brands,
            'primaryBrandId' => $primaryBrandId,
            'brandIsPrimary' => $brandIsPrimary,
            'configuredDatabaseName' => (string) ($databaseRecord?->configured_database_name ?? ''),
            'credentialUsername' => $credential !== null ? trim((string) $credential->username) : '',
            'passwordConfigured' => $credential !== null
                && is_string($credential->password)
                && $credential->password !== '',
        ]);
    }

    /**
     * Project the central registry row into the four states the Partner detail
     * screen renders: Menunggu (no attempt), Diproses (provisioned, not yet
     * migrated), Siap (migrated) or Gagal (failed). No credential and no raw
     * adapter error ever leaves this method — a failure carries only the safe
     * reason key the view localises.
     *
     * @return array{state: string, retry: bool, reason: string}
     */
    private function databaseState(Tenant $tenant): array
    {
        $record = TenantDatabase::query()->where('tenant_id', $tenant->getKey())->first();

        if (! $record instanceof TenantDatabase) {
            return ['state' => 'pending', 'retry' => false, 'reason' => ''];
        }

        $state = match ($record->status) {
            TenantDatabase::STATUS_MIGRATED => 'ready',
            TenantDatabase::STATUS_FAILED => 'failed',
            TenantDatabase::STATUS_PROVISIONED => 'processing',
            default => 'pending',
        };

        return [
            'state' => $state,
            'retry' => $state === 'failed',
            'reason' => $state === 'failed' ? 'database_error' : '',
        ];
    }
}
