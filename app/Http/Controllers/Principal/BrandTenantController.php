<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Tenant;
use App\Models\User;
use App\Platform\Access\Contracts\AuthorizesPrincipal;
use App\Platform\Access\PermissionKey;
use App\Platform\Access\SupportTenantSession;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BrandTenantController extends Controller
{
    public function index(Request $request, Brand $brand, AuthorizesPrincipal $principal): View
    {
        return view('principal.brands.tenants', [
            'brand' => $brand,
            'canOpen' => $principal->can($request->user(), PermissionKey::PLATFORM_TENANT_MANAGE),
            'tenants' => $brand->tenants()->orderBy('name')->orderBy('tenants.id')->paginate(15),
            'availableTenants' => Tenant::query()
                ->whereDoesntHave('brands', fn ($query) => $query->whereKey($brand->getKey()))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function attach(Request $request, Brand $brand): RedirectResponse
    {
        $data = $request->validate([
            'tenant_id' => ['required', 'ulid', Rule::exists(Tenant::class, 'id')],
            'is_primary' => ['sometimes', 'boolean'],
        ]);

        $brand->getConnection()->transaction(function () use ($brand, $data): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($data['tenant_id']);
            $tenant->brands()->syncWithoutDetaching([$brand->getKey()]);
            if ($data['is_primary'] ?? false) {
                $this->makePrimary($tenant, $brand);
            }
        }, 3);

        return to_route('principal.tailadmin.brands.tenants.index', $brand);
    }

    public function primary(Brand $brand, Tenant $tenant): RedirectResponse
    {
        $brand->getConnection()->transaction(function () use ($brand, $tenant): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $this->ensureLinked($brand, $tenant);
            $this->makePrimary($tenant, $brand);
        }, 3);

        return to_route('principal.tailadmin.brands.tenants.index', $brand)
            ->with('success', __('lba.brands.primary_set'));
    }

    public function detach(Brand $brand, Tenant $tenant): RedirectResponse
    {
        $brand->getConnection()->transaction(function () use ($brand, $tenant): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->getKey());
            $this->ensureLinked($brand, $tenant);
            $tenant->brands()->detach($brand->getKey());
        }, 3);

        return to_route('principal.tailadmin.brands.tenants.index', $brand);
    }

    public function open(Request $request, Brand $brand, Tenant $tenant, SupportTenantSession $support): RedirectResponse
    {
        $this->ensureLinked($brand, $tenant);
        $actor = $request->user();
        abort_unless($actor instanceof User && $support->open($actor, (string) $tenant->getKey()), 403);

        return to_route('filament.principal.pages.tenant-support');
    }

    private function ensureLinked(Brand $brand, Tenant $tenant): void
    {
        abort_unless($tenant->brands()->whereKey($brand->getKey())->exists(), 404);
    }

    private function makePrimary(Tenant $tenant, Brand $brand): void
    {
        $tenant->brands()->newPivotStatement()->where('tenant_id', $tenant->getKey())
            ->where('is_primary', true)->update(['is_primary' => false]);
        $tenant->brands()->updateExistingPivot($brand->getKey(), ['is_primary' => true]);
    }
}
