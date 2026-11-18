<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Access\UserCategory;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Practitioners\Exceptions\PractitionerException;
use Modules\Practitioners\Filament\Resources\PractitionerResource;
use Modules\Practitioners\Models\Practitioner;
use Modules\Practitioners\Models\PractitionerCredential;
use Modules\Practitioners\Services\PractitionerRegistry;

/**
 * Tenaga Ahli — TailAdmin practitioner registry (Phase 11).
 *
 * Replaces the "open practitioner → Filament" escape hatch with a first-class
 * TailAdmin surface. The roster itself is listed by the shared registry; this
 * controller owns the operational lifecycle: register a profile, edit it,
 * verify / suspend / reactivate / archive it, authorize it for a branch and
 * manage its credentials.
 *
 * Authorization re-derives the canonical `practitioner.profile.read` /
 * `practitioner.profile.manage` / `practitioner.credential.verify` permissions
 * server-side through {@see PractitionerResource}; every write goes through
 * {@see PractitionerRegistry}, which re-checks the actor on the server (a
 * practitioner may never verify their own record — separation of duties) and
 * refuses ineligible targets. Nothing is ever auto-verified, and branch
 * authorizations are limited to the branches the identity may operate in.
 */
final class PractitionerController extends Controller
{
    public function create(Request $request, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        return view('clinic.tailadmin.practitioner-create', [
            'candidates' => $this->eligibleUserOptions(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function store(Request $request, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'display_name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:128'],
            'professional_body' => ['nullable', 'string', 'max:255'],
            'specialities' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $error = $this->guarded(fn () => $registry->register(
            $request->user(),
            (string) $data['user_id'],
            $this->profileAttributes($data),
        ));

        if ($error !== null) {
            return back()->withInput()->withErrors(['user_id' => $error]);
        }

        return to_route('clinic.tailadmin.index', 'practitioners')
            ->with('success', __('lba.practitioners.registered'));
    }

    public function show(Request $request, string $practitioner): View
    {
        abort_unless(PractitionerResource::canViewAny(), 403);

        $resolved = $this->resolvePractitioner($practitioner);

        return view('clinic.tailadmin.practitioner-show', [
            'practitioner' => $resolved,
            'credentials' => $resolved->credentials()->orderByDesc('id')->get(),
            'authorizations' => $resolved->branchAuthorizations()->with('branch')->orderByDesc('id')->get(),
            'branchOptions' => $this->branchOptions($request),
            'canManage' => PractitionerResource::canCreate(),
            'canVerify' => PractitionerResource::canVerify(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function edit(Request $request, string $practitioner): View
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        return view('clinic.tailadmin.practitioner-edit', [
            'practitioner' => $this->resolvePractitioner($practitioner),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function update(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        $data = $request->validate([
            'display_name' => ['required', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:128'],
            'professional_body' => ['nullable', 'string', 'max:255'],
            'specialities' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $error = $this->guarded(fn () => $registry->update(
            $request->user(),
            $this->resolvePractitioner($practitioner),
            $this->profileAttributes($data),
        ));

        if ($error !== null) {
            return back()->withInput()->withErrors(['practitioner' => $error]);
        }

        return to_route('clinic.tailadmin.practitioners.show', $practitioner)
            ->with('success', __('lba.practitioners.updated'));
    }

    public function verify(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canVerify(), 403);

        $data = $request->validate(['expires_at' => ['nullable', 'date_format:Y-m-d']]);

        $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;

        $error = $this->guarded(fn () => $registry->verify(
            $request->user(),
            $this->resolvePractitioner($practitioner),
            $expiresAt,
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.verified'));
    }

    public function suspend(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canVerify(), 403);

        $error = $this->guarded(fn () => $registry->suspend(
            $request->user(),
            $this->resolvePractitioner($practitioner),
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.suspended'));
    }

    public function reactivate(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canVerify(), 403);

        $error = $this->guarded(fn () => $registry->reactivate(
            $request->user(),
            $this->resolvePractitioner($practitioner),
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.reactivated'));
    }

    public function archive(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        $error = $this->guarded(fn () => $registry->archive(
            $request->user(),
            $this->resolvePractitioner($practitioner),
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.archived'));
    }

    public function authorizeBranch(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        $data = $request->validate(['branch_id' => ['required', 'ulid', Rule::in($this->accessibleBranchIds($request))]]);

        $error = $this->guarded(fn () => $registry->authorizeBranch(
            $request->user(),
            $this->resolvePractitioner($practitioner),
            (string) $data['branch_id'],
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.branches.authorized'));
    }

    public function revokeBranch(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        $data = $request->validate(['branch_id' => ['required', 'ulid']]);

        $error = $this->guarded(function () use ($registry, $request, $practitioner, $data): void {
            $revoked = $registry->revokeBranch(
                $request->user(),
                $this->resolvePractitioner($practitioner),
                (string) $data['branch_id'],
            );

            if (! $revoked) {
                throw PractitionerException::branchNotAuthorized();
            }
        });

        return $this->outcome($error, $practitioner, __('lba.practitioners.branches.revoked'));
    }

    public function addCredential(Request $request, string $practitioner, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canCreate(), 403);

        $data = $request->validate([
            'credential_type' => ['required', Rule::in(PractitionerCredential::TYPES)],
            'jurisdiction' => ['nullable', 'string', 'max:128'],
            'reference_number' => ['nullable', 'string', 'max:128'],
            'issued_at' => ['nullable', 'date_format:Y-m-d'],
            'expires_at' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $error = $this->guarded(fn () => $registry->addCredential(
            $request->user(),
            $this->resolvePractitioner($practitioner),
            array_filter($data, static fn ($value): bool => $value !== null && $value !== ''),
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.credentials.added'));
    }

    public function verifyCredential(Request $request, string $practitioner, string $credential, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canVerify(), 403);

        $data = $request->validate(['expires_at' => ['nullable', 'date_format:Y-m-d']]);

        $expiresAt = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null;

        $error = $this->guarded(fn () => $registry->verifyCredential(
            $request->user(),
            $this->resolveCredential($practitioner, $credential),
            $expiresAt,
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.credentials.verified'));
    }

    public function rejectCredential(Request $request, string $practitioner, string $credential, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canVerify(), 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $error = $this->guarded(fn () => $registry->rejectCredential(
            $request->user(),
            $this->resolveCredential($practitioner, $credential),
            $data['notes'] ?? null,
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.credentials.rejected'));
    }

    public function revokeCredential(Request $request, string $practitioner, string $credential, PractitionerRegistry $registry): RedirectResponse
    {
        abort_unless(PractitionerResource::canVerify(), 403);

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $error = $this->guarded(fn () => $registry->revokeCredential(
            $request->user(),
            $this->resolveCredential($practitioner, $credential),
            $data['notes'] ?? null,
        ));

        return $this->outcome($error, $practitioner, __('lba.practitioners.credentials.revoked'));
    }

    /**
     * The professional profile fields a client may submit (user_id and every
     * verification field are server-controlled and never mass-assignable here).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileAttributes(array $data): array
    {
        $attributes = array_filter([
            'display_name' => $data['display_name'] ?? null,
            'title' => $data['title'] ?? null,
            'professional_body' => $data['professional_body'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], static fn ($value): bool => $value !== null);

        $attributes['specialities'] = $this->specialities($data['specialities'] ?? null);

        return $attributes;
    }

    /**
     * Parse the comma-separated specialities input into a clean list; a blank
     * value clears the field (an empty list), never a stray empty entry.
     *
     * @return list<string>
     */
    private function specialities(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $value)),
            static fn (string $item): bool => $item !== '',
        ));
    }

    /**
     * Run a registry operation, translating a refusal into a form error rather
     * than a 500.
     */
    private function guarded(Closure $operation): ?string
    {
        try {
            $operation();
        } catch (PractitionerException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    private function outcome(?string $error, string $practitioner, string $success): RedirectResponse
    {
        if ($error !== null) {
            return back()->withErrors(['practitioner' => $error]);
        }

        return to_route('clinic.tailadmin.practitioners.show', $practitioner)->with('success', $success);
    }

    /**
     * Resolve the target practitioner from the VERIFIED tenant database.
     *
     * Implicit route-model binding cannot be used here: `SubstituteBindings`
     * runs before the tenant connection is bound, so a `Practitioner` would
     * fail closed. The id is resolved explicitly inside the request instead — a
     * foreign or unknown id is simply not found (404), never widened.
     */
    private function resolvePractitioner(string $practitioner): Practitioner
    {
        return Practitioner::query()->whereKey($practitioner)->firstOrFail();
    }

    /**
     * Resolve a credential that BELONGS to the given practitioner, so a
     * credential of another practitioner can never be addressed.
     */
    private function resolveCredential(string $practitioner, string $credential): PractitionerCredential
    {
        return PractitionerCredential::query()
            ->where('practitioner_id', $this->resolvePractitioner($practitioner)->getKey())
            ->whereKey($credential)
            ->firstOrFail();
    }

    /**
     * Branch id => name options the identity may operate in.
     *
     * @return array<string, string>
     */
    private function branchOptions(Request $request): array
    {
        $options = [];

        foreach (app(ManagesBranchContext::class)->eligibleOptions($request->user()) as $option) {
            $options[$option->id] = $option->name;
        }

        return $options;
    }

    /**
     * Ids of the branches the identity may operate in for the verified tenant.
     *
     * @return list<string>
     */
    private function accessibleBranchIds(Request $request): array
    {
        return array_keys($this->branchOptions($request));
    }

    /**
     * Active clinical members of the verified tenant who are not yet registered
     * as practitioners — the only identities that may be added to the roster.
     *
     * @return array<string, string>
     */
    private function eligibleUserOptions(): array
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->is_active) {
            return [];
        }

        $tenantId = app(ManagesTenantContext::class)->currentTenantId($user);

        if ($tenantId === null) {
            return [];
        }

        $clinicalRoleIds = Role::query()
            ->whereIn('key', UserCategory::roleKeys(UserCategory::MEDICAL_STAFF))
            ->pluck('id')
            ->all();

        if ($clinicalRoleIds === []) {
            return [];
        }

        $candidateIds = TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->whereHas('roles', fn ($query) => $query->whereIn('roles.id', $clinicalRoleIds))
            ->pluck('user_id')
            ->unique()
            ->all();

        $registered = Practitioner::query()->pluck('user_id')->all();
        $eligible = array_values(array_diff($candidateIds, $registered));

        if ($eligible === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $eligible)
            ->where('is_active', true)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
