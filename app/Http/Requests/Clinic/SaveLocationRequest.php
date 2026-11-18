<?php

namespace App\Http\Requests\Clinic;

use App\Platform\Branches\Contracts\ManagesBranchContext;
use App\Platform\Branches\Models\TenantBranch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * TailAdmin examination-location (branch) create / update (Phase 11).
 *
 * A location is a tenant-owned row, so every rule is validated against the
 * VERIFIED tenant database (the `TenantBranch` model resolves that connection
 * and fails closed without it). Only an identity that may administer branches
 * in the current tenant is authorized; the acting tenant is never taken from
 * the request body.
 *
 * One screen manages all three documented location kinds — a permanent branch,
 * a temporary site and a scheduled event — and a temporary/event site must
 * declare the operating window it is bookable inside.
 */
final class SaveLocationRequest extends FormRequest
{
    public function authorize(ManagesBranchContext $branches): bool
    {
        $user = $this->user();

        return $user !== null && $branches->canManageBranches($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // The route parameter is the branch id being updated (a string), not a
        // bound model — implicit binding cannot run before the tenant database
        // is bound, so the id is taken verbatim and ignored on the unique check.
        $ignore = $this->route('location');

        $bounded = Rule::requiredIf(fn (): bool => in_array(
            $this->input('location_type'),
            [TenantBranch::LOCATION_TEMPORARY, TenantBranch::LOCATION_EVENT],
            true,
        ));

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required', 'string', 'max:64',
                Rule::unique(TenantBranch::class, 'code')->ignore(is_string($ignore) ? $ignore : null),
            ],
            'status' => ['required', Rule::in(TenantBranch::STATUSES)],
            'location_type' => ['required', Rule::in(TenantBranch::LOCATION_TYPES)],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'slot_minutes' => ['nullable', 'integer', 'min:5', 'max:240'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'opens_at' => ['nullable', 'date_format:H:i'],
            'closes_at' => ['nullable', 'date_format:H:i'],
            'operates_from' => [$bounded, 'nullable', 'date'],
            'operates_until' => [$bounded, 'nullable', 'date', 'after:operates_from'],
        ];
    }
}
