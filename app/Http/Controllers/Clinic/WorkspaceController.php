<?php

namespace App\Http\Controllers\Clinic;

use App\Filament\Clinic\Resources\TenantBranchResource;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Platform\Access\AccessContext;
use App\Platform\Access\Contracts\ChecksPermissions;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\PermissionKey;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Appointments\Filament\Resources\AppointmentResource;
use Modules\Commerce\Filament\Pages\PosTerminal;
use Modules\Commerce\Filament\Resources\OrderResource;
use Modules\Commerce\Filament\Resources\PaymentTransactionResource;
use Modules\Commerce\Filament\Resources\ProductResource;
use Modules\Commerce\Filament\Resources\StockLevelResource;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\Consultations\Models\Consultation;
use Modules\LBA\Filament\Pages\AiWalletOverview;
use Modules\LBA\Filament\Pages\BrandReportSettings;
use Modules\LBA\Filament\Pages\ReportsOverview;
use Modules\LBA\Filament\Pages\ReviewerInbox;
use Modules\LBA\Filament\Resources\ExaminationResource;
use Modules\LBA\Models\Examination;
use Modules\LBA\Models\ReportVersion;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Practitioners\Filament\Resources\PractitionerResource;

final class WorkspaceController extends Controller
{
    public const REGISTRIES = [
        'patients' => [PatientResource::class, 'full_name'],
        'appointments' => [AppointmentResource::class, 'starts_at'],
        'examinations' => [ExaminationResource::class, 'captured_at'],
        'consultations' => [ConsultationResource::class, 'scheduled_at'],
        'practitioners' => [PractitionerResource::class, 'display_name'],
    ];

    public function dashboard(Request $request, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        $user = $request->user();

        return view('clinic.tailadmin.dashboard', [
            'tenant' => $tenants->currentTenant($user),
            'tenantOptions' => $tenants->eligibleOptions($user),
            'branch' => $branches->currentBranch($user),
            'branchOptions' => $branches->eligibleOptions($user),
            'branchSetupUrl' => $branches->canManageBranches($user) ? TenantBranchResource::getUrl('create', panel: 'clinic') : null,
            'links' => self::navigation(),
        ]);
    }

    public function index(Request $request, string $registry): View
    {
        [$resource, $order] = self::REGISTRIES[$registry];
        abort_unless($resource::canViewAny(), 403);

        $rules = ['search' => ['nullable', 'string', 'max:255']];

        // The Pemeriksaan LBA registry carries the Manual / AI / Hybrid mode
        // queue as a validated query filter (the TailAdmin counterpart of the
        // Filament List{Ai,Hybrid,Manual}Examinations pages).
        if ($registry === 'examinations') {
            $rules['mode'] = ['nullable', Rule::in(Examination::MODES)];
        }

        $filters = $request->validate($rules);
        $search = $filters['search'] ?? '';
        $mode = $filters['mode'] ?? '';
        $query = $resource::getEloquentQuery();

        if ($registry === 'patients') {
            $query->when($search !== '', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereLike('full_name', "%{$search}%")->orWhereLike('patient_number', "%{$search}%"),
            ))->orderBy($order);
        } elseif ($registry === 'practitioners') {
            $query->when($search !== '', fn (Builder $query) => $query->whereLike('display_name', "%{$search}%"))->orderBy($order);
        } else {
            $query->with(['patient', 'branch'])
                ->when($search !== '', fn (Builder $query) => $query->whereHas(
                    'patient', fn (Builder $patient) => $patient->whereLike('full_name', "%{$search}%"),
                ))->orderByDesc($order);
        }

        if ($registry === 'examinations' && $mode !== '') {
            $query->where('mode', $mode);
        }

        $records = $query->orderBy('id')->paginate(15)->appends($filters);
        $createUrl = $resource::canCreate() ? match ($registry) {
            'patients' => route('clinic.tailadmin.patients.create'),
            'practitioners' => route('clinic.tailadmin.practitioners.create'),
            'consultations' => route('clinic.tailadmin.consultations.create'),
            'appointments', 'examinations' => route('clinic.tailadmin.intake.create', $registry),
            default => $resource::getUrl('create', panel: 'clinic'),
        } : null;

        $label = $resource::getNavigationLabel();

        return view('clinic.tailadmin.index', compact('registry', 'resource', 'label', 'search', 'mode', 'records', 'createUrl') + ['links' => self::navigation()]);
    }

    public function reports(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(ReportsOverview::canAccess(), 403);

        $branchIds = array_column($branches->eligibleOptions($request->user()), 'id');
        $reports = ReportVersion::query()
            ->with(['assessment.examination.patient', 'assessment.examination.branch'])
            ->whereHas('assessment.examination', fn (Builder $query) => $query->whereIn('branch_id', $branchIds))
            ->orderByDesc('generated_at')->orderByDesc('id')->paginate(15);

        return view('clinic.tailadmin.reports', ['reports' => $reports, 'links' => self::navigation()]);
    }

    /**
     * SINA AI — the SINA-channel consultation sessions the acting identity may
     * manage (consultation.manage under the verified tenant/branch context).
     */
    public function sina(Request $request, ManagesBranchContext $branches): View
    {
        abort_unless(ConsultationResource::canViewAny(), 403);

        $ids = self::branchIds($branches, $request->user());

        $sessions = Consultation::query()
            ->with(['patient', 'branch'])
            ->where('channel', Consultation::CHANNEL_SINA_CHAT)
            ->when(
                $ids !== [],
                fn (Builder $query) => $query->whereIn('branch_id', $ids),
                fn (Builder $query) => $query->whereRaw('1 = 0'),
            )
            ->orderByDesc('scheduled_at')->orderByDesc('id')->paginate(15);

        return view('clinic.tailadmin.sina', [
            'sessions' => $sessions,
            'links' => self::navigation(),
        ]);
    }

    /**
     * Pengaturan Klinik — the tenant-level settings hub. It requires the
     * tenant.profile.manage capability and links to the first-class TailAdmin
     * operational surfaces (examination locations and the branch team roster)
     * plus the report-branding page; no tenant setting is mutated here.
     */
    public function settings(Request $request, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        $user = $request->user();
        abort_unless(self::allows(PermissionKey::TENANT_PROFILE_MANAGE), 403);

        $canManage = $branches->canManageBranches($user);

        return view('clinic.tailadmin.settings', [
            'tenant' => $tenants->currentTenant($user),
            'locationsUrl' => $canManage ? route('clinic.tailadmin.locations') : null,
            'teamUrl' => $canManage ? route('clinic.tailadmin.team') : null,
            'brandReportUrl' => BrandReportSettings::canAccess() ? BrandReportSettings::getUrl(panel: 'clinic') : null,
            'links' => self::navigation(),
        ]);
    }

    /**
     * Ids of the branches the identity may operate in for the verified tenant.
     *
     * @return list<string>
     */
    private static function branchIds(ManagesBranchContext $branches, User $user): array
    {
        return array_map(static fn ($option): string => $option->id, $branches->eligibleOptions($user));
    }

    public function switchTenant(Request $request, ManagesTenantContext $tenants): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'ulid']]);
        abort_unless($tenants->select($request->user(), $data['tenant_id']), 403);

        return to_route('clinic.tailadmin.dashboard');
    }

    public function switchBranch(Request $request, ManagesTenantContext $tenants, ManagesBranchContext $branches): RedirectResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'ulid'], 'branch_id' => ['required', 'ulid']]);
        $user = $request->user();
        abort_unless($tenants->matchesCurrent($user, $data['tenant_id']), 403);
        abort_unless($branches->select($user, $data['branch_id']), 403);

        return to_route('clinic.tailadmin.dashboard');
    }

    public static function navigation(): array
    {
        $user = auth()->user();
        $links = [];

        foreach (self::REGISTRIES as $registry => [$resource]) {
            if ($resource::canViewAny()) {
                $links[$registry] = $resource::getNavigationLabel();
            }
        }

        if (ReviewerInbox::canAccess()) {
            $links['reviews'] = __('lba.review.nav');
        }

        if (ReportsOverview::canAccess()) {
            $links['reports'] = ReportsOverview::getNavigationLabel();
        }

        // Lokasi Pemeriksaan and Tim & Cabang are branch-scoped operational
        // surfaces: they only appear once the identity has a verified branch
        // context (the branches themselves are filtered to the eligible set).
        if ($user instanceof User && app(ManagesBranchContext::class)->hasEligibleBranch($user)) {
            $links['locations'] = __('lba.locations.nav');
            $links['team'] = __('lba.team.nav');
        }

        // Acara & Pemesanan reuses the appointment book (booking.read/manage).
        if (AppointmentResource::canViewAny()) {
            $links['events'] = __('lba.events.nav');
        }

        // SINA AI surfaces the SINA-channel sessions the identity may manage.
        if (ConsultationResource::canViewAny()) {
            $links['sina'] = __('lba.sina.nav');
        }

        if (PosTerminal::canAccess()) {
            $links['pos'] = PosTerminal::getNavigationLabel();
        }

        // WS3 catalog, branch stock and the order book — first-class TailAdmin
        // surfaces, each mirroring its Filament resource's entitlement + RBAC.
        if (ProductResource::canViewAny()) {
            $links['products'] = ProductResource::getNavigationLabel();
        }

        if (StockLevelResource::canViewAny()) {
            $links['inventory'] = StockLevelResource::getNavigationLabel();
        }

        if (OrderResource::canViewAny()) {
            $links['orders'] = OrderResource::getNavigationLabel();
        }

        if (PaymentTransactionResource::canViewAny()) {
            $links['transactions'] = PaymentTransactionResource::getNavigationLabel();
        }

        if (AiWalletOverview::canAccess()) {
            $links['wallet'] = AiWalletOverview::getNavigationLabel();
        }

        // Pengaturan Klinik mirrors the tenant.profile.manage capability.
        if (self::allows(PermissionKey::TENANT_PROFILE_MANAGE)) {
            $links['settings'] = __('lba.nav.clinic.settings');
        }

        return $links;
    }

    /**
     * Resolve a TailAdmin navigation key to its destination URL. Registry keys
     * point at the TailAdmin list, `reviews` at the reviewer inbox, `reports` at
     * the reports archive, the WS3 keys at the point of sale, the payment
     * history and the AI credit wallet, and the module surfaces at the location,
     * event, SINA, team and clinic-settings pages.
     */
    public static function navUrl(string $key): string
    {
        return match ($key) {
            'locations' => route('clinic.tailadmin.locations'),
            'events' => route('clinic.tailadmin.events'),
            'sina' => route('clinic.tailadmin.sina'),
            'team' => route('clinic.tailadmin.team'),
            'settings' => route('clinic.tailadmin.settings'),
            'reviews' => route('clinic.tailadmin.reviews.index'),
            'reports' => route('clinic.tailadmin.reports'),
            'pos' => route('clinic.tailadmin.pos'),
            'products' => route('clinic.tailadmin.products'),
            'inventory' => route('clinic.tailadmin.inventory'),
            'orders' => route('clinic.tailadmin.orders'),
            'transactions' => route('clinic.tailadmin.transactions'),
            'wallet' => route('clinic.tailadmin.wallet'),
            default => route('clinic.tailadmin.index', $key),
        };
    }

    /**
     * Re-derive a canonical permission for the verified tenant/branch context.
     *
     * The controller counterpart of the Clinic module-resources authorization
     * trait: the context is resolved server-side and never trusted from the
     * request.
     */
    private static function allows(string $permission): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return false;
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return false;
        }

        $branchId = app(ManagesBranchContext::class)->currentBranchId($user);

        return app(ChecksPermissions::class)->allows($user, $permission, AccessContext::forTenant($tenantId, $branchId));
    }
}
