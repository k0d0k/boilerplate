<?php

namespace App\Http\Requests\Clinic;

use App\Platform\Access\Contracts\ManagesTenantContext;
use App\Platform\Branches\Contracts\ManagesBranchContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Patients\Filament\Resources\PatientResource;
use Modules\Patients\Models\Patient;

final class SavePatientRequest extends FormRequest
{
    public function authorize(ManagesTenantContext $tenants): bool
    {
        return PatientResource::canCreate()
            && $tenants->matchesCurrent($this->user(), $this->string('tenant_id')->toString());
    }

    public function rules(ManagesBranchContext $branches): array
    {
        $branchIds = array_column($branches->eligibleOptions($this->user()), 'id');

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'sex' => ['nullable', Rule::in(Patient::SEXES)],
            'gender' => ['nullable', 'string', 'max:64'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'region' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:2'],
            'registered_branch_id' => ['required', 'ulid', Rule::in($branchIds)],
            'status' => ['required', Rule::in(Patient::STATUSES)],
        ];
    }
}
