<?php

namespace App\Http\Controllers\Principal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Principal\SaveBrandReportIdentityRequest;
use App\Http\Requests\Principal\SaveBrandRequest;
use App\Models\Brand;
use App\Platform\Crud\Generated\BrandCrud;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class BrandController extends Controller
{
    public function index(Request $request): View
    {
        $filterEnabled = in_array('status', BrandCrud::OPTIONS['filters'], true);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => [$filterEnabled ? 'nullable' : 'exclude', Rule::in([Brand::STATUS_ACTIVE, Brand::STATUS_SUSPENDED])],
        ]);

        $search = $filters['search'] ?? '';
        $status = $filters['status'] ?? '';
        $brands = Brand::query()
            ->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('slug', "%{$search}%"),
            ))
            ->when($status !== '', fn (Builder $query) => $query->where('status', $status))
            ->orderBy('name')->orderBy('id')->paginate(15)->appends($filters);

        return view('principal.brands.index', compact('brands', 'search', 'status', 'filterEnabled'));
    }

    public function create(): View
    {
        return view('principal.brands.form', ['brand' => new Brand(['status' => Brand::STATUS_ACTIVE])]);
    }

    public function store(SaveBrandRequest $request): RedirectResponse
    {
        Brand::query()->create($request->validated());

        return $this->saved();
    }

    public function edit(Brand $brand): View
    {
        return view('principal.brands.form', compact('brand'));
    }

    public function update(SaveBrandRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->safe()->only(['name', 'slug']));

        return $this->saved();
    }

    public function status(Request $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validate([
            'status' => ['required', Rule::in([Brand::STATUS_ACTIVE, Brand::STATUS_SUSPENDED])],
        ]));

        return $this->saved();
    }

    /**
     * Remove a brand (v3.3). Gated to the Principal Super Admin by
     * {@see BrandPolicy::delete()} through the route.
     *
     * A brand is only a commercial identity, so its removal never touches a
     * partner, an account, a subscription or a clinical database. It is blocked
     * while any partner is still LINKED to the brand: the operator must first
     * detach the link (or move "Brand utama") on those partners, so nobody is
     * left pointing at a brand that no longer exists. The reason message names
     * the linked partners.
     */
    public function destroy(Brand $brand): RedirectResponse
    {
        $linked = $brand->tenants()->orderBy('name')->pluck('tenants.name')->all();

        if ($linked !== []) {
            return to_route('principal.tailadmin.brands.index')
                ->withErrors(['delete' => __('principal.delete.brand_blocked', [
                    'name' => $brand->name,
                    'partners' => implode(', ', $linked),
                ])]);
        }

        $name = $brand->name;
        $brand->delete();

        return to_route('principal.tailadmin.brands.index')
            ->with('success', __('principal.delete.brand_deleted', ['name' => $name]));
    }

    public function reportIdentity(Brand $brand): View
    {
        return view('principal.brands.report-identity', [
            'brand' => $brand,
            'languages' => SaveBrandReportIdentityRequest::languages(),
            'textFields' => SaveBrandReportIdentityRequest::TEXT_FIELDS,
            'longTextFields' => SaveBrandReportIdentityRequest::LONG_TEXT_FIELDS,
        ]);
    }

    public function updateReportIdentity(SaveBrandReportIdentityRequest $request, Brand $brand): RedirectResponse
    {
        $brand->update($request->validated());

        return to_route('principal.tailadmin.brands.report-identity', $brand)
            ->with('success', __('lba.brand_report.saved'));
    }

    private function saved(): RedirectResponse
    {
        return to_route('principal.tailadmin.brands.index')->with('success', __('principal.brands.saved'));
    }
}
