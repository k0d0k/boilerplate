<?php

namespace App\Http\Controllers\Clinic;

use App\Http\Controllers\Controller;
use App\Models\TenantMembership;
use App\Models\User;
use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Tenancy\Contracts\ResolvesTenantDatabase;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Commerce\Support\Currency;
use Modules\Consultations\Exceptions\ConsultationException;
use Modules\Consultations\Filament\Resources\ConsultationResource;
use Modules\Consultations\Models\Consultation;
use Modules\Consultations\Services\ConsultationManager;
use Modules\Consultations\Services\ConsultationTariffService;
use Modules\Patients\Models\Patient;

/**
 * Workstream 2 — the TailAdmin consultation book for human experts.
 *
 * This replaces the "open consultation → Filament" escape hatch with a
 * first-class TailAdmin surface: booking, the consultation dossier (identity,
 * schedule, reason, status history and the private transcript) and the explicit
 * lifecycle (start / complete / cancel / no-show).
 *
 * Every action is authorised ON THE SERVER, never by the client:
 *
 *  - the Clinic panel middleware has already bound the VERIFIED tenant database
 *    and proven a verified tenant context, so a consultation only ever resolves
 *    inside the caller's own tenant database (a foreign id is a 404);
 *  - the consultation's branch must be one the identity may operate in, so a
 *    valid URL can never open another branch's session (a 403);
 *  - reading requires the module gate plus `consultation.manage` (via
 *    {@see ConsultationResource}), and every status change goes through
 *    {@see ConsultationManager}, the single authority that validates the
 *    transition, stamps the timestamps and writes the status history + audit.
 *
 * No consultation is ever deleted: the lifecycle is expressed through status.
 */
final class ConsultationController extends Controller
{
    public function create(Request $request, ManagesTenantContext $tenants, ManagesBranchContext $branches): View
    {
        abort_unless(ConsultationResource::canCreate(), 403);

        $user = $request->user();

        return view('clinic.tailadmin.consultation-create', [
            'branchId' => $branches->currentBranchId($user),
            'branchOptions' => $branches->eligibleOptions($user),
            'patients' => Patient::query()->active()
                ->orderBy('full_name')->orderBy('id')->limit(200)
                ->get(['id', 'full_name', 'patient_number']),
            'staffOptions' => $this->staffOptions($tenants->currentTenantId($user)),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    public function store(Request $request, ManagesBranchContext $branches, ConsultationManager $manager): RedirectResponse
    {
        abort_unless(ConsultationResource::canCreate(), 403);

        $branchIds = self::branchIds($branches, $request->user());

        $data = $request->validate([
            'patient_id' => ['required', 'ulid'],
            'branch_id' => ['required', 'ulid', Rule::in($branchIds)],
            // `staff_user_id` is a CENTRAL user id (stored as a string on the
            // tenant-local row), never a tenant ULID.
            'staff_user_id' => ['nullable', 'integer'],
            'channel' => ['required', Rule::in(Consultation::CHANNELS)],
            'type' => ['required', Rule::in(Consultation::TYPES)],
            'scheduled_at' => ['nullable', 'date_format:Y-m-d\TH:i'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $consultation = $manager->create($data, $request->user());
        } catch (ConsultationException $exception) {
            throw ValidationException::withMessages(['patient_id' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.consultations.show', $consultation)
            ->with('success', __('lba.consultations.created'));
    }

    /**
     * The consultation dossier: identity, assignment, schedule, summary, the
     * status history and the private transcript, plus the legal transitions.
     */
    public function show(Request $request, string $consultation, ManagesBranchContext $branches): View
    {
        $record = $this->resolve($request, $consultation, $branches);
        abort_unless(ConsultationResource::canView($record), 403);

        $histories = $record->statusHistories()->get();

        // Actor ids are central user ids stored on the tenant-local rows;
        // resolve their display names from the central users table.
        $actorNames = User::query()
            ->whereIn('id', $histories->pluck('changed_by')->filter()->unique()->all())
            ->pluck('name', 'id');

        $staffName = $record->staff_user_id !== null
            ? User::query()->whereKey($record->staff_user_id)->value('name')
            : null;

        $tariffService = app(ConsultationTariffService::class);
        $tariff = $tariffService->forConsultation($record);

        return view('clinic.tailadmin.consultation-show', [
            'consultation' => $record->load(['patient', 'branch', 'appointment']),
            'messages' => $record->messages()->get(),
            'histories' => $histories,
            'actorNames' => $actorNames,
            'staffName' => $staffName,
            'tariffLabel' => $tariff === null ? null : Currency::format($tariff->amount, $tariff->currency),
            'cancellableBeforeStart' => $tariffService->cancellableBeforeStart(),
            'refundable' => $tariffService->refundable(),
            'canManage' => ConsultationResource::canEdit($record),
            'allowedTransitions' => $record->allowedTransitions(),
            'links' => WorkspaceController::navigation(),
        ]);
    }

    /**
     * Move the consultation through its validated lifecycle. The transition is
     * re-validated by {@see ConsultationManager}; an illegal move surfaces as a
     * form error, never a 500 and never a silent success.
     */
    public function transition(
        Request $request,
        string $consultation,
        ManagesBranchContext $branches,
        ConsultationManager $manager,
    ): RedirectResponse {
        $record = $this->resolve($request, $consultation, $branches);
        abort_unless(ConsultationResource::canEdit($record), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(Consultation::STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $manager->transition($record, $data['status'], $request->user(), $data['note'] ?? null);
        } catch (ConsultationException $exception) {
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return to_route('clinic.tailadmin.consultations.show', $record)
            ->with('success', __('lba.consultations.status_changed'));
    }

    /**
     * Resolve one consultation INSIDE the verified tenant database and prove the
     * identity may operate in its branch. A missing id (including one that
     * belongs to another tenant) is a 404; a foreign branch is a 403.
     */
    private function resolve(Request $request, string $consultation, ManagesBranchContext $branches): Consultation
    {
        $record = Consultation::query()->whereKey($consultation)->firstOrFail();

        $branchIds = self::branchIds($branches, $request->user());

        abort_unless(in_array((string) $record->branch_id, $branchIds, true), 403);

        return $record;
    }

    /**
     * @return list<string>
     */
    private static function branchIds(ManagesBranchContext $branches, ?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return array_map(static fn ($option): string => (string) $option->id, $branches->eligibleOptions($user));
    }

    /**
     * Active staff members of the verified tenant, for the practitioner picker.
     *
     * @return array<string, string>
     */
    private function staffOptions(?string $tenantId): array
    {
        if ($tenantId === null || app(ResolvesTenantDatabase::class)->current() === null) {
            return [];
        }

        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantMembership::STATUS_ACTIVE)
            ->with('user')
            ->get()
            ->mapWithKeys(fn (TenantMembership $membership): array => [
                (string) $membership->user_id => $membership->user?->name ?? (string) $membership->user_id,
            ])
            ->all();
    }
}
